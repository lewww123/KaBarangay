<?php

namespace App\Http\Controllers\Lupon;

use App\Http\Controllers\Controller;
use App\Models\Barangay;
use App\Models\LuponComplaint;
use App\Support\AuditLogger;
use App\Support\CertificateOfficials;
use App\Support\LuponAccess;
use App\Support\LuponCache;
use App\Support\LuponCaseWorkflow;
use App\Support\LuponCatalog;
use App\Support\LuponDocket;
use App\Support\LuponEvidenceStore;
use App\Support\LuponReferences;
use App\Support\ReferenceData;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        'Pending Payment',
        'Payment Verification',
        'Payment Verified',
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
        'Pending Payment' => ['Under Review', 'Rejected'],
        'Payment Verification' => ['Rejected'],
        'Payment Verified' => [],
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
     * The only two operational tabs in the complaint queue. Each tab also
     * carries the older labels that meant the same step, so historical
     * records never disappear from the queue they belong in.
     */
    private const QUEUE_TABS = [
        'Under Review' => ['Submitted', 'Under Review'],
        'For Payment' => ['Pending Payment', 'Payment Verification', 'For Payment'],
    ];

    /**
     * Intake records that left the operational queue. They stay readable
     * through this history filter; cases live in the Cases module.
     */
    private const CLOSED_STATUSES = [
        'Payment Verified',
        'Accepted',
        'Rejected',
    ];

    /**
     * Lupon complaint queue for the authenticated lupon staff member's
     * barangay. Complaints from other barangays are never returned.
     */
    public function index(Request $request): Response
    {
        LuponAccess::staff($request);
        $user = $request->user();

        if (! $user->staffAssignments()->where('department', 'lupon')->where('is_active', true)->exists()) {
            abort(403, 'Unauthorized access to the Lupon complaint queue.');
        }

        $queueMode = (string) ($request->route('queueMode') ?? 'complaints');
        $statusFilter = $this->statusFilter($request->query('status'));
        $queueTab = $queueMode === 'complaints'
            ? $this->queueTab($request->query('tab'), $statusFilter)
            : null;

        $query = LuponComplaint::query()
            ->where('barangay_id', $user->barangay_id);

        if ($queueMode !== 'complaints') {
            if ($statusFilter !== null) {
                $query->where('status', $statusFilter);
            }
        } elseif ($queueTab === 'Closed') {
            $query->whereIn('status', self::CLOSED_STATUSES);
        } else {
            $query->whereIn('status', self::QUEUE_TABS[$queueTab]);
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
                    'narrative', 'requested_action', 'status', 'lupon_note', 'submitted_at', 'created_at', 'assessed_fee', 'payment_requested_at', 'payment_verification', 'complainant_snapshot',
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
                            'name' => $complaint->resident?->full_name ?? $complaint->complainant_snapshot['name'] ?? 'Walk-in complainant',
                            'residentNumber' => $complaint->resident?->resident_number,
                        ],
                        // Staff-entered intake is the only writer of a complainant
                        // snapshot, so it marks the walk-in source without a new column.
                        'source' => $complaint->complainant_snapshot ? 'walk-in' : 'online',
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
                        'paymentVerification' => $complaint->payment_verification,
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
            'queueTabs' => array_keys(self::QUEUE_TABS),
            'activeQueueTab' => $queueTab,
            'canVerifyPayments' => LuponAccess::canVerify($request),
            'queueMode' => $queueMode,
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
                        'pendingCount' => (int) ($counts['Submitted'] ?? 0) + (int) ($counts['Under Review'] ?? 0) + (int) ($counts['Pending Payment'] ?? 0) + (int) ($counts['Payment Verification'] ?? 0),
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

    /** Canonical intake status from the query string; anything else is ignored. */
    private function statusFilter(mixed $status): ?string
    {
        return is_string($status) && in_array($status, self::STATUSES, true)
            ? $status
            : null;
    }

    /**
     * Queue tab for the complaint list. An explicit tab wins, a legacy
     * ?status= link resolves to the tab that shows that record, and anything
     * else opens the review tab.
     */
    private function queueTab(mixed $requested, ?string $status): string
    {
        $tab = is_string($requested) ? $requested : null;

        if ($tab === null && $status !== null) {
            if (in_array($status, self::CLOSED_STATUSES, true)) {
                return 'Closed';
            }

            foreach (self::QUEUE_TABS as $name => $statuses) {
                if (in_array($status, $statuses, true)) {
                    return $name;
                }
            }
        }

        return in_array($tab, [...array_keys(self::QUEUE_TABS), 'Closed'], true)
            ? $tab
            : 'Under Review';
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
            LuponCache::forget((int) $user->barangay_id);

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

    /** Stage 1 → Stage 2: saving the assessed fee moves the complaint to For Payment; no case is created. */
    public function requestPayment(Request $request, LuponComplaint $complaint): RedirectResponse
    {
        LuponAccess::secretary($request);
        abort_unless((int) $complaint->barangay_id === (int) $request->user()->barangay_id, 403);
        $data = $request->validate([
            'assessed_fee' => ['required', 'numeric', 'min:0.01', 'max:999999.99', 'decimal:0,2'],
            'lupon_note' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($complaint, $request, $data) {
            $locked = LuponComplaint::whereKey($complaint->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, self::TERMINAL_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'assessed_fee' => "A {$locked->status} complaint is final and cannot be assessed a filing fee.",
                ]);
            }
            if (! in_array($locked->status, ['Pending Payment', 'Payment Verification', 'Submitted', 'Under Review'], true) || $locked->case()->exists()) {
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
        LuponCache::forget((int) $request->user()->barangay_id);

        return back()->with('success', 'Assessment saved. The resident can now see the filing fee and payment instructions. No case has been created yet.');
    }

    /** Record the manual fee and activate a promoted, verified KP case. */
    public function verifyFee(Request $request, LuponComplaint $complaint): RedirectResponse
    {
        LuponAccess::staff($request);
        abort_unless((int) $complaint->barangay_id === (int) $request->user()->barangay_id, 403);

        return app(LuponCaseController::class)->promote($request, $complaint);
    }

    /**
     * Walk-in intake form. Recording a walk-in complaint creates a complaint
     * submission only; an official case still needs assessed fee and verified payment.
     */
    public function createWalkIn(Request $request): Response
    {
        LuponAccess::staff($request);

        return Inertia::render('Lupon/Complaints/WalkIn', [
            'userName' => $request->user()->name,
            'complaintTypes' => LuponCatalog::CASE_TYPES,
        ]);
    }

    public function storeWalkIn(Request $request): RedirectResponse
    {
        LuponAccess::staff($request);
        $user = $request->user();

        $validated = $request->validate([
            'submission_token' => ['required', 'uuid'],
            'evidence' => LuponCatalog::EVIDENCE_RULES,
            'evidence.*' => LuponCatalog::FILE_RULES,
            'complaint_type' => ['required', 'string', Rule::in(LuponCatalog::CASE_TYPES)],
            'incident_date' => ['required', 'date', 'before_or_equal:'.LuponCaseWorkflow::today()],
            'incident_time' => ['nullable', 'date_format:H:i'],
            'incident_location' => ['required', 'string', 'max:500'],
            'narrative' => ['required', 'string', 'max:5000'],
            'requested_action' => ['required', 'string', 'max:2000'],
            'complainant_name' => ['required', 'string', 'max:255'],
            'complainant_is_resident' => ['required', 'boolean'],
            'complainant_resident_id' => ['nullable', 'integer', Rule::exists('residents', 'id')->where(fn ($query) => $query->where('barangay_id', $user->barangay_id))],
            'complainant_address' => ['nullable', 'string', 'max:500'],
            'complainant_contact' => ['nullable', 'string', 'max:50'],
            'respondent_name' => ['required', 'string', 'max:255'],
            'respondent_is_resident' => ['required', 'boolean'],
            'respondent_resident_id' => ['nullable', 'integer', Rule::exists('residents', 'id')->where(fn ($query) => $query->where('barangay_id', $user->barangay_id))],
            'respondent_address' => ['nullable', 'string', 'max:1000'],
            'respondent_contact' => ['nullable', 'string', 'max:50'],
        ]);

        if (
            ! empty($validated['complainant_resident_id']) &&
            ! empty($validated['respondent_resident_id']) &&
            $validated['complainant_resident_id'] == $validated['respondent_resident_id']
        ) {
            throw ValidationException::withMessages([
                'respondent_resident_id' => 'Complainant and respondent cannot be the same registered resident.',
            ]);
        }

        $validated = LuponDocket::party(LuponDocket::party($validated, 'complainant', (int) $user->barangay_id), 'respondent', (int) $user->barangay_id);

        $attributes = [
            'barangay_id' => $user->barangay_id,
            'resident_id' => $validated['complainant_resident_id'] ?? null,
            'complainant_snapshot' => [
                'name' => $validated['complainant_name'],
                'address' => $validated['complainant_address'] ?? null,
                'contact' => $validated['complainant_contact'] ?? null,
            ],
            'respondent_name' => $validated['respondent_name'],
            'respondent_resident_status' => $validated['respondent_is_resident'] ? 'Yes' : 'No',
            'respondent_address' => $validated['respondent_address'] ?? null,
            'respondent_contact' => $validated['respondent_contact'] ?? null,
            'complaint_type' => $validated['complaint_type'],
            'incident_date' => $validated['incident_date'],
            'incident_time' => $validated['incident_time'] ?? null,
            'incident_location' => $validated['incident_location'],
            'narrative' => $validated['narrative'],
            'requested_action' => $validated['requested_action'],
            'status' => 'Under Review',
            'submission_token' => $validated['submission_token'],
            'submitted_at' => now(),
        ];

        $complaint = DB::transaction(function () use ($attributes, $request, $user) {
            // A walk-in complainant may have no resident record, and a unique index treats
            // NULL resident_id rows as distinct, so the token lookup below is the only
            // duplicate guard. Serialize intake within this barangay on the same row lock
            // the case-creation paths already take, so a concurrent retry reads the
            // committed complaint instead of inserting a second submission.
            Barangay::whereKey($user->barangay_id)->lockForUpdate()->firstOrFail();

            $existing = LuponComplaint::where('barangay_id', $user->barangay_id)
                ->where('submission_token', $attributes['submission_token'])
                ->when($attributes['resident_id'], fn ($query) => $query->where('resident_id', $attributes['resident_id']))
                ->first();
            if ($existing) {
                return $existing;
            }

            $complaint = $this->createWithReference($attributes);
            LuponEvidenceStore::store($request->file('evidence', []), $complaint, null, null, $user->id);

            return $complaint;
        });

        AuditLogger::record(
            event: 'lupon_complaint.walk_in_submitted',
            barangayId: $user->barangay_id,
            actorId: $user->id,
            entityType: 'lupon_complaint',
            entityId: $complaint->id,
            context: [
                'action' => 'walk_in_submitted',
                'entity_reference' => $complaint->reference,
                'new_status' => $complaint->status,
            ],
            ipAddress: $request->ip()
        );
        LuponCache::forget((int) $user->barangay_id);

        return redirect()->route('lupon.complaints.index')->with(
            'success',
            "Walk-in complaint {$complaint->reference} is queued for review. No official case, case number, hearing, or notice has been created."
        );
    }

    /** Allocate the annual LUP reference inside a savepoint so a collision can be retried. */
    private function createWithReference(array $attributes): LuponComplaint
    {
        $prefix = 'LUP-'.Carbon::parse(LuponCaseWorkflow::today())->year.'-';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return DB::transaction(fn () => LuponComplaint::create([...$attributes, 'reference' => LuponReferences::next($prefix)]));
            } catch (QueryException $exception) {
                if (! $this->isUniqueViolation($exception)) {
                    throw $exception;
                }

                $existing = LuponComplaint::where('barangay_id', $attributes['barangay_id'])
                    ->where('submission_token', $attributes['submission_token'])
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }
        }

        throw new \RuntimeException('Unable to allocate a unique complaint reference.');
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'UNIQUE')
            || str_contains($message, 'Duplicate entry')
            || str_contains($message, 'SQLSTATE[23000]')
            || str_contains($message, 'SQLSTATE[23505]');
    }
}
