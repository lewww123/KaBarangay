<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SectorApplication;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'sector' => ['nullable', Rule::in(['all', 'senior', 'pwd'])],
            'status' => ['nullable', Rule::in(['all', 'pending_staff_review', 'under_admin_review', 'approved', 'rejected', 'requires_correction'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $base = SectorApplication::query()->where('barangay_id', $user->barangay_id);
        $page = null;
        $applications = function () use ($base, $filters, &$page) {
            $query = (clone $base)->with(['resident.purok', 'sector', 'submittedByUser', 'staffReviewedBy', 'adminDecidedBy']);
            if (($filters['sector'] ?? 'all') !== 'all') {
                $query->whereHas('sector', fn ($sector) => $sector->where('slug', $filters['sector']));
            }
            if (($filters['status'] ?? 'all') !== 'all') {
                $query->where('status', $filters['status']);
            }
            if (($search = trim($filters['search'] ?? '')) !== '') {
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
                $query->where(fn ($match) => $match
                    ->whereRaw("LOWER(tracking_number) LIKE ? ESCAPE '!'", [$like])
                    ->orWhereHas('resident', fn ($resident) => $resident->where(fn ($name) => $name
                        ->whereRaw("LOWER(first_name) LIKE ? ESCAPE '!'", [$like])
                        ->orWhereRaw("LOWER(last_name) LIKE ? ESCAPE '!'", [$like])
                        ->orWhereRaw("LOWER(TRIM(first_name || ' ' || COALESCE(NULLIF(middle_name, '') || ' ', '') || last_name || COALESCE(' ' || NULLIF(suffix, ''), ''))) LIKE ? ESCAPE '!'", [$like])
                        ->orWhereRaw("LOWER(resident_number) LIKE ? ESCAPE '!'", [$like]))));
            }
            $page = $query->latest()->orderByDesc('id')->paginate(25)->withQueryString();

            return $page->getCollection()->map(fn (SectorApplication $app) => $this->formatApplicationItem($app, false));
        };

        return Inertia::render('Admin/SectorApplications/Index', [
            'applications' => $this->deferProp($applications),
            'pagination' => $this->deferProp(function () use (&$page, $applications): array {
                if ($page === null) {
                    $applications();
                }

                return ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'previous' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()];
            }),
            'stats' => $this->deferProp(fn () => (clone $base)->selectRaw("COUNT(*) as total, COUNT(CASE WHEN status = 'under_admin_review' THEN 1 END) as pending, COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved, COUNT(CASE WHEN status = 'pending_staff_review' THEN 1 END) as staff_review, COUNT(CASE WHEN status IN ('rejected', 'requires_correction') THEN 1 END) as returned")->first()->only(['total', 'pending', 'approved', 'staff_review', 'returned'])),
            'filters' => $filters,
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

    private function formatApplicationItem(SectorApplication $app, bool $includeDocuments = true): array
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
            'documents' => $includeDocuments ? $app->documents->map(fn ($doc) => [
                'id' => $doc->id,
                'title' => $doc->title,
                'type' => $doc->document_type,
                'file_name' => $doc->original_name,
                'file_size' => round($doc->file_size / 1024).' KB',
                'verification_status' => $doc->verification_status,
                'url' => route('sector-applications.documents.show', [$app->id, $doc->id]),
            ])->values()->all() : [],
        ];
    }
}
