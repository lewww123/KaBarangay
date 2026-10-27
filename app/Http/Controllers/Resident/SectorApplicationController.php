<?php

namespace App\Http\Controllers\Resident;

use App\Http\Controllers\Controller;
use App\Models\Resident;
use App\Models\SectorApplication;
use App\Services\SectorApplicationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SectorApplicationController extends Controller
{
    private function getAuthenticatedResident(): Resident
    {
        $residentId = session('resident_id');
        if (! $residentId) {
            abort(401, 'Resident authentication required.');
        }

        return Resident::query()
            ->with(['barangay', 'sectors', 'purok'])
            ->where('id', $residentId)
            ->where('status', 'active')
            ->firstOrFail();
    }

    public function index(): Response
    {
        $resident = $this->getAuthenticatedResident();

        $applications = SectorApplication::query()
            ->with(['sector', 'staffReviewedBy', 'adminDecidedBy', 'documents'])
            ->where('resident_id', $resident->id)
            ->latest()
            ->get()
            ->map(fn (SectorApplication $app) => [
                'id' => $app->id,
                'tracking_number' => $app->tracking_number,
                'resident_id' => $app->resident_id,
                'resident_number' => $resident->resident_number,
                'applicant_name' => $resident->full_name,
                'purok' => $resident->purok?->name ?? '—',
                'sector' => $app->sector->slug,
                'sector_name' => $app->sector->name,
                'submission_type' => $app->submission_type,
                'status' => $app->status,
                'submitted_at' => $app->created_at->format('M d, Y'),
                'reviewed_by_staff_name' => $app->staffReviewedBy?->name,
                'staff_review_notes' => $app->staff_review_notes,
                'admin_decision_notes' => $app->admin_decision_notes,
                'admin_decided_at' => $app->admin_decided_at?->format('M d, Y'),
                'documents' => $app->documents->map(fn ($doc) => [
                    'id' => $doc->id,
                    'title' => $doc->title,
                    'type' => $doc->document_type,
                    'file_name' => $doc->original_name,
                    'file_size' => round($doc->file_size / 1024) . ' KB',
                    'verification_status' => $doc->verification_status,
                    'url' => route('sector-applications.documents.show', [$app->id, $doc->id]),
                ])->values()->all(),
            ]);

        return Inertia::render('Resident/SectorApplications/Index', [
            'resident' => [
                'id' => $resident->id,
                'full_name' => $resident->full_name,
                'resident_number' => $resident->resident_number,
                'barangay' => $resident->barangay?->name,
                'sectors' => $resident->sectors->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'slug' => $s->slug,
                ])->values(),
            ],
            'applications' => $applications,
        ]);
    }

    public function store(Request $request, SectorApplicationService $service): RedirectResponse
    {
        $resident = $this->getAuthenticatedResident();

        $validated = $request->validate([
            'sector' => ['required', 'string', 'in:senior,pwd'],
            'details' => ['nullable', 'array'],
            'documents' => ['nullable', 'array'],
            'documents.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        $formattedDocs = [];
        if ($request->hasFile('documents')) {
            foreach ($request->file('documents') as $index => $file) {
                $formattedDocs[] = [
                    'file' => $file,
                    'type' => $validated['sector'] === 'senior' ? 'senior_proof' : 'pwd_proof',
                    'title' => ucfirst($validated['sector']) . ' Proof Document #' . ($index + 1),
                ];
            }
        }

        $application = $service->submitSelfService(
            resident: $resident,
            sectorSlug: $validated['sector'],
            details: $validated['details'] ?? [],
            documents: $formattedDocs
        );

        return redirect()->route('resident.sector-applications')
            ->with('success', 'Your application ' . $application->tracking_number . ' has been submitted.');
    }

    /**
     * Show a single application for correction (if applicable).
     */
    public function edit(int $id, SectorApplicationService $service): Response
    {
        $resident = $this->getAuthenticatedResident();

        // Fetch the application without performing any state change.
        $application = \App\Models\SectorApplication::with(['sector', 'documents'])
            ->where('id', $id)
            ->where('resident_id', $resident->id)
            ->firstOrFail();

        return Inertia::render('Resident/SectorApplications/Edit', [
            'resident' => [
                'id' => $resident->id,
                'full_name' => $resident->full_name,
            ],
            'application' => [
                'id' => $application->id,
                'tracking_number' => $application->tracking_number,
                'sector' => $application->sector->slug,
                'details' => $application->details,
                'correction_notes' => $application->correction_notes,
                'documents' => $application->documents->map(fn($doc) => [
                    'id' => $doc->id,
                    'title' => $doc->title,
                    'type' => $doc->document_type,
                    'url' => route('sector-applications.documents.show', [$application->id, $doc->id]),
                ])->values()->all(),
            ],
        ]);
    }

    /**
     * Handle resident resubmission after correction.
     */
    public function update(Request $request, int $id, SectorApplicationService $service): RedirectResponse
    {
        $resident = $this->getAuthenticatedResident();

        $validated = $request->validate([
            'details' => ['nullable', 'array'],
            'documents' => ['nullable', 'array'],
            'documents.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        // Derive the sector from the existing application record (server-side, not from request)
        $existingApp = \App\Models\SectorApplication::with('sector')
            ->where('id', $id)
            ->where('resident_id', $resident->id)
            ->firstOrFail();
        $sectorSlug = $existingApp->sector->slug;

        $formattedDocs = [];
        if ($request->hasFile('documents')) {
            foreach ($request->file('documents') as $index => $file) {
                $formattedDocs[] = [
                    'file' => $file,
                    'type' => $sectorSlug === 'senior' ? 'senior_proof' : 'pwd_proof',
                    'title' => 'Correction Document #' . ($index + 1),
                ];
            }
        }

        $application = $service->residentResubmitCorrection(
            $resident,
            $id,
            $validated['details'] ?? [],
            $formattedDocs
        );

        return redirect()->route('resident.sector-applications')
            ->with('success', 'Your corrected application ' . $application->tracking_number . ' has been resubmitted.');
    }
}
