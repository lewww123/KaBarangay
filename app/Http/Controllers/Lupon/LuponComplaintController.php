<?php

namespace App\Http\Controllers\Lupon;

use App\Http\Controllers\Controller;
use App\Models\LuponComplaint;
use App\Support\AuditLogger;
use App\Support\CertificateOfficials;
use App\Support\LuponAccess;
use App\Support\LuponEvidenceStore;
use App\Support\ReferenceData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        'For Payment',
        'Accepted',
        'Rejected',
    ];

    /**
     * Allowed status transitions for an intake complaint.
     *
     * Review/rejection actions only. Acceptance is recorded atomically with
     * the paid receipt and case creation, never through a status picker.
     */
    private const TRANSITIONS = [
        'Submitted' => ['Under Review', 'Rejected'],
        'Under Review' => ['Rejected'],
        'For Payment' => ['Rejected'],
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
            'officials' => CertificateOfficials::resolve((int) $user->barangay_id),
            'barangayName' => fn () => ReferenceData::barangay((int) $barangayId)['name'],
            'complaints' => $this->deferProp(fn () => $query
                ->with([
                    'resident:id,first_name,middle_name,last_name,suffix,resident_number',
                    'evidence',
                    'case:id,complaint_id,case_number,phase,status,payment_status,fee_amount,or_number,paid_at,form_7_issued_at',
                ])
                ->select([
                    'id', 'barangay_id', 'resident_id', 'reference', 'respondent_name',
                    'respondent_resident_status', 'respondent_address', 'respondent_contact',
                    'complaint_type', 'incident_date', 'incident_time', 'incident_location',
                    'narrative', 'requested_action', 'status', 'lupon_note', 'submitted_at', 'created_at', 'assessed_fee', 'payment_requested_at',
                ])
                ->latest('created_at')
                ->paginate(15)
                ->withQueryString()
                ->through(function (LuponComplaint $complaint) {
                    return [
                        'id' => $complaint->id,
                        'evidence' => $complaint->evidence->map(fn ($file) => LuponEvidenceStore::summary($file))->all(),
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
                        'assessedFee' => $complaint->assessed_fee,
                        'paymentRequestedAt' => $complaint->payment_requested_at?->toIso8601String(),
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
            'status' => ['required', 'string', Rule::in(['Under Review', 'Rejected'])],
            'lupon_note' => ['nullable', 'string', 'max:1000', 'required_if:status,Rejected'],
        ]);

        $status = $validated['status'];
        DB::transaction(function () use ($complaint, $user, $validated, $status, $request) {
            $complaint = LuponComplaint::whereKey($complaint->id)->lockForUpdate()->firstOrFail();
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
            Cache::forget('lupon:complaints:stats:'.$user->barangay_id);

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
        });

        return redirect()->back()->with('success', 'Complaint status updated.');
    }

    /** Completing intake review requests payment; it does not create a case. */
    public function requestPayment(Request $request, LuponComplaint $complaint): RedirectResponse
    {
        LuponAccess::staff($request);
        abort_unless((int) $complaint->barangay_id === (int) $request->user()->barangay_id, 403);
        $data = $request->validate([
            'assessed_fee' => ['required', 'numeric', 'min:0.01', 'max:999999.99', 'decimal:0,2'],
            'lupon_note' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($complaint, $request, $data) {
            $locked = LuponComplaint::whereKey($complaint->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['Submitted', 'Under Review', 'Accepted'], true) || $locked->case()->exists()) {
                throw ValidationException::withMessages(['assessed_fee' => 'This complaint has already advanced. Refresh and review its current record.']);
            }
            $previous = $locked->status;
            $locked->update([
                'status' => 'For Payment',
                'assessed_fee' => $data['assessed_fee'],
                'payment_requested_at' => now(),
                'reviewed_at' => $locked->reviewed_at ?? now(),
                'lupon_note' => $data['lupon_note'] ?? null,
            ]);
            AuditLogger::record('lupon_complaint.payment_requested', barangayId: $locked->barangay_id,
                actorId: $request->user()->id, entityType: 'lupon_complaint', entityId: $locked->id,
                context: ['entity_reference' => $locked->reference, 'previous_status' => $previous, 'new_status' => 'For Payment'],
                ipAddress: $request->ip());
        });
        Cache::forget('lupon:complaints:stats:'.$request->user()->barangay_id);

        return back()->with('success', 'Review completed. The resident can now see the filing fee and payment instructions. No case has been created yet.');
    }

    /** Record the manual fee and activate a promoted, verified KP case. */
    public function verifyFee(Request $request, LuponComplaint $complaint): RedirectResponse
    {
        LuponAccess::staff($request);
        abort_unless((int) $complaint->barangay_id === (int) $request->user()->barangay_id, 403);

        return app(LuponCaseController::class)->promote($request, $complaint);
    }
}
