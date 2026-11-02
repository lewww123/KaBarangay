<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CertificateRequest;
use App\Models\Purok;
use App\Models\Resident;
use App\Models\Sector;
use App\Models\SectorApplication;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class SectorController extends Controller
{
    /**
     * Rows rendered per page for the General, PWD, and Senior member rosters.
     */
    private const MEMBERS_PER_PAGE = 15;

    /**
     * Independent pagination query parameters, so one list can change page
     * without disturbing the others.
     */
    private const GENERAL_PAGE_PARAM = 'general_page';

    private const PWD_PAGE_PARAM = 'pwd_page';

    private const SENIOR_PAGE_PARAM = 'senior_page';

    /**
     * Rows rendered per page for the read-only Certificates monitoring list.
     */
    private const CERTIFICATES_PER_PAGE = 15;

    /**
     * Dedicated pagination query parameter for the Certificates list.
     */
    private const CERTIFICATE_PAGE_PARAM = 'cert_page';

    /**
     * Terminal statuses for certificate requests monitoring.
     */
    private const CERTIFICATE_MONITORING_STATUSES = ['Released', 'Rejected'];

    /**
     * Display the consolidated Sectors dashboard and member rosters.
     */
    public function index(Request $request): Response
    {
        $barangayId = (int) $request->user()->barangay_id;
        $filters = $this->resolveMemberFilters($request);
        $certificateFilters = $this->resolveCertificateFilters($request);

        $requestedTab = (string) $request->query('tab', '');
        $allowedTabs = ['applications', 'general', 'pwd', 'senior', 'certificates', 'lupon'];
        $initialTab = in_array($requestedTab, $allowedTabs, true) ? $requestedTab : 'general';

        $payload = null;

        $resolvePayload = function () use (
            &$payload,
            $barangayId,
            $filters,
            $certificateFilters,
        ): array {
            if ($payload !== null) {
                return $payload;
            }

            $tableListing = [];

            try {
                $tableListing = Schema::getTableListing(null, false);
            } catch (\Throwable) {
                $tableListing = [];
            }

            $hasSectorApplications = in_array('sector_applications', $tableListing, true);
            $hasLuponCases = in_array('lupon_cases', $tableListing, true);

            $stats = (object) $this->rememberAggregate(
                'admin:sectors:stats:v2:'.$barangayId.':'.md5(json_encode([
                    'search' => $filters['search'],
                    'purok' => $filters['purok'],
                    'certificate_search' => $certificateFilters['search'],
                    'sector_applications' => $hasSectorApplications,
                    'lupon_cases' => $hasLuponCases,
                ])),
                30,
                fn () => $this->sectorStatsQuery(
                    $barangayId,
                    $filters,
                    $certificateFilters,
                    $hasSectorApplications,
                    $hasLuponCases,
                ),
            );

            $sectors = ($this->resolvesProp('Admin/Sectors', 'sectors') || $this->resolvesProp('Admin/Sectors', 'stats')) ? Sector::query()
                ->withCount([
                    'residents',
                    'registrationSectors',
                    'registrationDocuments',
                ])
                ->orderBy('name')
                ->get()
                ->map(fn (Sector $sector) => [
                    'id' => $sector->id,
                    'name' => $sector->name,
                    'slug' => $sector->slug,
                    'requires_proof' => $sector->requires_proof,
                    'is_active' => $sector->is_active,
                    'residents_count' => $sector->residents_count,
                    'registration_sectors_count' => $sector->registration_sectors_count,
                    'registration_documents_count' => $sector->registration_documents_count,
                ])
                ->values() : collect();

            $puroks = Purok::hydrate($this->rememberReference('sector:purok-options:'.$barangayId, 300, fn () => Purok::query()
                ->where('barangay_id', $barangayId)
                ->orderBy('name')
                ->get(['id', 'name'])->toArray()));

            $purokMap = $puroks->keyBy('id');

            $generalTotal = (int) ($stats->general_members ?? 0);
            $pwdTotal = (int) ($stats->pwd_members ?? 0);
            $seniorTotal = (int) ($stats->senior_members ?? 0);
            $processedCertificates = (int) ($stats->processed_certificates ?? 0);

            $generalMembers = $this->resolvesProp('Admin/Sectors', 'generalMembers') ? $this->generalMemberPage(
                $barangayId,
                self::GENERAL_PAGE_PARAM,
                $filters,
                (int) ($stats->filtered_general_members ?? $generalTotal),
                $purokMap,
            ) : null;
            $pwdMembers = $this->resolvesProp('Admin/Sectors', 'pwdMembers') ? $this->memberPage(
                $barangayId,
                'pwd',
                self::PWD_PAGE_PARAM,
                $filters,
                (int) ($stats->filtered_pwd_members ?? $pwdTotal),
                $purokMap,
            ) : null;
            $seniorMembers = $this->resolvesProp('Admin/Sectors', 'seniorMembers') ? $this->memberPage(
                $barangayId,
                'senior',
                self::SENIOR_PAGE_PARAM,
                $filters,
                (int) ($stats->filtered_senior_members ?? $seniorTotal),
                $purokMap,
            ) : null;

            $certificateRequests = $this->resolvesProp('Admin/Sectors', 'certificateRequests') ? $this->certificateRequestPage(
                $barangayId,
                $certificateFilters,
                (int) ($stats->filtered_certificate_requests ?? $processedCertificates),
            ) : null;

            $pendingSectorApplications = (int) ($stats->pending_sector_applications ?? 0);
            $openCases = (int) ($stats->lupon_open ?? 0);
            $closedCases = (int) ($stats->lupon_closed ?? 0);
            $totalCases = (int) ($stats->lupon_total ?? 0);

            return $payload = [
                'sectors' => $sectors,
                'stats' => [
                    'total' => $sectors->count(),
                    'active' => $sectors->where('is_active', true)->count(),
                    'sectorApplications' => $pendingSectorApplications,
                    'generalMembers' => $generalTotal,
                    'pwdMembers' => $pwdTotal,
                    'seniorMembers' => $seniorTotal,
                    'certificates' => $processedCertificates,
                    'openCases' => $openCases,
                ],
                'generalMembers' => $generalMembers,
                'pwdMembers' => $pwdMembers,
                'seniorMembers' => $seniorMembers,
                'puroks' => $puroks,
                'certificates' => [
                    'processed' => $processedCertificates,
                    'completed' => $processedCertificates,
                ],
                'certificateRequests' => $certificateRequests,
                'luponSummary' => [
                    'open' => $openCases,
                    'closed' => $closedCases,
                    'total' => $totalCases,
                ],
            ];
        };

        return Inertia::render('Admin/Sectors', [
            'sectors' => $this->deferProp(fn () => $resolvePayload()['sectors']),
            'stats' => $this->deferProp(fn () => $resolvePayload()['stats']),
            'generalMembers' => $this->deferProp(fn () => $resolvePayload()['generalMembers']),
            'pwdMembers' => $this->deferProp(fn () => $resolvePayload()['pwdMembers']),
            'seniorMembers' => $this->deferProp(fn () => $resolvePayload()['seniorMembers']),
            'puroks' => $this->deferProp(fn () => $resolvePayload()['puroks']),
            'filters' => [
                'search' => $filters['search'],
                'purok' => $filters['purok'],
            ],
            'certificates' => $this->deferProp(fn () => $resolvePayload()['certificates']),
            'certificateRequests' => $this->deferProp(fn () => $resolvePayload()['certificateRequests']),
            'certificateFilters' => [
                'search' => $certificateFilters['search'],
            ],
            'luponSummary' => $this->deferProp(fn () => $resolvePayload()['luponSummary']),
            'initialTab' => $initialTab,
        ]);
    }

    /**
     * Build every summary count in one database round trip.
     *
     * The filtered roster counts are only added when a filter is active; when
     * they are absent the paginator reuses the unfiltered totals. This keeps
     * the single query from calculating the same aggregate twice.
     */
    private function sectorStatsQuery(
        int $barangayId,
        array $filters,
        array $certificateFilters,
        bool $hasSectorApplications,
        bool $hasLuponCases,
    ): array {
        $stats = DB::query()
            ->selectSub(
                $this->generalMemberScope($barangayId)->selectRaw('COUNT(*)')->toBase(),
                'general_members',
            )
            ->selectSub(
                $this->memberScope($barangayId, 'pwd')->selectRaw('COUNT(*)')->toBase(),
                'pwd_members',
            )
            ->selectSub(
                $this->memberScope($barangayId, 'senior')->selectRaw('COUNT(*)')->toBase(),
                'senior_members',
            )
            ->selectSub(
                $this->certificateRequestScope($barangayId)->selectRaw('COUNT(*)')->toBase(),
                'processed_certificates',
            );

        if ($hasSectorApplications) {
            $stats->selectSub(
                SectorApplication::query()
                    ->where('barangay_id', $barangayId)
                    ->where('status', 'under_admin_review')
                    ->selectRaw('COUNT(*)')
                    ->toBase(),
                'pending_sector_applications',
            );
        }

        if ($hasLuponCases) {
            $stats
                ->selectSub(
                    DB::table('lupon_cases')
                        ->where('barangay_id', $barangayId)
                        ->selectRaw('COUNT(*)'),
                    'lupon_total',
                )
                ->selectSub(
                    DB::table('lupon_cases')
                        ->where('barangay_id', $barangayId)
                        ->selectRaw(
                            'SUM(CASE WHEN status IN (?, ?, ?) THEN 1 ELSE 0 END)',
                            ['Active', 'Under Mediation', 'Under Conciliation'],
                        ),
                    'lupon_open',
                )
                ->selectSub(
                    DB::table('lupon_cases')
                        ->where('barangay_id', $barangayId)
                        ->selectRaw(
                            'SUM(CASE WHEN status IN (?, ?, ?) THEN 1 ELSE 0 END)',
                            ['Settled', 'Unresolved', 'Dismissed'],
                        ),
                    'lupon_closed',
                );
        }

        if ($filters['search'] !== '' || $filters['purok_id'] !== null) {
            $generalFiltered = $this->generalMemberScope($barangayId);
            $this->applyMemberFilters($generalFiltered, $filters, $barangayId);

            $pwdFiltered = $this->memberScope($barangayId, 'pwd');
            $this->applyMemberFilters($pwdFiltered, $filters, $barangayId);

            $seniorFiltered = $this->memberScope($barangayId, 'senior');
            $this->applyMemberFilters($seniorFiltered, $filters, $barangayId);

            $stats
                ->selectSub($generalFiltered->selectRaw('COUNT(*)')->toBase(), 'filtered_general_members')
                ->selectSub($pwdFiltered->selectRaw('COUNT(*)')->toBase(), 'filtered_pwd_members')
                ->selectSub($seniorFiltered->selectRaw('COUNT(*)')->toBase(), 'filtered_senior_members');
        }

        if ($certificateFilters['search'] !== '') {
            $certificatesFiltered = $this->certificateRequestScope($barangayId);
            $this->applyCertificateFilters($certificatesFiltered, $certificateFilters);

            $stats->selectSub(
                $certificatesFiltered->selectRaw('COUNT(*)')->toBase(),
                'filtered_certificate_requests',
            );
        }

        return (array) ($stats->first() ?? []);
    }

    /*
    |--------------------------------------------------------------------------
    | Member roster query helpers
    |--------------------------------------------------------------------------
    |
    | Shared by the General, PWD, and Senior rosters so search, purok filtering,
    | ordering, and pagination behave identically across all member lists.
    |
    */

    /**
     * The base scope for the general (regular) resident roster.
     *
     * Scoped to the authenticated admin's barangay and residents who do not belong
     * to any sector (whereDoesntHave('sectors')).
     */
    private function generalMemberScope(int $barangayId): Builder
    {
        return Resident::query()
            ->where('barangay_id', $barangayId)
            ->whereDoesntHave('sectors');
    }

    /**
     * Build one page of the general resident roster.
     */
    private function generalMemberPage(
        int $barangayId,
        string $pageParam,
        array $filters,
        int $total,
        Collection &$puroks,
    ): LengthAwarePaginator {
        $query = $this->generalMemberScope($barangayId);

        $this->applyMemberFilters($query, $filters, $barangayId);

        $paginator = $query
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->paginate(self::MEMBERS_PER_PAGE, ['*'], $pageParam, null, $total)
            ->withQueryString();

        $this->hydratePuroks($paginator->getCollection(), $puroks);

        return $paginator->through(fn (Resident $resident) => [
            'id' => $resident->id,
            'resident_number' => $resident->resident_number,
            'name' => $resident->full_name,
            'purok' => $resident->purok?->name ?? '',
            'status' => $resident->status,
        ]);
    }

    /**
     * The base scope for a sector membership roster.
     *
     * Always constrained to the authenticated admin's barangay. Resident
     * status is deliberately NOT constrained here: both active and inactive
     * members appear in their sector's roster.
     */
    private function memberScope(int $barangayId, string $slug): Builder
    {
        return Resident::query()
            ->where('barangay_id', $barangayId)
            ->whereHas('sectors', fn (Builder $query) => $query->where('slug', $slug));
    }

    /**
     * Build one page of a sector membership roster.
     *
     * Ordering is `last_name`, `first_name`, then `id` as a deterministic
     * tie-breaker, which keeps page boundaries stable for residents who share
     * the same name. `withQueryString()` carries the shared search and purok
     * filters, plus other rosters' page parameters, onto every
     * generated pagination link.
     */
    private function memberPage(
        int $barangayId,
        string $slug,
        string $pageParam,
        array $filters,
        int $total,
        Collection &$puroks,
    ): LengthAwarePaginator {
        $query = $this->memberScope($barangayId, $slug);

        $this->applyMemberFilters($query, $filters, $barangayId);

        $paginator = $query
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->paginate(self::MEMBERS_PER_PAGE, ['*'], $pageParam, null, $total)
            ->withQueryString();

        $this->hydratePuroks($paginator->getCollection(), $puroks);

        return $paginator->through(fn (Resident $resident) => [
            'id' => $resident->id,
            'resident_number' => $resident->resident_number,
            'name' => $resident->full_name,
            'purok' => $resident->purok?->name ?? '',
            'status' => $resident->status,
        ]);
    }

    /**
     * Normalise the shared `search` and `purok` query parameters.
     *
     * `search` is trimmed and split into whitespace-separated tokens.
     * `purok` must be a positive integer literal; anything else is treated as
     * "no purok filter" so malformed input can never widen or narrow a roster
     * unpredictably.
     */
    private function resolveMemberFilters(Request $request): array
    {
        $rawSearch = $request->query('search');
        $search = is_string($rawSearch) ? trim($rawSearch) : '';

        $tokens = [];

        if ($search !== '') {
            foreach (preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                // Strip LIKE metacharacters instead of escaping them: MySQL and
                // SQLite disagree on how to escape a LIKE pattern, so removal is
                // the only approach that behaves identically on both backends.
                $token = str_replace(['%', '_'], '', $token);

                if ($token !== '') {
                    $tokens[] = $token;
                }
            }
        }

        $rawPurok = $request->query('purok');
        $purok = is_string($rawPurok) && preg_match('/^\d+$/', $rawPurok) === 1
            ? $rawPurok
            : '';

        return [
            'search' => $search,
            'purok' => $purok,
            'tokens' => $tokens,
            'purok_id' => $purok === '' ? null : (int) $purok,
        ];
    }

    /**
     * Apply the shared search and purok filters to a roster query.
     *
     * Search tokens are ANDed: every token must match at least one searchable
     * column, so a multi-word query such as `Ana Reyes` matches a resident
     * whose first name is Ana and last name is Reyes rather than either token
     * alone. Searchable columns are the resident number, every component of
     * the resident's name, and the purok name.
     *
     * The purok filter is resolved through the `purok` relation constrained to
     * the admin's own barangay, so a purok belonging to another barangay can
     * never be used to reach another barangay's residents.
     */
    private function applyMemberFilters(Builder $query, array $filters, int $barangayId): void
    {
        if ($filters['search'] !== '' && $filters['tokens'] === []) {
            /*
            | The submitted search was made up entirely of LIKE metacharacters,
            | which are stripped before matching. Nothing can match a search
            | like that, so the roster must come back empty rather than quietly
            | falling back to the unfiltered list.
            */
            $query->whereRaw('1 = 0');

            return;
        }

        foreach ($filters['tokens'] as $token) {
            $like = '%'.$token.'%';

            $query->where(function (Builder $nested) use ($like) {
                $nested
                    ->where('first_name', 'like', $like)
                    ->orWhere('middle_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('suffix', 'like', $like)
                    ->orWhere('resident_number', 'like', $like)
                    ->orWhereHas(
                        'purok',
                        fn (Builder $purokQuery) => $purokQuery->where('name', 'like', $like),
                    );
            });
        }

        if ($filters['purok_id'] !== null) {
            $query->whereHas('purok', function (Builder $purokQuery) use ($filters, $barangayId) {
                $purokQuery
                    ->where('barangay_id', $barangayId)
                    ->where('id', $filters['purok_id']);
            });
        }
    }

    /**
     * Load purok relations from the already-loaded barangay puroks. Puroks
     * outside that list are fetched once, preserving the original relation
     * behavior even if legacy data points across barangays.
     *
     * @param  Collection<int, Resident>  $records
     * @param  Collection<int, Purok>  $puroks
     */
    private function hydratePuroks(Collection $records, Collection &$puroks): void
    {
        if ($records->isEmpty()) {
            return;
        }

        $missingIds = $records->pluck('purok_id')
            ->filter()
            ->unique()
            ->reject(fn ($id) => $puroks->has($id))
            ->values();

        if ($missingIds->isNotEmpty()) {
            $puroks = $puroks->union(
                Purok::query()
                    ->whereIn('id', $missingIds)
                    ->get(['id', 'name'])
                    ->keyBy('id')
            );
        }

        foreach ($records as $record) {
            $record->setRelation('purok', $puroks->get($record->purok_id));
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Certificate monitoring query helpers
    |--------------------------------------------------------------------------
    |
    | A single read-only list of terminal certificate requests. The
    | Released/Rejected restriction is applied to the query itself, so search
    | and pagination can never surface any other status.
    |
    */

    /**
     * Normalise the `cert_search` query parameter for the Certificates list.
     */
    private function resolveCertificateFilters(Request $request): array
    {
        $rawSearch = $request->query('cert_search');
        $search = is_string($rawSearch) ? trim($rawSearch) : '';

        $tokens = [];

        if ($search !== '') {
            foreach (preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                $token = str_replace(['%', '_'], '', $token);

                if ($token !== '') {
                    $tokens[] = $token;
                }
            }
        }

        return [
            'search' => $search,
            'tokens' => $tokens,
        ];
    }

    /**
     * The base scope for the read-only Certificates monitoring list.
     */
    private function certificateRequestScope(int $barangayId): Builder
    {
        return CertificateRequest::query()
            ->where('barangay_id', $barangayId)
            ->whereIn('status', self::CERTIFICATE_MONITORING_STATUSES);
    }

    /**
     * Apply the Certificates list search without allowing the status
     * restriction to be bypassed.
     */
    private function applyCertificateFilters(Builder $query, array $filters): void
    {
        if ($filters['search'] !== '' && $filters['tokens'] === []) {
            $query->whereRaw('1 = 0');
        }

        foreach ($filters['tokens'] as $token) {
            $like = '%'.$token.'%';

            $query->where(function (Builder $nested) use ($like) {
                $nested
                    ->where('reference', 'like', $like)
                    ->orWhere('purpose', 'like', $like)
                    ->orWhereHas(
                        'certificate',
                        fn (Builder $certificateQuery) => $certificateQuery->where('name', 'like', $like),
                    )
                    ->orWhereHas('resident', function (Builder $residentQuery) use ($like) {
                        $residentQuery
                            ->where('first_name', 'like', $like)
                            ->orWhere('middle_name', 'like', $like)
                            ->orWhere('last_name', 'like', $like)
                            ->orWhere('suffix', 'like', $like)
                            ->orWhere('resident_number', 'like', $like);
                    });
            });
        }
    }

    /**
     * Build one page of the read-only Certificates monitoring list.
     */
    private function certificateRequestPage(
        int $barangayId,
        array $filters,
        int $total,
    ): LengthAwarePaginator {
        $query = $this->certificateRequestScope($barangayId);

        $this->applyCertificateFilters($query, $filters);

        return $query
            ->with([
                'resident:id,first_name,middle_name,last_name,suffix,resident_number',
                'certificate:id,name',
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(self::CERTIFICATES_PER_PAGE, ['*'], self::CERTIFICATE_PAGE_PARAM, null, $total)
            ->withQueryString()
            ->through(fn (CertificateRequest $certificateRequest) => [
                'id' => $certificateRequest->id,
                'reference' => $certificateRequest->reference,
                'certificate' => $certificateRequest->certificate?->name ?? 'Certificate',
                'resident_name' => $certificateRequest->resident?->full_name ?? 'Unknown resident',
                'resident_number' => $certificateRequest->resident?->resident_number,
                'purpose' => $certificateRequest->purpose,
                'fee' => (float) $certificateRequest->fee,
                'status' => $certificateRequest->status,
                'submitted_at' => $certificateRequest->submitted_at?->format('M j, Y') ?? '',
                'status_at' => ($certificateRequest->released_at ?? $certificateRequest->rejected_at)?->format('M j, Y') ?? '',
            ]);
    }
}
