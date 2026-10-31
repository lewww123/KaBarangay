<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SectorApplication;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SectorApplicationController extends Controller
{
    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();
        if (! $user->hasActiveAssignment(['admin'])) {
            abort(403, 'Unauthorized access to Barangay Administrator portal.');
        }
    }

    public function index(Request $request): Response
    {
        $this->authorizeAdmin($request);
        $user = $request->user();

        $applications = fn () => SectorApplication::query()
            ->with(['resident.purok', 'sector', 'submittedByUser', 'staffReviewedBy', 'adminDecidedBy', 'documents'])
            ->where('barangay_id', $user->barangay_id)
            ->latest()
            ->get()
            ->map(fn (SectorApplication $app) => $this->formatApplicationItem($app));

        return Inertia::render('Admin/SectorApplications/Index', [
            'applications' => $this->deferProp($applications),
        ]);
    }

    public function show(Request $request, SectorApplication $application): Response
    {
        $this->authorizeAdmin($request);
        $user = $request->user();

        if ($application->barangay_id !== $user->barangay_id) {
            abort(404);
        }

        return Inertia::render('Admin/SectorApplications/Show', [
            'application' => $this->deferProp(function () use ($application): array {
                $application->load(['resident.purok', 'sector', 'submittedByUser', 'staffReviewedBy', 'adminDecidedBy', 'documents']);

                return $this->formatApplicationItem($application);
            }),
        ]);
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
