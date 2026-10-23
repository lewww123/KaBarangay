<?php

namespace App\Http\Controllers\Lupon;

use App\Http\Controllers\Controller;
use App\Models\LuponComplaint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LuponComplaintController extends Controller
{
    /**
     * Canonical intake statuses used by the resident tracker and this queue.
     *
     * Intake only. Hearing, mediation, and case-management statuses are not
     * part of this batch and are deliberately absent.
     */
    private const STATUSES = [
        'Submitted',
        'Under Review',
        'Accepted',
        'Rejected',
    ];

    /**
     * Allowed status transitions for an intake complaint.
     *
     * Submitted -> Under Review -> {Accepted, Rejected}. Accepted and
     * Rejected are terminal for intake. Re-applying the current status is
     * allowed so a note can be updated without moving the complaint.
     */
    private const TRANSITIONS = [
        'Submitted' => ['Under Review', 'Accepted', 'Rejected'],
        'Under Review' => ['Accepted', 'Rejected'],
        'Accepted' => [],
        'Rejected' => [],
    ];

    /**
     * Statuses that end the intake workflow.
     */
    private const TERMINAL_STATUSES = [
        'Accepted',
        'Rejected',
    ];

    /**
     * Lupon complaint queue for the authenticated lupon staff member's
     * barangay. Complaints from other barangays are never returned.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        if ($user->role !== 'lupon') {
            abort(403, 'Unauthorized access to the Lupon complaint queue.');
        }

        $statusFilter = $request->query('status');

        if (! is_string($statusFilter)
            || ! in_array($statusFilter, self::STATUSES, true)) {
            $statusFilter = null;
        }

        $query = LuponComplaint::query()
            ->where('barangay_id', $user->barangay_id);

        if ($statusFilter !== null) {
            $query->where('status', $statusFilter);
        }

        $complaints = $query
            ->with('resident:id,first_name,middle_name,last_name,suffix,resident_number')
            ->latest('created_at')
            ->get()
            ->map(function (LuponComplaint $complaint) {
                return [
                    'id' => $complaint->id,
                    'reference' => $complaint->reference,
                    'resident' => [
                        'name' => $complaint->resident?->full_name ?? 'Unknown resident',
                        'residentNumber' => $complaint->resident?->resident_number,
                    ],
                    'respondentName' => $complaint->respondent_name,
                    'respondentResidentStatus' => $complaint->respondent_resident_status,
                    'respondentAddress' => $complaint->respondent_address,
                    'respondentContact' => $complaint->respondent_contact,
                    'complaintType' => $complaint->complaint_type,
                    'incidentDate' => $complaint->incident_date?->format('F j, Y') ?? '',
                    'incidentTime' => $complaint->incident_time ?: null,
                    'incidentLocation' => $complaint->incident_location,
                    // Narrative and requested action are the subject of review.
                    // They are returned per record only, and never inside the
                    // aggregate counts below.
                    'narrative' => $complaint->narrative,
                    'requestedAction' => $complaint->requested_action,
                    'status' => $complaint->status,
                    'luponNote' => $complaint->lupon_note,
                    'submittedDate' => (
                        $complaint->submitted_at
                        ?? $complaint->created_at
                    )?->format('F j, Y') ?? '',
                ];
            })
            ->values();

        // Aggregate counts: integers only. No complaint narratives or other
        // confidential detail is included in this payload.
        $pendingCount = LuponComplaint::query()
            ->where('barangay_id', $user->barangay_id)
            ->whereIn('status', ['Submitted', 'Under Review'])
            ->count();

        $byStatus = collect(self::STATUSES)
            ->mapWithKeys(function (string $status) use ($user) {
                return [
                    $status => LuponComplaint::query()
                        ->where('barangay_id', $user->barangay_id)
                        ->where('status', $status)
                        ->count(),
                ];
            })
            ->all();

        return Inertia::render('Lupon/Complaints', [
            'userName' => $user->name,
            'barangayName' => $user->barangay?->name,
            'complaints' => $complaints,
            'statuses' => self::STATUSES,
            'activeStatusFilter' => $statusFilter,
            'stats' => [
                'pendingCount' => $pendingCount,
                'byStatus' => $byStatus,
            ],
        ]);
    }

    /**
     * Transition a complaint's intake status.
     *
     * The complaint must belong to the authenticated lupon staff member's
     * barangay. Rejection requires a note.
     */
    public function updateStatus(Request $request, LuponComplaint $complaint): RedirectResponse
    {
        $user = $request->user();

        if ($user->role !== 'lupon') {
            abort(403, 'Unauthorized access to the Lupon complaint queue.');
        }

        if ($complaint->barangay_id !== $user->barangay_id) {
            abort(403, 'Unauthorized action.');
        }

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(self::STATUSES)],
            'lupon_note' => ['nullable', 'string', 'max:1000', 'required_if:status,Rejected'],
        ]);

        $status = $validated['status'];
        $currentStatus = $complaint->status;

        if ($status !== $currentStatus) {
            if (in_array($currentStatus, self::TERMINAL_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'status' => "A {$currentStatus} complaint is final and cannot change status.",
                ]);
            }

            if (! in_array($status, self::TRANSITIONS[$currentStatus] ?? [], true)) {
                throw ValidationException::withMessages([
                    'status' => "A complaint cannot move from \"{$currentStatus}\" to \"{$status}\".",
                ]);
            }
        }

        $attributes = [
            'status' => $status,
            'lupon_note' => $validated['lupon_note'] ?? null,
        ];

        if ($status !== 'Submitted' && $complaint->reviewed_at === null) {
            $attributes['reviewed_at'] = now();
        }

        if ($status === 'Rejected' && $complaint->rejected_at === null) {
            $attributes['rejected_at'] = now();
        }

        $complaint->update($attributes);

        return redirect()->back()->with('success', 'Complaint status updated.');
    }
}
