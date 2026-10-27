<?php

namespace App\Http\Controllers\Clerk;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\CertificateIssuance;
use App\Models\CertificateRequest;
use App\Models\OrgChartPosition;
use App\Models\Resident;
use App\Support\AuditLogger;
use App\Support\CertificateRequestDetails;
use App\Support\CertificateRequestWorkflow;
use App\Support\CertificateTemplates;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Certificate-request processing for the Clerk portal.
 *
 * Clerks view requests submitted by residents of their own barangay, review
 * and revise the certificate-specific details, and move requests through the
 * validated status workflow. Administrators have aggregate monitoring only;
 * they cannot reach these routes.
 */
class CertificateRequestController extends Controller
{
    /**
     * Page sizes the Clerk list will honor. Any other value falls back to the
     * default, so an arbitrary per_page can never reach the paginator.
     */
    private const ALLOWED_PER_PAGE = [10, 25, 50];

    private const DEFAULT_PER_PAGE = 10;

    public function index(Request $request)
    {
        // Barangay scoping is derived exclusively from the authenticated user.
        // A client-supplied barangay_id is never read.
        $barangayId = $request->user()->barangay_id;

        $search = trim((string) $request->query('search', ''));
        $requestedStatus = trim((string) $request->query('status', ''));
        $requestedCertificateId = trim((string) $request->query('certificate_id', ''));
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));
        $requestedPerPage = (int) $request->query('per_page', self::DEFAULT_PER_PAGE);

        // Missing, empty, "ALL", or unknown status is treated as no filter so
        // arbitrary input can never reach the query builder.
        $statusFilter = in_array($requestedStatus, CertificateRequestWorkflow::statuses(), true)
            ? $requestedStatus
            : null;

        // Certificate-type filter must reference a certificate catalog entry in
        // the Clerk's own barangay; anything else is ignored.
        $certificateFilter = null;
        if ($requestedCertificateId !== '' && ctype_digit($requestedCertificateId)) {
            $certificateFilter = Certificate::query()
                ->where('barangay_id', $barangayId)
                ->whereKey((int) $requestedCertificateId)
                ->value('id');
        }

        // Date boundaries are validated strictly; malformed values are ignored
        // rather than passed to the database.
        $dateFromValue = $this->parseDate($dateFrom);
        $dateToValue = $this->parseDate($dateTo);

        $perPage = in_array($requestedPerPage, self::ALLOWED_PER_PAGE, true)
            ? $requestedPerPage
            : self::DEFAULT_PER_PAGE;

        // Every token must match at least one searchable field (AND across
        // tokens). LIKE wildcards are stripped so wildcard-only input cannot
        // silently match every request.
        $tokens = collect(preg_split('/\s+/', $search) ?: [])
            ->map(fn (string $token) => str_replace(['%', '_'], '', $token))
            ->filter(fn (string $token) => $token !== '')
            ->values();

        // Barangay scope is applied first; the search predicate is wrapped in
        // its own grouped clause so its OR conditions can never escape the
        // Clerk's barangay restriction.
        $query = CertificateRequest::query()
            ->where('barangay_id', $barangayId);

        if ($search !== '') {
            if ($tokens->isEmpty()) {
                $query->whereRaw('1 = 0');
            } else {
                foreach ($tokens as $token) {
                    $term = '%'.$token.'%';

                    $query->where(function ($group) use ($term) {
                        $group->where('reference', 'like', $term)
                            ->orWhereHas('resident', function ($resident) use ($term) {
                                $resident->where('first_name', 'like', $term)
                                    ->orWhere('middle_name', 'like', $term)
                                    ->orWhere('last_name', 'like', $term)
                                    ->orWhere('suffix', 'like', $term)
                                    ->orWhere('resident_number', 'like', $term);
                            });
                    });
                }
            }
        }

        if ($statusFilter !== null) {
            $query->where('status', $statusFilter);
        }

        if ($certificateFilter !== null) {
            $query->where('certificate_id', $certificateFilter);
        }

        if ($dateFromValue !== null) {
            $query->where('submitted_at', '>=', $dateFromValue);
        }

        if ($dateToValue !== null) {
            // Include the whole end day regardless of the stored time.
            $query->where('submitted_at', '<=', $dateToValue->copy()->endOfDay());
        }

        $requests = $query
            ->with([
                'resident:id,first_name,middle_name,last_name,suffix,resident_number',
                'certificate:id,name,code',
            ])
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString()
            ->through(function (CertificateRequest $certificateRequest) {
                return [
                    'id' => $certificateRequest->id,
                    'reference' => $certificateRequest->reference,
                    'certificate' => $certificateRequest->certificate?->name ?? 'Certificate',
                    'resident' => [
                        'name' => $certificateRequest->resident?->full_name ?? 'Unknown resident',
                        'resident_number' => $certificateRequest->resident?->resident_number,
                    ],
                    'status' => $certificateRequest->status,
                    'submitted_at' => $certificateRequest->submitted_at?->format('F j, Y') ?? '',
                ];
            });

        // Certificate-type filter options for the Clerk's own barangay only.
        $certificateOptions = Certificate::query()
            ->where('barangay_id', $barangayId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Certificate $certificate) => [
                'id' => $certificate->id,
                'name' => $certificate->name,
            ])
            ->all();

        $signatories = OrgChartPosition::query()
            ->where('barangay_id', $barangayId)
            ->where('status', 'published')
            ->whereNotNull('assigned_person')
            ->where('assigned_person', '<>', '')
            ->orderBy('title')
            ->get(['title', 'assigned_person'])
            ->map(fn (OrgChartPosition $position) => [
                'name' => $position->assigned_person,
                'title' => $position->title,
            ])
            ->unique(fn (array $item) => mb_strtolower($item['name'].'|'.$item['title']))
            ->values();

        [$issuances, $issuedFilters] = $this->issuanceListing($request, $barangayId);

        [$allRecords, $allFilters] = $this->allListing($request, $barangayId);

        return Inertia::render('Clerk/Certificates', [
            'requests' => $requests,
            'issuances' => $issuances,
            'allRecords' => $allRecords,
            'statuses' => CertificateRequestWorkflow::statuses(),
            'certificateOptions' => $certificateOptions,
            'signatories' => $signatories,
            'filters' => [
                'search' => $search,
                'status' => $statusFilter ?? '',
                'certificate_id' => $certificateFilter !== null ? (string) $certificateFilter : '',
                'date_from' => $dateFromValue?->format('Y-m-d') ?? '',
                'date_to' => $dateToValue?->format('Y-m-d') ?? '',
                'per_page' => $perPage,
            ],
            'issuedFilters' => $issuedFilters,
            'allFilters' => $allFilters,
        ]);
    }

    /**
     * Detail view for a single certificate request.
     *
     * The request is resolved strictly within the Clerk's own barangay before
     * any data is projected. Only fields the Clerk needs to review, revise, or
     * issue the certificate are returned; resident contact details stay
     * private.
     */
    public function show(Request $request, CertificateRequest $certificateRequest): JsonResponse
    {
        $barangayId = $request->user()->barangay_id;

        abort_unless(
            $barangayId !== null && $certificateRequest->barangay_id === $barangayId,
            404,
        );

        $certificateRequest->loadMissing([
            'resident:id,barangay_id,purok_id,first_name,middle_name,last_name,suffix,resident_number',
            'resident.purok:id,name',
            'certificate:id,name,code',
        ]);

        $certificate = $certificateRequest->certificate;
        $resident = $certificateRequest->resident;
        $type = CertificateRequestDetails::typeForCertificate($certificate);
        $decoded = CertificateRequestDetails::decode($certificateRequest->additional_details);
        $detailsType = $decoded['type'] ?? $type;

        $issuance = CertificateIssuance::query()
            ->where('barangay_id', $barangayId)
            ->where('certificate_request_id', $certificateRequest->id)
            ->first();

        return response()->json([
            'id' => $certificateRequest->id,
            'reference' => $certificateRequest->reference,
            'certificate' => $certificate instanceof Certificate ? $certificate->name : 'Certificate',
            'certificate_type' => $type,
            'template_available' => $type !== null
                && CertificateTemplates::isAvailable($type),
            'resident' => [
                'id' => $resident?->id,
                'name' => $resident instanceof Resident ? $resident->full_name : 'Unknown resident',
                'resident_number' => $resident instanceof Resident ? $resident->resident_number : null,
                'purok' => $resident?->purok?->name ?? '',
            ],
            'purpose' => $certificateRequest->purpose,
            'fee' => (string) $certificateRequest->fee,
            'business_name' => $certificateRequest->business_name,
            'business_address' => $certificateRequest->business_address,
            'notes' => $decoded['notes'],
            'original_notes' => $decoded['original_notes'],
            'details' => CertificateRequestDetails::displayRows($detailsType, $decoded['fields']),
            'original_details' => CertificateRequestDetails::displayRows(
                $detailsType,
                $decoded['original_fields'],
            ),
            'details_fields' => CertificateRequestDetails::fieldsForType($detailsType),
            'detail_values' => $decoded['fields'],
            'status' => $certificateRequest->status,
            'admin_note' => $certificateRequest->admin_note,
            'allowed_statuses' => CertificateRequestWorkflow::genericStatuses(
                $certificateRequest->status,
            ),
            'submitted_at' => $certificateRequest->submitted_at?->format('F j, Y') ?? '',
            'reviewed_at' => $certificateRequest->reviewed_at?->format('F j, Y') ?? null,
            'issuance' => $issuance ? [
                'id' => $issuance->id,
                'reference' => $issuance->reference,
                'issued_at' => $issuance->issued_at->format('F j, Y g:i A'),
            ] : null,
        ]);
    }

    /**
     * Persist the Clerk's review of the certificate-specific values.
     *
     * The Resident's original submission is retained alongside the revised
     * values. Only whitelisted fields for the request's certificate type are
     * accepted, and the request must belong to the Clerk's barangay.
     */
    public function updateDetails(
        Request $request,
        CertificateRequest $certificateRequest,
    ): JsonResponse {
        $barangayId = $request->user()->barangay_id;

        abort_unless(
            $barangayId !== null && $certificateRequest->barangay_id === $barangayId,
            404,
        );

        $certificateRequest->loadMissing('certificate:id,name,code');

        $decoded = CertificateRequestDetails::decode(
            $certificateRequest->additional_details,
        );
        $type = $decoded['type']
            ?? CertificateRequestDetails::typeForCertificate(
                $certificateRequest->certificate,
            );

        $rules = CertificateRequestDetails::detailValidationRules($type);

        if ($rules === []) {
            abort(422, 'This request has no editable certificate details.');
        }

        $validated = $request->validate($rules);
        $fields = array_merge(
            $decoded['fields'],
            is_array($validated['details'] ?? null) ? $validated['details'] : [],
        );

        $certificateRequest->update([
            'additional_details' => CertificateRequestDetails::encodeRevision(
                $certificateRequest->additional_details,
                $type,
                $fields,
            ),
        ]);

        /*
        |--------------------------------------------------------------------------
        | Audit: certificate request details reviewed
        |--------------------------------------------------------------------------
        |
        | Values and free-text notes are never recorded, only the bounded event
        | and the request identity. The resident's original values remain in the
        | stored payload for audit and comparison.
        |
        */

        AuditLogger::record(
            'certificate_request.details_reviewed',
            $certificateRequest->barangay_id,
            $request->user()->id,
            'certificate_request',
            $certificateRequest->id,
            ['action' => 'details_reviewed'],
            $request->ip(),
        );

        $updated = CertificateRequestDetails::decode(
            $certificateRequest->fresh()->additional_details,
        );

        return response()->json([
            'message' => 'Certificate request details updated.',
            'details' => CertificateRequestDetails::displayRows(
                $updated['type'] ?? $type,
                $updated['fields'],
            ),
            'original_details' => CertificateRequestDetails::displayRows(
                $updated['type'] ?? $type,
                $updated['original_fields'],
            ),
            'detail_values' => $updated['fields'],
            'notes' => $updated['notes'],
            'original_notes' => $updated['original_notes'],
        ]);
    }

    /**
     * Move a request through the validated Clerk status workflow.
     */
    public function updateStatus(
        Request $request,
        CertificateRequest $certificateRequest,
    ): JsonResponse {
        $barangayId = $request->user()->barangay_id;

        abort_unless(
            $barangayId !== null && $certificateRequest->barangay_id === $barangayId,
            404,
        );

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(CertificateRequestWorkflow::statuses())],
            'admin_note' => ['nullable', 'string', 'max:1000', 'required_if:status,Rejected'],
        ]);

        $targetStatus = $validated['status'];
        $currentStatus = $certificateRequest->status;

        // CRITICAL SERVER-SIDE TRANSITION RESTRICTIONS:
        // Protected statuses (For Payment, Ready for Claim, Released) can NEVER be set directly
        // via the generic status endpoint. They must follow their dedicated workflow actions:
        // - 'For Payment': Automatically set upon linked certificate issuance (fee > 0).
        // - 'Ready for Claim': Automatically set upon linked issuance (fee == 0) or via dedicated payment confirmation.
        // - 'Released': Only set via dedicated physical handover release endpoint.
        if ($targetStatus !== $currentStatus) {
            if ($targetStatus === 'For Payment') {
                return response()->json([
                    'message' => 'The For Payment status is set automatically upon certificate issuance.',
                    'errors' => ['status' => ['The For Payment status is set automatically upon certificate issuance.']],
                ], 422);
            }

            if ($targetStatus === 'Ready for Claim') {
                return response()->json([
                    'message' => 'The Ready for Claim status requires payment confirmation or certificate issuance.',
                    'errors' => ['status' => ['The Ready for Claim status requires payment confirmation or certificate issuance.']],
                ], 422);
            }

            if ($targetStatus === 'Released') {
                return response()->json([
                    'message' => 'Dedicated physical handover confirmation is required to release a certificate.',
                    'errors' => ['status' => ['Dedicated physical handover confirmation is required to release a certificate.']],
                ], 422);
            }
        }

        $previousStatus = $certificateRequest->status;

        CertificateRequestWorkflow::apply(
            $certificateRequest,
            $targetStatus,
            $validated['admin_note'] ?? null,
        );

        /*
        |--------------------------------------------------------------------------
        | Audit: certificate request status changed
        |--------------------------------------------------------------------------
        |
        | Re-applying the current status only edits the note and records
        | nothing. The free-text note is never recorded; only the bounded status
        | transition is written.
        |
        */

        if ($certificateRequest->wasChanged('status')) {
            AuditLogger::record(
                'certificate_request.status_changed',
                $certificateRequest->barangay_id,
                $request->user()->id,
                'certificate_request',
                $certificateRequest->id,
                [
                    'previous_status' => $previousStatus,
                    'new_status' => $certificateRequest->status,
                ],
                $request->ip(),
            );
        }

        return response()->json([
            'message' => 'Certificate request status updated.',
            'status' => $certificateRequest->status,
            'allowed_statuses' => CertificateRequestWorkflow::genericStatuses(
                $certificateRequest->status,
            ),
            'admin_note' => $certificateRequest->admin_note,
        ]);
    }

    /**
     * Dedicated Clerk action to confirm payment for a certificate request.
     *
     * Payment confirmation is a Clerk attestation of actual collection, not
     * independent accounting verification. Requires that the request is currently
     * in 'For Payment' and has a valid linked issuance.
     */
    public function confirmPayment(
        Request $request,
        CertificateRequest $certificateRequest,
    ): JsonResponse {
        $barangayId = $request->user()->barangay_id;

        abort_unless(
            $barangayId !== null && $certificateRequest->barangay_id === $barangayId,
            404,
        );

        $validated = $request->validate([
            'confirm_payment' => ['required', 'accepted'],
            'admin_note' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($certificateRequest->status !== 'For Payment') {
            return response()->json([
                'message' => 'Only requests in For Payment status can have payment confirmed.',
                'errors' => ['status' => ['Only requests in For Payment status can have payment confirmed.']],
            ], 422);
        }

        $hasIssuance = CertificateIssuance::where('barangay_id', $barangayId)
            ->where('certificate_request_id', $certificateRequest->id)
            ->exists();

        if (! $hasIssuance) {
            return response()->json([
                'message' => 'Payment cannot be confirmed before certificate issuance.',
                'errors' => ['status' => ['Payment cannot be confirmed before certificate issuance.']],
            ], 422);
        }

        $previousStatus = $certificateRequest->status;
        $note = $validated['admin_note'] ?? 'Payment confirmed by clerk. Certificate is ready for claim.';

        DB::transaction(function () use ($certificateRequest, $note) {
            CertificateRequestWorkflow::apply(
                $certificateRequest,
                'Ready for Claim',
                $note,
            );
        });

        AuditLogger::record(
            'certificate_request.payment_confirmed',
            $certificateRequest->barangay_id,
            $request->user()->id,
            'certificate_request',
            $certificateRequest->id,
            [
                'action' => 'payment_confirmed',
                'previous_status' => $previousStatus,
                'new_status' => 'Ready for Claim',
            ],
            $request->ip(),
        );

        return response()->json([
            'message' => 'Payment confirmed. Certificate is ready for claim.',
            'status' => $certificateRequest->status,
            'allowed_statuses' => CertificateRequestWorkflow::genericStatuses(
                $certificateRequest->status,
            ),
            'admin_note' => $certificateRequest->admin_note,
            'ready_for_claim_at' => $certificateRequest->ready_for_claim_at?->format('F j, Y g:i A'),
        ]);
    }

    /**
     * Dedicated Clerk action to confirm physical handover and release of a certificate.
     *
     * Requires that the request is currently in 'Ready for Claim' and has a valid
     * linked issuance. Explicit confirmation of physical handover is required.
     */
    public function confirmRelease(
        Request $request,
        CertificateRequest $certificateRequest,
    ): JsonResponse {
        $barangayId = $request->user()->barangay_id;

        abort_unless(
            $barangayId !== null && $certificateRequest->barangay_id === $barangayId,
            404,
        );

        $validated = $request->validate([
            'confirm_handover' => ['required', 'accepted'],
            'admin_note' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($certificateRequest->status !== 'Ready for Claim') {
            return response()->json([
                'message' => 'Only requests in Ready for Claim status can be released.',
                'errors' => ['status' => ['Only requests in Ready for Claim status can be released.']],
            ], 422);
        }

        $hasIssuance = CertificateIssuance::where('barangay_id', $barangayId)
            ->where('certificate_request_id', $certificateRequest->id)
            ->exists();

        if (! $hasIssuance) {
            return response()->json([
                'message' => 'Cannot release a certificate that has not been issued yet.',
                'errors' => ['status' => ['Cannot release a certificate that has not been issued yet.']],
            ], 422);
        }

        $previousStatus = $certificateRequest->status;
        $note = $validated['admin_note'] ?? 'Certificate physically handed over and released.';

        DB::transaction(function () use ($certificateRequest, $note) {
            CertificateRequestWorkflow::apply(
                $certificateRequest,
                'Released',
                $note,
            );
        });

        AuditLogger::record(
            'certificate_request.released',
            $certificateRequest->barangay_id,
            $request->user()->id,
            'certificate_request',
            $certificateRequest->id,
            [
                'action' => 'certificate_released',
                'previous_status' => $previousStatus,
                'new_status' => 'Released',
            ],
            $request->ip(),
        );

        return response()->json([
            'message' => 'Certificate released successfully.',
            'status' => $certificateRequest->status,
            'allowed_statuses' => CertificateRequestWorkflow::genericStatuses(
                $certificateRequest->status,
            ),
            'admin_note' => $certificateRequest->admin_note,
            'released_at' => $certificateRequest->released_at?->format('F j, Y g:i A'),
        ]);
    }

    /**
     * Build the read-only issuance-history listing for the Issued tab.
     *
     * The Clerk can only ever see issuances recorded for their own barangay.
     * The scope is derived from the authenticated user; a client-supplied
     * barangay_id is never read. Only the fields needed for the table are
     * projected, and the issuer is reduced to a display name.
     *
     * @return array{0: LengthAwarePaginator, 1: array<string, mixed>}
     */
    private function issuanceListing(Request $request, ?int $barangayId): array
    {
        $search = trim((string) $request->query('issued_search', ''));
        $requestedType = trim((string) $request->query('issued_type', ''));
        $dateFrom = trim((string) $request->query('issued_date_from', ''));
        $dateTo = trim((string) $request->query('issued_date_to', ''));
        $requestedPerPage = (int) $request->query('issued_per_page', self::DEFAULT_PER_PAGE);

        // Certificate-type filter must reference a template type the feature
        // recognises; anything else is ignored rather than passed through.
        $typeFilter = in_array($requestedType, CertificateTemplates::types(), true)
            ? $requestedType
            : null;

        $dateFromValue = $this->parseDate($dateFrom);
        $dateToValue = $this->parseDate($dateTo);

        $perPage = in_array($requestedPerPage, self::ALLOWED_PER_PAGE, true)
            ? $requestedPerPage
            : self::DEFAULT_PER_PAGE;

        // Every token must match at least one searchable field (AND across
        // tokens). LIKE wildcards are stripped so wildcard-only input cannot
        // silently match every issuance.
        $tokens = collect(preg_split('/\s+/', $search) ?: [])
            ->map(fn (string $token) => str_replace(['%', '_'], '', $token))
            ->filter(fn (string $token) => $token !== '')
            ->values();

        // Barangay scope is applied first and independently; null scope matches
        // nothing. The search predicate is grouped so its OR conditions can
        // never escape the barangay restriction.
        $query = CertificateIssuance::query();

        if ($barangayId === null) {
            $query->whereRaw('1 = 0');
        } else {
            $query->where('barangay_id', $barangayId);
        }

        if ($search !== '') {
            if ($tokens->isEmpty()) {
                $query->whereRaw('1 = 0');
            } else {
                foreach ($tokens as $token) {
                    $term = '%'.$token.'%';

                    $query->where(function ($group) use ($term) {
                        $group->where('reference', 'like', $term)
                            ->orWhere('resident_name', 'like', $term)
                            ->orWhere('resident_number', 'like', $term);
                    });
                }
            }
        }

        if ($typeFilter !== null) {
            $query->where('certificate_type', $typeFilter);
        }

        if ($dateFromValue !== null) {
            $query->where('issued_at', '>=', $dateFromValue);
        }

        if ($dateToValue !== null) {
            // Include the whole end day regardless of the stored time.
            $query->where('issued_at', '<=', $dateToValue->copy()->endOfDay());
        }

        $issuances = $query
            ->with('issuer:id,name')
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'issued_page')
            ->withQueryString()
            ->through(function (CertificateIssuance $issuance) {
                return [
                    'id' => $issuance->id,
                    'reference' => $issuance->reference,
                    'certificate_type' => $issuance->certificate_type,
                    'resident' => [
                        'name' => $issuance->resident_name,
                        'resident_number' => $issuance->resident_number,
                    ],
                    'purok' => $issuance->purok,
                    'issued_at' => $issuance->issued_at?->format('F j, Y') ?? '',
                    'issued_by' => $issuance->issuer?->name ?? 'Unknown',
                ];
            });

        return [$issuances, [
            'search' => $search,
            'type' => $typeFilter ?? '',
            'date_from' => $dateFromValue?->format('Y-m-d') ?? '',
            'date_to' => $dateToValue?->format('Y-m-d') ?? '',
            'per_page' => $perPage,
        ]];
    }

    /**
     * Build the read-only combined "All Records" listing.
     *
     * Combines certificate requests and issued certificates into a single
     * paginated listing using a UNION ALL of two barangay-scoped projections
     * normalised to a common row shape. An explicit `record_type` distinguishes
     * the two sources, and issuance rows carry no request status (the UI shows
     * a neutral "Issued" state instead). Requests and issuances are never
     * merged on the client.
     *
     * Barangay scope is derived from the authenticated user and applied to both
     * branches independently; a missing barangay id matches nothing. Only
     * presentation fields are projected — no fee, resident_id, issued_by_id,
     * certificate_request_id, or other internal identifiers.
     *
     * @return array{0: LengthAwarePaginator, 1: array<string, mixed>}
     */
    private function allListing(Request $request, ?int $barangayId): array
    {
        $search = trim((string) $request->query('all_search', ''));
        $requestedType = trim((string) $request->query('all_type', ''));
        $dateFrom = trim((string) $request->query('all_date_from', ''));
        $dateTo = trim((string) $request->query('all_date_to', ''));
        $requestedPerPage = (int) $request->query('all_per_page', self::DEFAULT_PER_PAGE);

        $dateFromValue = $this->parseDate($dateFrom);
        $dateToValue = $this->parseDate($dateTo);

        $perPage = in_array($requestedPerPage, self::ALLOWED_PER_PAGE, true)
            ? $requestedPerPage
            : self::DEFAULT_PER_PAGE;

        // The type filter accepts, union-wide, either a recognised certificate
        // template type (matched against the issuance snapshot and the request's
        // certificate label) or a barangay-owned certificate catalog id. A
        // numeric id that is not owned by this barangay matches nothing; a
        // wholly unrecognised label is ignored rather than passed through.
        $typeIsTemplate = in_array($requestedType, CertificateTemplates::types(), true);

        $typeCertificateId = null;
        if (! $typeIsTemplate && $requestedType !== '' && ctype_digit($requestedType) && $barangayId !== null) {
            // A foreign or unknown numeric id resolves to nothing, so it must
            // filter every branch to zero rows rather than silently disabling
            // the filter (which would leak the full listing).
            $typeCertificateId = Certificate::query()
                ->where('barangay_id', $barangayId)
                ->whereKey((int) $requestedType)
                ->value('id') ?? -1;
        }

        // Wildcards are stripped so wildcard-only input cannot silently match
        // every record. Each token must match at least one searchable field.
        $tokens = collect(preg_split('/\s+/', $search) ?: [])
            ->map(fn (string $token) => str_replace(['%', '_'], '', $token))
            ->filter(fn (string $token) => $token !== '')
            ->values();

        // Both branches always produce the full common column shape so the
        // union stays valid; a missing barangay id simply matches nothing.
        $requestRows = $this->allRequestBranch($barangayId, $search, $tokens, $typeIsTemplate, $typeCertificateId, $requestedType, $dateFromValue, $dateToValue);
        $issuanceRows = $this->allIssuanceBranch($barangayId, $search, $tokens, $typeIsTemplate, $typeCertificateId, $requestedType, $dateFromValue, $dateToValue);

        // Wrap the union as a subquery before ordering and pagination. The
        // outer query orders newest-first with a stable tiebreak on record type
        // and id so pagination pages never overlap.
        $union = $requestRows->unionAll($issuanceRows);

        $rows = DB::query()
            ->fromSub($union, 'all_records')
            ->orderByDesc('occurred_at')
            ->orderBy('record_type')
            ->orderByDesc('source_id')
            ->paginate($perPage, ['*'], 'all_page')
            ->withQueryString()
            ->through(function ($row) {
                // Request rows carry their structured name parts; issuance rows
                // carry the name snapshot taken at issuance time.
                $residentName = $row->record_type === 'issuance'
                    ? $row->resident_name_snapshot
                    : collect([
                        $row->resident_first,
                        $row->resident_middle,
                        $row->resident_last,
                        $row->resident_suffix,
                    ])->filter()->implode(' ');

                $occurredAt = $row->occurred_at
                    ? Carbon::parse($row->occurred_at)->format('F j, Y')
                    : '';

                return [
                    'record_type' => $row->record_type,
                    'id' => (int) $row->source_id,
                    'reference' => $row->reference,
                    'certificate' => $row->certificate,
                    'resident' => [
                        'name' => $residentName !== '' ? $residentName : 'Unknown resident',
                        'resident_number' => $row->resident_number,
                    ],
                    'purok' => $row->purok,
                    // Issuance rows carry no request status; the UI renders a
                    // neutral "Issued" state for them.
                    'status' => $row->status,
                    'occurred_at' => $occurredAt,
                ];
            });

        return [$rows, [
            'search' => $search,
            'type' => $typeIsTemplate
                ? $requestedType
                : ($typeCertificateId !== null && $typeCertificateId > 0 ? (string) $typeCertificateId : ''),
            'date_from' => $dateFromValue?->format('Y-m-d') ?? '',
            'date_to' => $dateToValue?->format('Y-m-d') ?? '',
            'per_page' => $perPage,
        ]];
    }

    /**
     * Request branch of the All Records union, normalised to the common shape.
     *
     * @param  Collection<int, string>  $tokens
     */
    private function allRequestBranch(
        ?int $barangayId,
        string $search,
        $tokens,
        bool $typeIsTemplate,
        ?int $typeCertificateId,
        string $requestedType,
        ?Carbon $dateFromValue,
        ?Carbon $dateToValue,
    ): Builder {
        $query = DB::table('certificate_requests as r')
            ->join('residents as res', 'res.id', '=', 'r.resident_id')
            ->leftJoin('certificates as c', 'c.id', '=', 'r.certificate_id');

        if ($barangayId === null) {
            $query->whereRaw('1 = 0');
        } else {
            $query->where('r.barangay_id', $barangayId);
        }

        if ($search !== '') {
            if ($tokens->isEmpty()) {
                $query->whereRaw('1 = 0');
            } else {
                foreach ($tokens as $token) {
                    $term = '%'.$token.'%';

                    $query->where(function ($group) use ($term) {
                        $group->where('r.reference', 'like', $term)
                            ->orWhere('res.first_name', 'like', $term)
                            ->orWhere('res.middle_name', 'like', $term)
                            ->orWhere('res.last_name', 'like', $term)
                            ->orWhere('res.suffix', 'like', $term)
                            ->orWhere('res.resident_number', 'like', $term);
                    });
                }
            }
        }

        if ($typeIsTemplate) {
            $query->where('c.name', $requestedType);
        } elseif ($typeCertificateId !== null) {
            $query->where('r.certificate_id', $typeCertificateId);
        }

        if ($dateFromValue !== null) {
            $query->where('r.submitted_at', '>=', $dateFromValue);
        }

        if ($dateToValue !== null) {
            $query->where('r.submitted_at', '<=', $dateToValue->copy()->endOfDay());
        }

        return $query->selectRaw(
            "'request' as record_type,"
            .' r.id as source_id,'
            .' r.reference as reference,'
            ." COALESCE(c.name, 'Certificate') as certificate,"
            .' res.first_name as resident_first,'
            .' res.middle_name as resident_middle,'
            .' res.last_name as resident_last,'
            .' res.suffix as resident_suffix,'
            .' NULL as resident_name_snapshot,'
            .' res.resident_number as resident_number,'
            .' NULL as purok,'
            .' r.status as status,'
            .' r.submitted_at as occurred_at'
        );
    }

    /**
     * Issuance branch of the All Records union, normalised to the common shape.
     *
     * @param  Collection<int, string>  $tokens
     */
    private function allIssuanceBranch(
        ?int $barangayId,
        string $search,
        $tokens,
        bool $typeIsTemplate,
        ?int $typeCertificateId,
        string $requestedType,
        ?Carbon $dateFromValue,
        ?Carbon $dateToValue,
    ): Builder {
        $query = DB::table('certificate_issuances as i');

        if ($barangayId === null) {
            $query->whereRaw('1 = 0');
        } else {
            $query->where('i.barangay_id', $barangayId);
        }

        if ($search !== '') {
            if ($tokens->isEmpty()) {
                $query->whereRaw('1 = 0');
            } else {
                foreach ($tokens as $token) {
                    $term = '%'.$token.'%';

                    $query->where(function ($group) use ($term) {
                        $group->where('i.reference', 'like', $term)
                            ->orWhere('i.resident_name', 'like', $term)
                            ->orWhere('i.resident_number', 'like', $term);
                    });
                }
            }
        }

        // A template type matches the issuance snapshot directly. A catalog id
        // has no meaning for issuances (they store a type label, not a catalog
        // id), so it matches nothing on this branch.
        if ($typeIsTemplate) {
            $query->where('i.certificate_type', $requestedType);
        } elseif ($typeCertificateId !== null) {
            $query->whereRaw('1 = 0');
        }

        if ($dateFromValue !== null) {
            $query->where('i.issued_at', '>=', $dateFromValue);
        }

        if ($dateToValue !== null) {
            $query->where('i.issued_at', '<=', $dateToValue->copy()->endOfDay());
        }

        return $query->selectRaw(
            "'issuance' as record_type,"
            .' i.id as source_id,'
            .' i.reference as reference,'
            .' i.certificate_type as certificate,'
            .' NULL as resident_first,'
            .' NULL as resident_middle,'
            .' NULL as resident_last,'
            .' NULL as resident_suffix,'
            .' i.resident_name as resident_name_snapshot,'
            .' i.resident_number as resident_number,'
            .' i.purok as purok,'
            .' NULL as status,'
            .' i.issued_at as occurred_at'
        );
    }

    /**
     * Parse a Y-m-d date string, returning null for empty or malformed input.
     */
    private function parseDate(string $value): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            return null;
        }

        return Carbon::instance($parsed)->startOfDay();
    }
}
