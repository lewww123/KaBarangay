<?php

namespace App\Http\Controllers\Clerk;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\CertificateIssuance;
use App\Models\CertificateRequest;
use App\Support\CertificateTemplates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Read-only certificate-request listing for the Clerk portal.
 *
 * The Clerk can view requests submitted by residents of their own barangay,
 * search and filter them, and page through the results. The Clerk cannot
 * change a request's status; certificate-request status transitions remain
 * Admin-only (see App\Http\Controllers\Admin\CertificateRequestController).
 */
class CertificateRequestController extends Controller
{
    /**
     * Canonical certificate request statuses, mirrored from the resident
     * tracker. Used only to validate the status filter; this controller never
     * writes a status.
     */
    private const STATUSES = [
        'Pending',
        'Needs Action',
        'For Payment',
        'Processing',
        'Ready for Claim',
        'Released',
        'Rejected',
    ];

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
        $statusFilter = in_array($requestedStatus, self::STATUSES, true)
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
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Certificate $certificate) => [
                'id' => $certificate->id,
                'name' => $certificate->name,
            ])
            ->all();

        [$issuances, $issuedFilters] = $this->issuanceListing($request, $barangayId);

        [$allRecords, $allFilters] = $this->allListing($request, $barangayId);

        return Inertia::render('Clerk/Certificates', [
            'requests' => $requests,
            'issuances' => $issuances,
            'allRecords' => $allRecords,
            'statuses' => self::STATUSES,
            'certificateOptions' => $certificateOptions,
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
     * Build the read-only issuance-history listing for the Issued tab.
     *
     * The Clerk can only ever see issuances recorded for their own barangay.
     * The scope is derived from the authenticated user; a client-supplied
     * barangay_id is never read. Only the fields needed for the table are
     * projected, and the issuer is reduced to a display name.
     *
     * @return array{0: \Illuminate\Contracts\Pagination\LengthAwarePaginator, 1: array<string, mixed>}
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
     * @return array{0: \Illuminate\Contracts\Pagination\LengthAwarePaginator, 1: array<string, mixed>}
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
                    ? \Illuminate\Support\Carbon::parse($row->occurred_at)->format('F j, Y')
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
     * @param  \Illuminate\Support\Collection<int, string>  $tokens
     */
    private function allRequestBranch(
        ?int $barangayId,
        string $search,
        $tokens,
        bool $typeIsTemplate,
        ?int $typeCertificateId,
        string $requestedType,
        ?\Illuminate\Support\Carbon $dateFromValue,
        ?\Illuminate\Support\Carbon $dateToValue,
    ): \Illuminate\Database\Query\Builder {
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
     * @param  \Illuminate\Support\Collection<int, string>  $tokens
     */
    private function allIssuanceBranch(
        ?int $barangayId,
        string $search,
        $tokens,
        bool $typeIsTemplate,
        ?int $typeCertificateId,
        string $requestedType,
        ?\Illuminate\Support\Carbon $dateFromValue,
        ?\Illuminate\Support\Carbon $dateToValue,
    ): \Illuminate\Database\Query\Builder {
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
    private function parseDate(string $value): ?\Illuminate\Support\Carbon
    {
        if ($value === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            return null;
        }

        return \Illuminate\Support\Carbon::instance($parsed)->startOfDay();
    }
}
