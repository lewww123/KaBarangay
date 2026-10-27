<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\Purok;
use App\Models\Resident;
use App\Models\SectorApplication;
use App\Services\SectorApplicationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PwdController extends Controller
{
    private function authorizePwdStaff(Request $request): void
    {
        $user = $request->user();
        if (! in_array($user->role, ['pwd', 'pwd_staff', 'admin'], true)) {
            abort(403, 'Unauthorized access to PWD Sector Portal.');
        }
    }

    public function dashboard(Request $request): Response
    {
        $this->authorizePwdStaff($request);
        $user = $request->user();

        $pwdResidentsCount = Resident::where('status', 'active')
            ->where('barangay_id', $user->barangay_id)
            ->whereHas('sectors', fn ($s) => $s->where('slug', 'pwd')->orWhere('name', 'LIKE', '%PWD%'))
            ->count();

        $pwdAnnouncements = Announcement::where('barangay_id', $user->barangay_id)
            ->where('target_audience', 'pwd');

        return Inertia::render('Pwd/Dashboard', [
            'userName' => $user->name,
            'stats' => [
                'totalResidents' => $pwdResidentsCount,
                'totalAnnouncements' => (clone $pwdAnnouncements)->count(),
                'pendingAnnouncements' => (clone $pwdAnnouncements)->where('approval_status', 'pending')->count(),
                'approvedAnnouncements' => (clone $pwdAnnouncements)->where('approval_status', 'approved')->count(),
            ],
            'recentAnnouncements' => (clone $pwdAnnouncements)->latest()->take(5)->get(),
        ]);
    }

    public function residents(Request $request): Response
    {
        $this->authorizePwdStaff($request);
        $user = $request->user();

        $residents = Resident::query()
            ->with(['purok:id,name', 'sectors:id,name,slug', 'qrCredential:id,resident_id,is_active,revoked_at'])
            ->where('barangay_id', $user->barangay_id)
            ->where('status', 'active')
            ->whereHas('sectors', fn ($s) => $s->where('slug', 'pwd')->orWhere('name', 'LIKE', '%PWD%'))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->map(fn (Resident $r) => [
                'id' => $r->id,
                'resident_number' => $r->resident_number,
                'full_name' => $r->full_name,
                'sex' => $r->sex,
                'purok' => $r->purok?->name ?? '—',
                'status' => $r->status,
                'has_qr' => (bool) ($r->qrCredential?->is_active && ! $r->qrCredential?->revoked_at),
                'sectors' => $r->sectors->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'slug' => $s->slug,
                ])->values(),
            ]);

        $puroks = Purok::where('barangay_id', $user->barangay_id)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('Pwd/Residents/Index', [
            'auth' => ['user' => ['name' => $user->name, 'staff_id' => 'PWD-001']],
            'residents' => $residents,
            'puroks' => $puroks,
        ]);
    }

    public function applications(Request $request): Response
    {
        $this->authorizePwdStaff($request);
        $user = $request->user();

        $applications = SectorApplication::query()
            ->with(['resident.purok', 'sector', 'submittedByUser', 'staffReviewedBy', 'adminDecidedBy', 'documents'])
            ->where('barangay_id', $user->barangay_id)
            ->whereHas('sector', fn ($q) => $q->where('slug', 'pwd'))
            ->latest()
            ->get()
            ->map(fn (SectorApplication $app) => $this->formatApplicationItem($app));

        return Inertia::render('Pwd/Applications/Index', [
            'auth' => ['user' => ['name' => $user->name, 'staff_id' => 'PWD-001']],
            'applications' => $applications,
        ]);
    }

    public function createApplication(Request $request): Response
    {
        $this->authorizePwdStaff($request);
        $user = $request->user();

        return Inertia::render('Pwd/Applications/Create', [
            'auth' => ['user' => ['name' => $user->name, 'staff_id' => 'PWD-001']],
        ]);
    }

    public function storeApplication(Request $request, SectorApplicationService $service): RedirectResponse
    {
        $this->authorizePwdStaff($request);
        $user = $request->user();

        $validated = $request->validate([
            'resident_id' => ['required', 'integer', 'exists:residents,id'],
            'disability_type' => ['nullable', 'string', 'max:150'],
            'assistive_devices' => ['nullable', 'string', 'max:255'],
            'documents' => ['nullable', 'array'],
            'documents.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        $details = array_filter([
            'disability_type' => $validated['disability_type'] ?? null,
            'assistive_devices' => $validated['assistive_devices'] ?? null,
        ]);

        $formattedDocs = [];
        if ($request->hasFile('documents')) {
            foreach ($request->file('documents') as $index => $file) {
                $formattedDocs[] = [
                    'file' => $file,
                    'type' => 'pwd_proof',
                    'title' => 'PWD Proof Document #'.($index + 1),
                ];
            }
        }

        $application = $service->submitStaffAssisted(
            staff: $user,
            residentId: (int) $validated['resident_id'],
            sectorSlug: 'pwd',
            details: $details,
            documents: $formattedDocs
        );

        return redirect()->route('pwd.applications.show', $application->id)
            ->with('success', 'PWD application '.$application->tracking_number.' successfully created.');
    }

    public function showApplication(Request $request, SectorApplication $application): Response
    {
        $this->authorizePwdStaff($request);
        $user = $request->user();

        if ($application->barangay_id !== $user->barangay_id || $application->sector->slug !== 'pwd') {
            abort(404);
        }

        $application->load(['resident.purok', 'sector', 'submittedByUser', 'staffReviewedBy', 'adminDecidedBy', 'documents']);

        return Inertia::render('Pwd/Applications/Show', [
            'auth' => ['user' => ['name' => $user->name, 'staff_id' => 'PWD-001']],
            'application' => $this->formatApplicationItem($application),
        ]);
    }

    public function approveApplication(
        Request $request,
        SectorApplication $application,
        SectorApplicationService $service
    ): RedirectResponse {
        $this->authorizePwdStaff($request);
        $user = $request->user();

        $validated = $request->validate([
            'checklist' => ['nullable', 'array'],
            'review_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $service->staffApprove(
            application: $application,
            staff: $user,
            checklist: $validated['checklist'] ?? [],
            reviewNotes: $validated['review_notes'] ?? null
        );

        return redirect()->route('pwd.applications.show', $application->id)
            ->with('success', 'Application approved and PWD sector membership activated.');
    }

    public function rejectApplication(
        Request $request,
        SectorApplication $application,
        SectorApplicationService $service
    ): RedirectResponse {
        $this->authorizePwdStaff($request);
        $user = $request->user();

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $service->staffReject(
            application: $application,
            staff: $user,
            rejectionReason: $validated['reason']
        );

        return redirect()->route('pwd.applications.show', $application->id)
            ->with('success', 'Application rejected and the reason recorded.');
    }

    public function requestCorrection(
        Request $request,
        SectorApplication $application,
        SectorApplicationService $service
    ): RedirectResponse {
        $this->authorizePwdStaff($request);
        $user = $request->user();

        $validated = $request->validate([
            'correction_notes' => ['required', 'string', 'max:1000'],
        ]);

        $service->requestCorrection(
            application: $application,
            staff: $user,
            correctionNotes: $validated['correction_notes']
        );

        return redirect()->route('pwd.applications.show', $application->id)
            ->with('success', 'Correction request recorded and applicant notified.');
    }

    private function formatApplicationItem(SectorApplication $app): array
    {
        return [
            'id' => $app->id,
            'tracking_number' => $app->tracking_number,
            'resident_id' => $app->resident_id,
            'resident_number' => $app->resident->resident_number ?? '—',
            'applicant_name' => $app->resident->full_name ?? '—',
            'purok' => $app->resident->purok?->name ?? '—',
            'sector' => $app->sector->slug,
            'sector_name' => $app->sector->name,
            'submission_type' => $app->submission_type,
            'submitted_by_name' => $app->submittedByUser?->name ?? ($app->submission_type === 'self_service' ? 'Resident Self-Service' : 'Staff'),
            'status' => $app->status,
            'submitted_at' => $app->created_at->format('M d, Y'),
            'reviewed_by_staff_name' => $app->staffReviewedBy?->name,
            'staff_review_notes' => $app->staff_review_notes,
            'staff_checklist_completed' => (bool) $app->staff_checklist_completed,
            'admin_decision_by_name' => $app->adminDecidedBy?->name,
            'admin_decision_notes' => $app->admin_decision_notes,
            'admin_decided_at' => $app->admin_decided_at?->format('M d, Y'),
            'documents' => $app->documents->map(fn ($doc) => [
                'id' => $doc->id,
                'title' => $doc->title,
                'type' => $doc->document_type,
                'file_name' => $doc->original_name,
                'file_size' => round($doc->file_size / 1024).' KB',
                'verification_status' => $doc->verification_status,
                'url' => route('sector-applications.documents.show', [$app->id, $doc->id]),
            ])->values()->all(),
        ];
    }
}
