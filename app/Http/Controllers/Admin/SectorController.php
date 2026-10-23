<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CertificateRequest;
use App\Models\Purok;
use App\Models\Resident;
use App\Models\Sector;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SectorController extends Controller
{
    /**
     * Rows rendered per page for the PWD Members and Senior Citizens lists.
     */
    private const MEMBERS_PER_PAGE = 15;

    /**
     * Independent pagination query parameters, so one list can change page
     * without disturbing the other.
     */
    private const PWD_PAGE_PARAM = 'pwd_page';

    private const SENIOR_PAGE_PARAM = 'senior_page';

    /**
     * Rows rendered per page for the read-only Certificates monitoring list.
     */
    private const CERTIFICATES_PER_PAGE = 15;

    /**
     * Dedicated pagination query parameter for the Certificates list so it can
     * page independently of the PWD and Senior rosters.
     */
    private const CERTIFICATE_PAGE_PARAM = 'cert_page';

    /**
     * The only certificate request statuses an Admin may see under Sectors →
     * Certificates. Per the approved monitoring definition these are the
     * terminal statuses that are Released or Rejected. The allow-list is
     * applied in the query builder, so the restriction holds on the backend
     * regardless of the UI, search, or pagination inputs.
     */
    private const CERTIFICATE_MONITORING_STATUSES = ['Released', 'Rejected'];

    /**
     * List every sector with its dependent-record counts.
     *
     * Sectors are global reference data: the `sectors` table has no
     * `barangay_id`, so the listing is intentionally not barangay-scoped.
     */
    public function index(Request $request): Response
    {
        $sectors = Sector::query()
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
            ->values();

        /*
        |--------------------------------------------------------------------------
        | PWD Members and Senior Citizens (Barangay-Scoped, Read-Only)
        |--------------------------------------------------------------------------
        |
        | Both rosters derive from the verified `resident_sector` pivot where the
        | linked sector slug is the canonical `pwd` or `senior`. Both are
        | restricted to the authenticated admin's barangay, both are paginated
        | server-side on separate query parameters, and both share the same
        | search and purok filter inputs.
        |
        | Active and inactive residents are both listed: these are membership
        | rosters, not an approval queue, so a resident's account status is
        | reported rather than used to hide them from their own sector list.
        |
        | The aggregate counts below are the unfiltered totals for each roster,
        | so the headline figure always matches the full membership even while
        | a search or filter is narrowing the visible page.
        |
        */

        $barangayId = (int) $request->user()->barangay_id;
        $filters = $this->resolveMemberFilters($request);

        $pwdMembers = $this->memberPage($barangayId, 'pwd', self::PWD_PAGE_PARAM, $filters);
        $seniorMembers = $this->memberPage($barangayId, 'senior', self::SENIOR_PAGE_PARAM, $filters);

        $puroks = Purok::query()
            ->where('barangay_id', $barangayId)
            ->orderBy('name')
            ->get(['id', 'name']);

        /*
        |--------------------------------------------------------------------------
        | Certificates (Barangay-Scoped, Read-Only Monitoring)
        |--------------------------------------------------------------------------
        |
        | Per the approved definitions, both the Processed and Completed metrics
        | count individual certificate requests whose current status is
        | terminal, i.e. `Released` or `Rejected` (the canonical terminal
        | statuses defined in Admin\CertificateRequestController). The count is
        | all-time and is scoped to the authenticated admin's barangay.
        |
        | The same allow-list drives the read-only list below, which surfaces the
        | individual terminal requests (never any other status) with search and
        | pagination. The restriction is enforced in the query builder, not only
        | by hiding rows in the UI. Certificate processing itself remains in the
        | existing certificate workflow; this controller never writes request
        | records and the tab exposes no write actions.
        |
        | The two approved definitions currently resolve to the same number, so
        | a single aggregate serves both and the UI states that explicitly
        | rather than presenting two identical figures as distinct measures.
        |
        */

        $certificateFilters = $this->resolveCertificateFilters($request);
        $certificateRequests = $this->certificateRequestPage($barangayId, $certificateFilters);

        $processedCertificates = CertificateRequest::query()
            ->where('barangay_id', $barangayId)
            ->whereIn('status', self::CERTIFICATE_MONITORING_STATUSES)
            ->count();

        return Inertia::render('Admin/Sectors', [
            'sectors' => $sectors,
            'stats' => [
                'total' => $sectors->count(),
                'active' => $sectors->where('is_active', true)->count(),
                'pwdMembers' => $this->memberScope($barangayId, 'pwd')->count(),
                'seniorMembers' => $this->memberScope($barangayId, 'senior')->count(),
            ],
            'pwdMembers' => $pwdMembers,
            'seniorMembers' => $seniorMembers,
            'puroks' => $puroks,
            'filters' => [
                'search' => $filters['search'],
                'purok' => $filters['purok'],
            ],
            'certificates' => [
                'processed' => $processedCertificates,
                'completed' => $processedCertificates,
            ],
            'certificateRequests' => $certificateRequests,
            'certificateFilters' => [
                'search' => $certificateFilters['search'],
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('sectors', 'slug'),
            ],
            'requires_proof' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        $slug = $validated['slug'] ?? Str::slug($validated['name']);

        if ($slug === '' || Sector::query()->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages([
                'slug' => 'A unique slug could not be derived from this name; please provide one.',
            ]);
        }

        Sector::create([
            'name' => $validated['name'],
            'slug' => $slug,
            'requires_proof' => $validated['requires_proof'] ?? true,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return back()->with('success', 'Sector created successfully.');
    }

    /**
     * Update a sector's mutable attributes.
     *
     * `slug` is deliberately immutable after creation: the resident
     * registration workflow resolves the Senior and PWD sectors by the
     * canonical slugs `senior` and `pwd`, so renaming a slug could silently
     * disable sector selection for residents.
     */
    public function update(Request $request, Sector $sector): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'requires_proof' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        $sector->update([
            'name' => $validated['name'],
            'requires_proof' => $validated['requires_proof'] ?? $sector->requires_proof,
            'is_active' => $validated['is_active'] ?? $sector->is_active,
        ]);

        return back()->with('success', 'Sector updated successfully.');
    }

    /*
    |--------------------------------------------------------------------------
    | Member roster query helpers
    |--------------------------------------------------------------------------
    |
    | Shared by the PWD and Senior rosters so search, purok filtering,
    | ordering, and pagination behave identically for both lists.
    |
    */

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
     * filters, plus the other roster's page parameter, onto every
     * generated pagination link.
     */
    private function memberPage(
        int $barangayId,
        string $slug,
        string $pageParam,
        array $filters,
    ): LengthAwarePaginator {
        $query = $this->memberScope($barangayId, $slug);

        $this->applyMemberFilters($query, $filters, $barangayId);

        return $query
            ->with('purok:id,name')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->paginate(self::MEMBERS_PER_PAGE, ['*'], $pageParam)
            ->withQueryString()
            ->through(fn (Resident $resident) => [
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
     *
     * Mirrors the member roster search: the value is trimmed and split into
     * whitespace-separated tokens, and LIKE metacharacters are stripped so
     * wildcard-only input cannot silently match every request.
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
     * Build one page of the read-only Certificates monitoring list.
     *
     * The base scope is always the authenticated admin's barangay AND a status
     * in the Released/Rejected allow-list. Search tokens are ANDed and wrapped
     * in their own grouped clause so their OR conditions can never widen the
     * status or barangay restriction. Ordering is `updated_at` then `id` so the
     * most recently processed request appears first and page boundaries stay
     * stable.
     */
    private function certificateRequestPage(
        int $barangayId,
        array $filters,
    ): LengthAwarePaginator {
        $query = CertificateRequest::query()
            ->where('barangay_id', $barangayId)
            ->whereIn('status', self::CERTIFICATE_MONITORING_STATUSES);

        /*
        | The submitted search was made up entirely of LIKE metacharacters,
        | which are stripped before matching. Nothing can match a search like
        | that, so the list must come back empty rather than quietly falling
        | back to the unfiltered terminal list.
        */
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

        return $query
            ->with([
                'resident:id,first_name,middle_name,last_name,suffix,resident_number',
                'certificate:id,name',
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(self::CERTIFICATES_PER_PAGE, ['*'], self::CERTIFICATE_PAGE_PARAM)
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
