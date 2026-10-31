<?php

namespace App\Http\Controllers\Lupon;

use App\Http\Controllers\Controller;
use App\Models\LuponComplaint;
use App\Support\AuditLogger;
use App\Support\ReferenceData;
use App\Support\ResidentNotificationService;
use Illuminate\Support\Facades\DB;
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

        if (! $user->staffAssignments()->where('department', 'lupon')->where('is_active', true)->exists()) {
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

        $barangayId = $user->barangay_id;

        return Inertia::render('Lupon/Complaints', [
            'userName' => $user->name,
            'barangayName' => fn () => ReferenceData::barangay((int) $barangayId)['name'],
            'complaints' => $this->deferProp(fn () => $query
                ->with([
                    'resident:id,first_name,middle_name,last_name,suffix,resident_number',
                    'case:id,complaint_id,case_number,phase,status,payment_status,fee_amount,or_number,paid_at,form_7_issued_at',
                ])
                ->select([
                    'id', 'barangay_id', 'resident_id', 'reference', 'respondent_name',
                    'respondent_resident_status', 'respondent_address', 'respondent_contact',
                    'complaint_type', 'incident_date', 'incident_time', 'incident_location',
                    'narrative', 'requested_action', 'status', 'lupon_note', 'submitted_at', 'created_at',
                ])
                ->latest('created_at')
                ->paginate(15)
                ->withQueryString()
                ->through(function (LuponComplaint $complaint) {
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
                        'case' => $complaint->case ? [
                            'id' => $complaint->case->id,
                            'caseNumber' => $complaint->case->case_number,
                            'phase' => $complaint->case->phase,
                            'status' => $complaint->case->status,
                            'paymentStatus' => $complaint->case->payment_status,
                            'feeAmount' => $complaint->case->fee_amount,
                            'orNumber' => $complaint->case->or_number,
                            'form7IssuedAt' => $complaint->case->form_7_issued_at?->format('F j, Y g:i A'),
                        ] : null,
                    ];
                })),
            'statuses' => self::STATUSES,
            'activeStatusFilter' => $statusFilter,
            'stats' => $this->deferProp(fn () => $this->rememberAggregate(
                "lupon:complaints:stats:{$barangayId}",
                30,
                function () use ($barangayId): array {
                    // Aggregate counts: integers only. No complaint narratives
                    // or other confidential detail is included in this payload.
                    $counts = LuponComplaint::query()
                        ->where('barangay_id', $barangayId)
                        ->selectRaw('status, COUNT(*) as total')
                        ->groupBy('status')
                        ->pluck('total', 'status');

                    return [
                        'pendingCount' => (int) ($counts['Submitted'] ?? 0) + (int) ($counts['Under Review'] ?? 0),
                        'byStatus' => collect(self::STATUSES)
                            ->mapWithKeys(fn (string $status) => [
                                $status => (int) ($counts[$status] ?? 0),
                            ])
                            ->all(),
                    ];
                },
            )),
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

        if (! $user->staffAssignments()->where('department', 'lupon')->where('is_active', true)->exists()) {
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

        AuditLogger::record(
            event: 'lupon_complaint.status_updated',
            barangayId: $user->barangay_id,
            actorId: $user->id,
            entityType: 'lupon_complaint',
            entityId: $complaint->id,
            context: [
                'action' => 'status_updated',
                'entity_reference' => $complaint->reference,
                'previous_status' => $currentStatus,
                'new_status' => $status,
            ],
            ipAddress: $request->ip()
        );

        return redirect()->back()->with('success', 'Complaint status updated.');
    }

    /** Record the manual fee and activate a promoted, verified KP case. */
    public function verifyFee(
        Request $request,
        LuponComplaint $complaint,
        ResidentNotificationService $notifications,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user->staffAssignments()->where('department', 'lupon')->where('is_active', true)->exists() || $complaint->barangay_id !== $user->barangay_id) {
            abort(403, 'Unauthorized access to complaint.');
        }

        $validated = $request->validate([
            'fee_amount' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'or_number' => ['required', 'string', 'max:100'],
            'payment_status' => ['required', Rule::in(['paid', 'waived'])],
        ]);

        [$case, $respondent] = DB::transaction(function () use ($complaint, $user, $validated) {
            $locked = LuponComplaint::query()
                ->whereKey($complaint->id)
                ->where('barangay_id', $user->barangay_id)
                ->lockForUpdate()
                ->firstOrFail();
            $case = $locked->case()
                ->with(['respondents' => fn ($query) => $query
                    ->where('is_primary', true)
                    ->with('resident:id,first_name,middle_name,last_name,suffix,email')])
                ->lockForUpdate()
                ->first();

            if ($case === null) {
                throw ValidationException::withMessages([
                    'fee_amount' => 'Promote and verify the respondent before activating this complaint.',
                ]);
            }

            $respondent = $case->respondents->first();
            if ($respondent?->association_status !== 'verified' || $respondent->resident === null) {
                throw ValidationException::withMessages([
                    'fee_amount' => 'Verify and link the respondent resident from the case record before activating it.',
                ]);
            }

            if ($case->payment_status !== 'unpaid') {
                throw ValidationException::withMessages([
                    'fee_amount' => 'This case has already had its fee verified.',
                ]);
            }

            $case->update([
                'payment_status' => $validated['payment_status'],
                'fee_amount' => $validated['fee_amount'],
                'or_number' => $validated['or_number'],
                'paid_at' => now(),
                'form_7_issued_at' => now(),
                'status' => 'Under Mediation',
            ]);

            return [$case->fresh(), $respondent->resident];
        });

        $notification = $notifications->enqueueLuponSummons($respondent, $case);
        $notifications->deliver($notification);

        AuditLogger::record(
            event: 'lupon_case.fee_verified',
            barangayId: $user->barangay_id,
            actorId: $user->id,
            entityType: 'lupon_case',
            entityId: $case->id,
            context: ['action' => 'fee_verified', 'entity_reference' => $case->case_number],
            ipAddress: $request->ip(),
        );

        return redirect()->back()->with('success', "Fee verified. KP Form 7 summons issued for {$case->case_number}.");
    }
}
