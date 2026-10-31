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

class SeniorController extends Controller
{
    private function authorizeSeniorStaff(Request $request): void
    {
        if (! $request->user()->staffAssignments()
            ->where('department', 'senior')
            ->where('is_active', true)
            ->exists()) {
            abort(403, 'Unauthorized access to Senior Citizens Portal.');
        }
    }

    public function dashboard(Request $request): Response
    {
        $this->authorizeSeniorStaff($request);
        $user = $request->user();

        $barangayId = $user->barangay_id;

        return Inertia::render('Senior/Dashboard', [
            'userName' => $user->name,
            'stats' => $this->deferProp(fn () => $this->rememberAggregate(
                "senior:dashboard:stats:v2:{$barangayId}",
                30,
                function () use ($barangayId): array {
                    $announcements = Announcement::query()
                        ->where('barangay_id', $barangayId)
                        ->where('target_audience', 'senior_citizens');

                    $counts = $announcements->selectRaw("COUNT(*) as total, COUNT(CASE WHEN approval_status = 'pending' THEN 1 END) as pending, COUNT(CASE WHEN approval_status = 'approved' AND (program_status IS NULL OR program_status != 'draft') THEN 1 END) as approved")
                        ->selectRaw("COUNT(CASE WHEN program_status = 'draft' OR (program_status IS NULL AND (category = 'Event' OR requires_attendance = true) AND completed_at IS NULL AND (approval_status != 'approved' OR is_published = false)) THEN 1 END) as draft_programs")
                        ->selectSub(Resident::query()->where('status', 'active')->where('barangay_id', $barangayId)
                            ->whereHas('sectors', fn ($query) => $query->where('slug', 'senior'))->selectRaw('COUNT(*)'), 'residents')->first();

                    return [
                        'totalResidents' => (int) $counts->residents,
                        'totalAnnouncements' => (int) $counts->total,
                        'pendingAnnouncements' => (int) $counts->pending,
                        'draftPrograms' => (int) $counts->draft_programs,
                        'approvedAnnouncements' => (int) $counts->approved,
                    ];
                },
            )),
            'recentAnnouncements' => $this->deferProp(fn () => Announcement::where('barangay_id', $barangayId)
                ->where('target_audience', 'senior_citizens')
                ->latest()
                ->take(5)
                ->get()),
        ]);
    }

    public function residents(Request $request): Response
    {
        $this->authorizeSeniorStaff($request);
        $user = $request->user();

        $barangayId = $user->barangay_id;

        return Inertia::render('Senior/Residents/Index', [
            'auth' => $this->authProps($user),
            'residents' => $this->deferProp(fn () => Resident::query()
                ->with(['purok:id,name', 'sectors:id,name,slug', 'qrCredential:id,resident_id,is_active,revoked_at'])
                ->where('barangay_id', $barangayId)
                ->where('status', 'active')
                ->whereHas('sectors', fn ($s) => $s->where('slug', 'senior'))
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
                ])),
            'puroks' => $this->deferProp(fn () => $this->rememberReference(
                "senior:purok-options:{$barangayId}",
                300,
                fn () => Purok::where('barangay_id', $barangayId)
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (Purok $purok) => [
                        'id' => $purok->id,
                        'name' => $purok->name,
                    ])
                    ->all(),
            )),
        ]);
    }

    public function applications(Request $request): Response
    {
        $this->authorizeSeniorStaff($request);
        $user = $request->user();

        return Inertia::render('Senior/Applications/Index', [
            'auth' => $this->authProps($user),
            'applications' => $this->deferProp(fn () => SectorApplication::query()
                ->with(['resident.purok', 'sector', 'submittedByUser', 'staffReviewedBy', 'adminDecidedBy', 'documents'])
                ->where('barangay_id', $user->barangay_id)
                ->whereHas('sector', fn ($q) => $q->where('slug', 'senior'))
                ->latest()
                ->get()
                ->map(fn (SectorApplication $app) => $this->formatApplicationItem($app))),
        ]);
    }

    public function createApplication(Request $request): Response
    {
        $this->authorizeSeniorStaff($request);
        $user = $request->user();

        return Inertia::render('Senior/Applications/Create', [
            'auth' => $this->authProps($user),
        ]);
    }

    public function storeApplication(Request $request, SectorApplicationService $service): RedirectResponse
    {
        $this->authorizeSeniorStaff($request);
        $user = $request->user();

        $validated = $request->validate([
            'resident_id' => ['required', 'integer', 'exists:residents,id'],
            'osca_id' => ['nullable', 'string', 'max:100'],
            'emergency_contact' => ['nullable', 'string', 'max:255'],
            'documents' => ['nullable', 'array'],
            'documents.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        $details = array_filter([
            'osca_id' => $validated['osca_id'] ?? null,
            'emergency_contact' => $validated['emergency_contact'] ?? null,
        ]);

        $formattedDocs = [];
        if ($request->hasFile('documents')) {
            foreach ($request->file('documents') as $index => $file) {
                $formattedDocs[] = [
                    'file' => $file,
                    'type' => 'senior_proof',
                    'title' => 'Senior Proof Document #'.($index + 1),
                ];
            }
        }

        $application = $service->submitStaffAssisted(
            staff: $user,
            residentId: (int) $validated['resident_id'],
            sectorSlug: 'senior',
            details: $details,
            documents: $formattedDocs
        );

        return redirect()->route('senior.applications.show', $application->id)
            ->with('success', 'Senior Citizen application '.$application->tracking_number.' successfully created.');
    }

    public function showApplication(Request $request, SectorApplication $application): Response
    {
        $this->authorizeSeniorStaff($request);
        $user = $request->user();

        if ($application->barangay_id !== $user->barangay_id || $application->sector->slug !== 'senior') {
            abort(404);
        }

        return Inertia::render('Senior/Applications/Show', [
            'auth' => $this->authProps($user),
            'application' => $this->deferProp(function () use ($application): array {
                $application->loadMissing(['resident.purok', 'sector', 'submittedByUser', 'staffReviewedBy', 'adminDecidedBy', 'documents']);

                return $this->formatApplicationItem($application);
            }),
        ]);
    }

    public function approveApplication(
        Request $request,
        SectorApplication $application,
        SectorApplicationService $service
    ): RedirectResponse {
        $this->authorizeSeniorStaff($request);
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

        return redirect()->route('senior.applications.show', $application->id)
            ->with('success', 'Application approved and Senior Citizens sector membership activated.');
    }

    public function rejectApplication(
        Request $request,
        SectorApplication $application,
        SectorApplicationService $service
    ): RedirectResponse {
        $this->authorizeSeniorStaff($request);
        $user = $request->user();

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $service->staffReject(
            application: $application,
            staff: $user,
            rejectionReason: $validated['reason']
        );

        return redirect()->route('senior.applications.show', $application->id)
            ->with('success', 'Application rejected and the reason recorded.');
    }

    public function requestCorrection(
        Request $request,
        SectorApplication $application,
        SectorApplicationService $service
    ): RedirectResponse {
        $this->authorizeSeniorStaff($request);
        $user = $request->user();

        $validated = $request->validate([
            'correction_notes' => ['required', 'string', 'max:1000'],
        ]);

        $service->requestCorrection(
            application: $application,
            staff: $user,
            correctionNotes: $validated['correction_notes']
        );

        return redirect()->route('senior.applications.show', $application->id)
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

    private function authProps($user): array
    {
        return [
            'user' => ['name' => $user->name],
            'staffAssignments' => $user->staffAssignments()
                ->where('department', 'senior')->where('is_active', true)
                ->get(['department', 'position']),
        ];
    }
}
