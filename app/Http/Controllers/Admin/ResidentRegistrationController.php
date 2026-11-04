<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RequestResidentRegistrationCorrectionRequest;
use App\Models\Household;
use App\Models\Purok;
use App\Models\RegistrationDocument;
use App\Models\Resident;
use App\Models\ResidentAccount;
use App\Models\ResidentNotification;
use App\Models\ResidentQrCredential;
use App\Models\ResidentRegistration;
use App\Models\ResidentRegistrationCorrectionCycle;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\ResidentRegistrationCorrectionPolicy;
use App\Support\ResidentRegistrationDecisionNotificationService;
use App\Support\ResidentRegistrationQrMatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResidentRegistrationController extends Controller
{
    /**
     * Resident and login-account statuses an administrator may set.
     */
    private const RESIDENT_STATUSES = [
        'active',
        'inactive',
    ];

    public function pendingAccounts(Request $request): JsonResponse
    {
        $barangayId = (int) $request->user()->barangay_id;
        $puroks = Purok::query()->where('barangay_id', $barangayId)
            ->get(['id', 'name'])->keyBy('id');
        $purokId = $this->resolvePurokFilter($request, $puroks->keys()->map(fn ($id) => (int) $id)->all());
        $search = trim((string) $request->query('registration_search', ''));
        $totalPending = ResidentRegistration::query()->where('barangay_id', $barangayId)
            ->where('status', 'pending')->count();
        $total = $this->registrationQuery($barangayId, $purokId, $search)->where('status', 'pending')->count();
        $page = max(1, min((int) $request->query('pending_page', 1), max(1, (int) ceil($total / 15))));
        $request->query->set('pending_page', $page);
        $pending = $this->registrationList($barangayId, $purokId, $search, 'pending', 'pending_page', $total, $puroks);
        // Links must navigate the admin page, not this JSON endpoint.
        $pending->withPath(route('admin.residents'));

        return response()->json([
            'pendingRegistrations' => $pending,
            'totalPending' => $totalPending,
            'updatedAt' => now()->toIso8601String(),
        ])->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function index(Request $request): Response
    {
        $user = $request->user();

        /*
        |--------------------------------------------------------------------------
        | Purok Filter (server-side)
        |--------------------------------------------------------------------------
        |
        | The selected purok is validated against the authenticated admin's
        | barangay. An unknown, non-numeric, or foreign purok id resolves to
        | All Puroks (null) so it can never expose another barangay's records.
        |
        */

        $purokOptions = $this->rememberReference(
            'admin:purok-options:v2:'.$user->barangay_id,
            300,
            fn () => Purok::query()
                ->where('barangay_id', $user->barangay_id)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->toArray(),
        );

        $purokModels = Purok::hydrate($purokOptions);

        $purokMap = $purokModels->keyBy('id');

        $selectedPurokId = $this->resolvePurokFilter(
            $request,
            $purokMap->keys()->map(fn ($id) => (int) $id)->all()
        );

        /*
        |--------------------------------------------------------------------------
        | Registration Search (server-side, shared by the review tabs)
        |--------------------------------------------------------------------------
        |
        | The Pending, Needs Correction, and Rejected tabs share one search box.
        | The keyword is applied on the server so those lists never load every
        | registration of the barangay into the browser. Each tab is paginated
        | with its own page parameter so their pagination states never conflict.
        |
        */

        /*
        |--------------------------------------------------------------------------
        | Permanent Resident Statistics
        |--------------------------------------------------------------------------
        */

        $residentSearch = trim(
            (string) $request->query('resident_search', '')
        );
        $registrationSearch = trim((string) $request->query('registration_search', ''));

        $payload = null;

        $resolvePayload = function () use (
            &$payload,
            $user,
            $selectedPurokId,
            $residentSearch,
            $registrationSearch,
            $purokMap,
            $purokModels,
        ): array {
            if ($payload !== null) {
                return $payload;
            }

            $statsQuery = DB::query()
                ->selectSub(
                    Resident::query()
                        ->where('barangay_id', $user->barangay_id)
                        ->approvedAccount()
                        ->selectRaw('COUNT(*)')
                        ->toBase(),
                    'total_residents',
                )
                ->selectSub(
                    Household::query()
                        ->where('barangay_id', $user->barangay_id)
                        ->selectRaw('COUNT(*)')
                        ->toBase(),
                    'total_households',
                )
                ->selectSub(
                    ResidentRegistration::query()
                        ->where('barangay_id', $user->barangay_id)
                        ->where('status', 'pending')
                        ->selectRaw('COUNT(*)')
                        ->toBase(),
                    'pending_registrations',
                )
                ->selectSub(
                    $this->registrationQuery(
                        (int) $user->barangay_id,
                        $selectedPurokId,
                        $registrationSearch,
                    )->where('status', 'pending')->selectRaw('COUNT(*)')->toBase(),
                    'pending_reviews',
                )
                ->selectSub(
                    $this->registrationQuery(
                        (int) $user->barangay_id,
                        $selectedPurokId,
                        $registrationSearch,
                    )->where('status', 'needs_correction')->selectRaw('COUNT(*)')->toBase(),
                    'needs_correction_reviews',
                )
                ->selectSub(
                    $this->registrationQuery(
                        (int) $user->barangay_id,
                        $selectedPurokId,
                        $registrationSearch,
                    )->where('status', 'rejected')->selectRaw('COUNT(*)')->toBase(),
                    'rejected_reviews',
                )
                ->selectSub(
                    $this->residentListQuery(
                        (int) $user->barangay_id,
                        $selectedPurokId,
                        $residentSearch,
                        'admin',
                    )->reorder()->select(DB::raw('COUNT(*)'))->toBase(),
                    'added_by_admin',
                )
                ->selectSub(
                    $this->allResidentsQuery(
                        (int) $user->barangay_id,
                        $selectedPurokId,
                        $residentSearch,
                    )->reorder()->select(DB::raw('COUNT(*)'))->toBase(),
                    'all_residents',
                );

            // Pagination skips fetching rows when its supplied total is zero.
            // Read current counts so a previously empty queue cannot hide new
            // applications or retain applications after an admin reviews them.
            $stats = (object) (array) ($statsQuery->first() ?? []);

            $totalResidents = (int) ($stats->total_residents ?? 0);
            $activeResidents = $totalResidents;

            $puroks = $this->resolvesProp('Admin/Residents', 'puroks')
                ? $this->purokSummaries($purokModels, (int) $user->barangay_id)
                : null;

            /*
            |--------------------------------------------------------------------------
            | Resident Directories
            |--------------------------------------------------------------------------
            |
            | The approved roster is paginated and searched on the server so the
            | page never loads every resident of the barangay into the browser.
            | The current query string is preserved on the pagination links.
            |
            */

            $addedByAdmin = $this->resolvesProp('Admin/Residents', 'addedByAdmin') ? $this->residentList(
                (int) $user->barangay_id,
                $selectedPurokId,
                $residentSearch,
                'admin',
                'added_page',
                (int) ($stats->added_by_admin ?? 0),
                $purokMap
            ) : null;

            $pendingRegistrations = $this->resolvesProp('Admin/Residents', 'pendingRegistrations') ? $this->registrationList(
                (int) $user->barangay_id,
                $selectedPurokId,
                $registrationSearch,
                'pending',
                'pending_page',
                (int) ($stats->pending_reviews ?? 0),
                $purokMap
            ) : null;
            $needsCorrectionRegistrations = $this->resolvesProp('Admin/Residents', 'needsCorrectionRegistrations') ? $this->registrationList(
                (int) $user->barangay_id,
                $selectedPurokId,
                $registrationSearch,
                'needs_correction',
                'needs_correction_page',
                (int) ($stats->needs_correction_reviews ?? 0),
                $purokMap
            ) : null;
            $rejectedRegistrations = $this->resolvesProp('Admin/Residents', 'rejectedRegistrations') ? $this->registrationList(
                (int) $user->barangay_id,
                $selectedPurokId,
                $registrationSearch,
                'rejected',
                'rejected_page',
                (int) ($stats->rejected_reviews ?? 0),
                $purokMap
            ) : null;

            /*
            |--------------------------------------------------------------------------
            | All Residents (verified records with linked online accounts)
            |--------------------------------------------------------------------------
            |
            | The directory intentionally excludes unverified records, applicants,
            | and official Residents without an online account.
            |
            */

            $allResidents = $this->resolvesProp('Admin/Residents', 'allResidents') ? $this->allResidents(
                (int) $user->barangay_id,
                $selectedPurokId,
                $residentSearch,
                (int) ($stats->all_residents ?? 0),
                $purokMap
            ) : null;
            $households = $this->resolvesProp('Admin/Residents', 'households')
                ? $this->households((int) $user->barangay_id, $selectedPurokId, $residentSearch)
                : null;

            return $payload = [
                'allResidents' => $allResidents,
                'pendingRegistrations' => $pendingRegistrations,
                'needsCorrectionRegistrations' => $needsCorrectionRegistrations,
                'rejectedRegistrations' => $rejectedRegistrations,
                'puroks' => $puroks,
                'addedByAdmin' => $addedByAdmin,
                'households' => $households,
                'stats' => [
                    'totalResidents' => $totalResidents,
                    'activeResidents' => $activeResidents,
                    'pendingRegistrations' => (int) ($stats->pending_registrations ?? 0),
                    'totalHouseholds' => (int) ($stats->total_households ?? 0),
                ],
            ];
        };

        return Inertia::render('Admin/Residents', [
            'allResidents' => $this->deferProp(fn () => $resolvePayload()['allResidents']),
            'pendingRegistrations' => $this->deferProp(fn () => $resolvePayload()['pendingRegistrations']),
            'needsCorrectionRegistrations' => $this->deferProp(fn () => $resolvePayload()['needsCorrectionRegistrations']),
            'rejectedRegistrations' => $this->deferProp(fn () => $resolvePayload()['rejectedRegistrations']),
            'puroks' => $this->deferProp(fn () => $resolvePayload()['puroks']),
            'addedByAdmin' => $this->deferProp(fn () => $resolvePayload()['addedByAdmin']),
            'households' => $this->deferProp(fn () => $resolvePayload()['households']),
            'filters' => [
                'resident_search' => $residentSearch,
                'registration_search' => $registrationSearch,
                'purok_id' => $selectedPurokId,
            ],
            'stats' => $this->deferProp(fn () => $resolvePayload()['stats']),
        ]);
    }

    /**
     * Build per-purok summaries only when the deferred purok prop is requested.
     *
     * @param  Collection<int, Purok>  $puroks
     */
    private function purokSummaries(Collection $puroks, int $barangayId): Collection
    {
        $householdCounts = Household::query()
            ->where('barangay_id', $barangayId)
            ->selectRaw('purok_id, COUNT(*) as aggregate')
            ->groupBy('purok_id')
            ->pluck('aggregate', 'purok_id');
        $residentCounts = Resident::query()
            ->where('barangay_id', $barangayId)
            ->approvedAccount()
            ->selectRaw('purok_id, COUNT(*) as aggregate')
            ->groupBy('purok_id')
            ->pluck('aggregate', 'purok_id');

        return $puroks
            ->map(fn (Purok $purok) => [
                'id' => $purok->id,
                'name' => $purok->name,
                'household_count' => (int) ($householdCounts[$purok->id] ?? 0),
                'resident_count' => (int) ($residentCounts[$purok->id] ?? 0),
            ])
            ->values();
    }

    /*
    |--------------------------------------------------------------------------
    | Read-only Resident / Registration Details
    |--------------------------------------------------------------------------
    */

    /**
     * Read-only details for a single permanent resident.
     *
     * Scoped to the authenticated admin's barangay; cross-barangay records
     * return 404. Never exposes QR tokens, password hashes, or account
     * credentials.
     */
    public function showResidentDetails(
        Request $request,
        Resident $resident
    ): JsonResponse {
        $this->authorizeResident($request, $resident);

        $resident->load([
            'barangay:id,name',
            'purok:id,name',
            'sectors:id,name,slug',
            'account:id,resident_id,is_active',
            'household.head:id,first_name,middle_name,last_name,suffix',
            'household.members:id,household_id,first_name,middle_name,last_name,suffix,household_relationship',
        ]);
        $decisionNotification = Schema::hasColumn('resident_registrations', 'matched_resident_id')
            ? $resident->matchedRegistrations()
                ->with('latestDecisionNotification')
                ->latest('id')
                ->first()
                ?->latestDecisionNotification
            : null;

        return response()->json([
            'type' => 'resident',
            'data' => [
                'id' => $resident->id,
                'resident_number' => $resident->resident_number,
                'first_name' => $resident->first_name,
                'middle_name' => $resident->middle_name,
                'last_name' => $resident->last_name,
                'suffix' => $resident->suffix,
                'birthdate' => $resident->birthdate?->format('Y-m-d'),
                'sex' => $resident->sex,
                'civil_status' => $resident->civil_status,
                'address_line' => $resident->address_line,
                'mobile' => $resident->mobile,
                'email' => $resident->email,
                'email_verified' => $resident->email_verified_at !== null,
                'status' => $resident->status,
                'source' => $resident->source,
                'approved_at' => $resident->approved_at?->format('Y-m-d'),
                'barangay' => $resident->barangay?->name,
                'purok' => $resident->purok?->name,
                'account_exists' => $resident->account !== null,
                'account_active' => (bool) (
                    $resident->account?->is_active ?? false
                ),
                'household' => $resident->household === null ? null : [
                    'id' => $resident->household->id,
                    'code' => $resident->household->household_code,
                    'head' => $resident->household->head?->full_name,
                    'relationship' => $resident->household_relationship,
                    'members' => $resident->household->members->map(fn (Resident $member) => [
                        'id' => $member->id,
                        'full_name' => $member->full_name,
                        'relationship' => $member->household_relationship,
                    ])->values(),
                ],
                'decision_notification' => $decisionNotification === null ? null : [
                    'id' => $decisionNotification->id,
                    'status' => $decisionNotification->status,
                    'attempts' => $decisionNotification->attempts,
                ],
                'sectors' => $resident->sectors
                    ->map(fn ($sector) => [
                        'id' => $sector->id,
                        'name' => $sector->name,
                        'slug' => $sector->slug,
                    ])
                    ->values(),
            ],
        ])->header('Cache-Control', 'private, no-store, max-age=0');
    }

    /**
     * Read-only details for a registration-only record (an application that
     * has not produced a permanent resident yet).
     *
     * Scoped to the authenticated admin's barangay; cross-barangay records
     * return 404. Never exposes the stored password hash.
     */
    public function showRegistrationDetails(
        Request $request,
        ResidentRegistration $registration
    ): JsonResponse {
        $user = $request->user();

        abort_unless(
            (int) $registration->barangay_id ===
                (int) $user->barangay_id,
            404
        );

        $hasMatchColumns = Schema::hasColumn(
            'resident_registrations',
            'matched_resident_id'
        );

        $relations = [
            'barangay:id,name',
            'purok:id,name',
            'sectors.sector:id,name,slug',
            'documents.sector:id,slug',
            'reviewer:id,name',
            'correctionCycles.reviewer:id,name',
            'resident.purok:id,name',
            'resident.account:id,resident_id,is_active',
        ];
        if ($hasMatchColumns) {
            $relations[] = 'matchedResident.purok:id,name';
            $relations[] = 'matchedResident.account:id,resident_id,is_active';
        }
        $registration->load($relations);

        $linkedResident = $hasMatchColumns ? $registration->matchedResident : null;
        if ($linkedResident === null && $registration->status === 'approved') {
            $linkedResident = $registration->resident;
        }

        if (
            $linkedResident !== null &&
            (int) $linkedResident->barangay_id !== (int) $registration->barangay_id
        ) {
            $linkedResident = null;
        }

        return response()->json([
            'type' => 'registration',
            'data' => [
                'reference' => $registration->public_id,
                'first_name' => $registration->first_name,
                'middle_name' => $registration->middle_name,
                'last_name' => $registration->last_name,
                'suffix' => $registration->suffix,
                'birthdate' => $registration->birthdate?->format('Y-m-d'),
                'sex' => $registration->sex,
                'civil_status' => $registration->civil_status,
                'address_line' => $registration->address_line,
                'mobile' => $registration->mobile,
                'email' => $registration->email,
                'status' => $registration->status,
                'review_notes' => $registration->review_notes,
                'reviewer' => $registration->reviewer?->name,
                'matched_at' => $hasMatchColumns
                    ? $registration->matched_at?->format('Y-m-d H:i')
                    : null,
                'matched_resident' => $linkedResident ? [
                    'id' => $linkedResident->id,
                    'resident_number' => $linkedResident->resident_number,
                    'full_name' => $linkedResident->full_name,
                    'birthdate' => $linkedResident->birthdate?->format('Y-m-d'),
                    'email' => $linkedResident->email,
                    'status' => $linkedResident->status,
                    'purok' => $linkedResident->purok?->name,
                    'account_exists' => $linkedResident->account !== null,
                    'account_active' => (bool) ($linkedResident->account?->is_active ?? false),
                ] : null,
                'submitted_at' => $registration->submitted_at?->format('Y-m-d'),
                'reviewed_at' => $registration->reviewed_at?->format('Y-m-d'),
                'last_resubmitted_at' => $registration->last_resubmitted_at
                    ?->format('Y-m-d'),
                'barangay' => $registration->barangay?->name,
                'purok' => $registration->purok?->name,
                'sectors' => $registration->sectors
                    ->map(fn ($registrationSector) => [
                        'id' => $registrationSector->sector?->id,
                        'name' => $registrationSector->sector?->name,
                        'slug' => $registrationSector->sector?->slug,
                    ])
                    ->filter(fn ($sector) => $sector['id'])
                    ->values(),
                'documents' => $registration->documents
                    ->map(
                        fn (RegistrationDocument $document) => $this->documentPayload(
                            $document,
                            $registration->id
                        )
                    )
                    ->values(),
                'correction_cycles' => $registration->correctionCycles
                    ->map(fn (ResidentRegistrationCorrectionCycle $cycle) => [
                        'cycle_number' => $cycle->cycle_number,
                        'instructions' => $cycle->instructions,
                        'field_targets' => $cycle->field_targets,
                        'document_targets' => $cycle->document_targets,
                        'reviewer' => $cycle->reviewer?->name,
                        'requested_at' => $cycle->requested_at?->format('Y-m-d H:i'),
                        'resubmitted_at' => $cycle->resubmitted_at?->format('Y-m-d H:i'),
                    ])
                    ->values(),
            ],
        ])->header('Cache-Control', 'private, no-store, max-age=0');
    }

    /**
     * Search official Residents in the application's barangay.
     * Results are suggestions only; an administrator must select one explicitly.
     */
    public function matchingCandidates(
        Request $request,
        ResidentRegistration $registration,
        ResidentRegistrationQrMatcher $qrMatcher,
    ): JsonResponse {
        abort_unless(
            (int) $registration->barangay_id ===
                (int) $request->user()->barangay_id,
            404
        );

        abort_unless(
            Schema::hasColumn('resident_registrations', 'matched_resident_id'),
            503,
            'Resident candidate linking is unavailable until the identity-linking schema update is applied.'
        );

        abort_unless(
            $registration->status === 'pending' &&
                $registration->matched_resident_id === null,
            422
        );

        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);
        $terms = preg_split('/\s+/', trim($validated['q'])) ?: [];

        $candidates = Resident::query()
            ->with(['purok:id,name', 'account:id,resident_id,is_active'])
            ->where('barangay_id', $registration->barangay_id)
            ->where('source', 'admin')
            ->whereIn('status', ['active', 'inactive'])
            ->where(function ($query) use ($terms) {
                foreach ($terms as $term) {
                    $like = '%'.Str::lower($term).'%';
                    $query->where(function ($query) use ($like) {
                        $query->whereRaw('LOWER(resident_number) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(first_name) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(middle_name) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(last_name) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(email) LIKE ?', [$like]);
                    });
                }
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(20)
            ->get()
            ->map(fn (Resident $resident) => [
                'id' => $resident->id,
                'resident_number' => $resident->resident_number,
                'full_name' => $resident->full_name,
                'birthdate' => $resident->birthdate?->format('Y-m-d'),
                'sex' => $resident->sex,
                'purok' => $resident->purok?->name,
                'email' => $resident->email,
                'status' => $resident->status,
                'account_exists' => $resident->account !== null,
                'account_active' => (bool) ($resident->account?->is_active ?? false),
                'qr_suggested' => false,
            ])
            ->values();

        $qrResident = $qrMatcher->suggestedResident($registration);
        if ($qrResident !== null) {
            $qrSuggestion = [
                'id' => $qrResident->id,
                'resident_number' => $qrResident->resident_number,
                'full_name' => $qrResident->full_name,
                'birthdate' => $qrResident->birthdate?->format('Y-m-d'),
                'sex' => $qrResident->sex,
                'purok' => $qrResident->purok?->name,
                'email' => $qrResident->email,
                'status' => $qrResident->status,
                'account_exists' => $qrResident->account !== null,
                'account_active' => (bool) ($qrResident->account?->is_active ?? false),
                'qr_suggested' => true,
            ];

            $existingIndex = $candidates->search(
                fn (array $candidate) => $candidate['id'] === $qrResident->id
            );
            if ($existingIndex === false) {
                $candidates->prepend($qrSuggestion);
            } else {
                $candidates->put($existingIndex, $qrSuggestion);
            }
        }

        return response()
            ->json(['data' => $candidates])
            ->header('Cache-Control', 'private, no-store, max-age=0');
    }

    /*
    |--------------------------------------------------------------------------
    | Show Registration Document
    |--------------------------------------------------------------------------
    */

    public function showDocument(
        Request $request,
        ResidentRegistration $registration,
        RegistrationDocument $document
    ): StreamedResponse {
        $user = $request->user();

        // Admin can only view applications from their own barangay.
        // Return 404 (instead of 403) so cross-barangay records are not disclosed.
        abort_unless(
            (int) $registration->barangay_id ===
                (int) $user->barangay_id,
            404
        );

        // Prevent using a document ID belonging to another registration.
        abort_unless(
            (int) $document->resident_registration_id ===
                (int) $registration->id,
            404
        );

        $disk = $document->disk ?: 'local';

        if (! Storage::disk($disk)->exists($document->path)) {
            $content = $document->backedUpContent();
            abort_if($content === null, 404);

            return response()->stream(function () use ($content): void {
                echo $content;
            }, 200, [
                'Content-Type' => $document->mime_type ?: 'application/octet-stream',
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        abort_unless(
            Storage::disk($disk)->exists($document->path),
            404
        );

        return Storage::disk($disk)->response(
            $document->path,
            $document->original_name ?: basename($document->path),
            [
                'Content-Type' => $document->mime_type
                    ?: 'application/octet-stream',
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
            'inline'
        );
    }

    public function requestCorrection(
        RequestResidentRegistrationCorrectionRequest $request,
        ResidentRegistration $registration,
        ResidentRegistrationDecisionNotificationService $notifications,
    ): RedirectResponse {
        $user = $request->user();

        abort_unless((int) $registration->barangay_id === (int) $user->barangay_id, 404);

        $validated = $request->validated();
        $fieldTargets = array_values($validated['field_targets'] ?? []);
        $documentTargets = array_values($validated['document_targets'] ?? []);

        try {
            [$cycle, $notification] = DB::transaction(function () use (
                $registration,
                $user,
                $fieldTargets,
                $documentTargets,
                $validated,
                $notifications,
            ): array {
                $locked = ResidentRegistration::query()
                    ->whereKey($registration->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                abort_unless(
                    (int) $locked->barangay_id === (int) $user->barangay_id,
                    404
                );
                abort_unless($locked->status === 'pending', 422);

                $locked->load(['documents.sector:id,slug']);
                $availableDocuments = ResidentRegistrationCorrectionPolicy::availableDocumentTargets(
                    $locked->documents
                );
                if (array_diff($documentTargets, $availableDocuments) !== []) {
                    throw ValidationException::withMessages([
                        'document_targets' => 'One or more selected documents are no longer available for correction.',
                    ]);
                }

                $nextCycleNumber = ((int) ResidentRegistrationCorrectionCycle::query()
                    ->where('resident_registration_id', $locked->id)
                    ->max('cycle_number')) + 1;
                $snapshot = [];
                foreach ($fieldTargets as $field) {
                    $value = $locked->getAttribute($field);
                    $snapshot[$field] = $value instanceof \DateTimeInterface
                        ? $value->format('Y-m-d')
                        : $value;
                }

                $requestedAt = now();
                $cycle = $locked->correctionCycles()->create([
                    'cycle_number' => $nextCycleNumber,
                    'instructions' => trim($validated['instructions']),
                    'field_targets' => $fieldTargets,
                    'document_targets' => $documentTargets,
                    'prior_field_snapshot' => $snapshot,
                    'reviewer_id' => $user->id,
                    'requested_at' => $requestedAt,
                ]);

                $locked->forceFill([
                    'status' => 'needs_correction',
                    'reviewed_by' => $user->id,
                    'reviewed_at' => $requestedAt,
                ])->save();

                $notification = $notifications->enqueue($locked, 'correction', $cycle);

                return [$cycle, $notification];
            });
        } catch (QueryException $exception) {
            $sqlState = $exception->errorInfo[0] ?? (string) $exception->getCode();
            $message = strtolower($exception->getMessage());
            if (
                in_array($sqlState, ['23000', '23505'], true)
                && str_contains($message, 'resident_reg_correction_cycle_unique')
            ) {
                throw ValidationException::withMessages([
                    'correction' => 'A correction request was already recorded. Refresh the application before trying again.',
                ]);
            }

            throw $exception;
        }

        $notifications->deliverAfterCommit($notification->id);

        AuditLogger::record(
            'resident_registration.correction_requested',
            $registration->barangay_id,
            $user->id,
            'resident_registration',
            $registration->id,
            ['cycle_number' => $cycle->cycle_number],
            $request->ip(),
        );

        return back()->with('success', 'Correction instructions were sent to the applicant’s secure application page.');
    }

    /*
    |--------------------------------------------------------------------------
    | Approve Resident Registration
    |--------------------------------------------------------------------------
    */

    public function approve(
        Request $request,
        ResidentRegistration $registration,
        ResidentRegistrationDecisionNotificationService $notifications,
    ): RedirectResponse {
        $user = $request->user();

        /*
        |--------------------------------------------------------------------------
        | Barangay Security
        |--------------------------------------------------------------------------
        |
        | An admin can only approve applications belonging to their barangay.
        | Return 404 (instead of 403) so cross-barangay records are not disclosed.
        |
        */

        abort_unless(
            (int) $registration->barangay_id ===
                (int) $user->barangay_id,
            404
        );

        if (! Schema::hasColumn('resident_registrations', 'matched_resident_id')) {
            throw ValidationException::withMessages([
                'approval' => 'Resident linking is unavailable until the identity-linking schema update is applied. The application remains pending.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Application Status
        |--------------------------------------------------------------------------
        */

        abort_unless($registration->status === 'pending', 422);

        $validated = $request->validate([
            'resident_id' => ['required', 'integer'],
        ]);
        $previousStatus = $registration->status;

        if ($registration->resident()->exists() || $registration->matched_resident_id !== null) {
            throw ValidationException::withMessages([
                'approval' => 'This registration has already been linked to a Resident.',
            ]);
        }

        [$resident, $notification] = DB::transaction(function () use ($registration, $user, $validated, $notifications) {
            $lockedRegistration = ResidentRegistration::query()
                ->whereKey($registration->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $lockedRegistration->status !== 'pending' ||
                $lockedRegistration->resident()->exists() ||
                $lockedRegistration->matched_resident_id !== null
            ) {
                throw ValidationException::withMessages([
                    'approval' => 'This registration is no longer available for linking.',
                ]);
            }

            $resident = Resident::query()
                ->whereKey($validated['resident_id'])
                ->where('barangay_id', $lockedRegistration->barangay_id)
                ->where('source', 'admin')
                ->lockForUpdate()
                ->first();

            if (! $resident || ! in_array($resident->status, ['active', 'inactive'], true)) {
                throw ValidationException::withMessages([
                    'resident_id' => 'Select an Admin-added Resident from this barangay.',
                ]);
            }

            $hasAccount = ResidentAccount::query()
                ->where('resident_id', $resident->id)
                ->lockForUpdate()
                ->exists();

            if ($hasAccount) {
                throw ValidationException::withMessages([
                    'resident_id' => 'This Resident already has an account. Existing account credentials were not changed.',
                ]);
            }

            $registrationEmail = Str::lower(trim((string) $lockedRegistration->email));
            // A verified application email supersedes any blank or stale
            // masterlist value. Legacy registrations without an email never
            // erase an existing resident contact address.
            if ($registrationEmail !== '') {
                $resident->forceFill([
                    'email' => $registrationEmail,
                    'email_verified_at' => $lockedRegistration->email_verified_at,
                ])->save();
            }

            $this->ensureActiveQrCredential($resident);

            try {
                $residentAccount = ResidentAccount::create([
                    'resident_id' => $resident->id,
                    'password' => $lockedRegistration->password,
                    'is_active' => true,
                    'password_changed_at' => null,
                    'last_login_at' => null,
                ]);

                User::firstOrCreate(
                    ['resident_id' => $resident->id],
                    [
                        'barangay_id' => $resident->barangay_id,
                        'name' => trim($resident->first_name.' '.$resident->last_name),
                        'email' => $resident->email ?? "resident_{$resident->id}@kabarangay.local",
                        'password' => $residentAccount->password,
                        'status' => 'active',
                    ],
                );
            } catch (QueryException $exception) {
                $sqlState = $exception->errorInfo[0] ?? (string) $exception->getCode();
                $message = strtolower($exception->getMessage());
                if (
                    in_array($sqlState, ['23000', '23505'], true) &&
                    str_contains($message, 'resident_accounts') &&
                    str_contains($message, 'resident_id')
                ) {
                    throw ValidationException::withMessages([
                        'resident_id' => 'This Resident account was linked by another request. Refresh and review the record.',
                    ]);
                }
                throw $exception;
            }

            $sectorIds = $lockedRegistration->sectors()
                ->pluck('sector_id')
                ->filter()
                ->unique()
                ->values()
                ->all();
            if ($sectorIds !== []) {
                $resident->sectors()->syncWithoutDetaching($sectorIds);
            }

            $lockedRegistration->forceFill([
                'matched_resident_id' => $resident->id,
                'matched_at' => now(),
                'matched_by' => $user->id,
                'status' => 'approved',
                'review_notes' => null,
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
            ])->save();

            $resident->forceFill([
                'status' => 'active',
                'approved_at' => now(),
            ])->save();

            $notification = $notifications->enqueue($lockedRegistration, 'approved');

            return [$resident, $notification];
        });

        $notifications->deliverAfterCommit($notification->id);

        AuditLogger::record(
            'resident_registration.approved',
            $registration->barangay_id,
            $user->id,
            'resident_registration',
            $registration->id,
            [
                'previous_status' => $previousStatus,
                'new_status' => 'approved',
            ],
            $request->ip(),
        );

        AuditLogger::record(
            'resident_registration.matched',
            $registration->barangay_id,
            $user->id,
            'resident_registration',
            $registration->id,
            ['entity_reference' => $resident->resident_number],
            $request->ip(),
        );

        return back()->with(
            'success',
            'Registration approved and linked to '.$resident->resident_number.'.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Reject Resident Registration
    |--------------------------------------------------------------------------
    */

    public function reject(
        Request $request,
        ResidentRegistration $registration,
        ResidentRegistrationDecisionNotificationService $notifications,
    ): RedirectResponse {
        $user = $request->user();

        // Admin can only review applicants from their barangay.
        // Return 404 (instead of 403) so cross-barangay records are not disclosed.
        abort_unless(
            (int) $registration->barangay_id ===
                (int) $user->barangay_id,
            404
        );

        // Correction requests must be resubmitted to Pending before decision.
        abort_unless($registration->status === 'pending', 422);

        $previousStatus = $registration->status;

        $validated = $request->validate([
            'reason' => [
                'required',
                'string',
                'min:5',
                'max:1000',
            ],
        ]);
        $emailSafeReason = $request->input('email_safe_reason');
        if (
            ! is_string($emailSafeReason) ||
            mb_strlen(trim($emailSafeReason)) < 5 ||
            mb_strlen(trim($emailSafeReason)) > 1000
        ) {
            $emailSafeReason = null;
        } else {
            $emailSafeReason = trim($emailSafeReason);
        }

        $notification = DB::transaction(function () use ($registration, $validated, $user, $notifications, $emailSafeReason): ResidentNotification {
            $lockedRegistration = ResidentRegistration::query()
                ->whereKey($registration->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless($lockedRegistration->status === 'pending', 422);

            $lockedRegistration->update([
                'status' => 'rejected',
                'review_notes' => trim($validated['reason']),
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
            ]);

            return $notifications->enqueue(
                $lockedRegistration,
                'rejected',
                emailSafeReason: $emailSafeReason,
            );
        });

        $notifications->deliverAfterCommit($notification->id);

        /*
        |--------------------------------------------------------------------------
        | Audit: registration rejected
        |--------------------------------------------------------------------------
        |
        | The free-text rejection reason is deliberately not recorded; only the
        | bounded status transition is written.
        |
        */

        AuditLogger::record(
            'resident_registration.rejected',
            $registration->barangay_id,
            $user->id,
            'resident_registration',
            $registration->id,
            [
                'previous_status' => $previousStatus,
                'new_status' => 'rejected',
            ],
            $request->ip(),
        );

        return back()->with(
            'success',
            'Resident application rejected.'
        );
    }

    public function retryNotification(
        Request $request,
        ResidentNotification $notification,
        ResidentRegistrationDecisionNotificationService $notifications,
    ): RedirectResponse {
        abort_unless(
            $notification->type === ResidentNotification::TYPE_REGISTRATION_DECISION &&
            $notification->registration !== null,
            404,
        );
        abort_unless(
            (int) $notification->registration->barangay_id === (int) $request->user()->barangay_id,
            404,
        );

        if ($notification->status !== ResidentNotification::STATUS_FAILED) {
            throw ValidationException::withMessages([
                'notification' => 'Only a failed, eligible registration decision notification can be retried.',
            ]);
        }

        $result = $notifications->retry($notification);

        return back()->with(
            $result->status === ResidentNotification::STATUS_SENT ? 'success' : 'error',
            $result->status === ResidentNotification::STATUS_SENT
                ? 'Registration notification sent.'
                : 'Registration notification remains unavailable for delivery.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Update Resident Status
    |--------------------------------------------------------------------------
    */

    /**
     * Activate or deactivate a permanent resident.
     *
     * Deactivating a resident immediately blocks portal access because the
     * resident auth middleware requires an active resident status.
     */
    public function updateResidentStatus(
        Request $request,
        Resident $resident
    ): RedirectResponse {
        $this->authorizeResident($request, $resident);

        $validated = $request->validate([
            'status' => [
                'required',
                'string',
                Rule::in(self::RESIDENT_STATUSES),
            ],
        ]);

        $previousStatus = $resident->status;

        $resident->update([
            'status' => $validated['status'],
        ]);

        if ($resident->wasChanged('status')) {
            AuditLogger::record(
                'resident.status_changed',
                $resident->barangay_id,
                $request->user()->id,
                'resident',
                $resident->id,
                [
                    'previous_status' => $previousStatus,
                    'new_status' => $resident->status,
                ],
                $request->ip(),
            );
        }

        return back()->with(
            'success',
            $validated['status'] === 'active'
                ? 'Resident activated successfully.'
                : 'Resident deactivated successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Update Resident Login Account Status
    |--------------------------------------------------------------------------
    */

    /**
     * Enable or disable a resident's portal login account.
     *
     * This is independent of the resident record status: either one being
     * inactive blocks login through the resident auth middleware.
     */
    public function updateAccountStatus(
        Request $request,
        Resident $resident
    ): RedirectResponse {
        $this->authorizeResident($request, $resident);

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $resident->loadMissing('account');
        $account = $resident->account;

        abort_unless($account, 404);

        $previousStatus = $account->is_active ? 'active' : 'inactive';

        $account->update([
            'is_active' => $validated['is_active'],
        ]);

        if ($account->wasChanged('is_active')) {
            AuditLogger::record(
                'resident.account_status_changed',
                $resident->barangay_id,
                $request->user()->id,
                'resident',
                $resident->id,
                [
                    'previous_status' => $previousStatus,
                    'new_status' => $account->is_active ? 'active' : 'inactive',
                ],
                $request->ip(),
            );
        }

        return back()->with(
            'success',
            $validated['is_active']
                ? 'Resident login account enabled.'
                : 'Resident login account disabled.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Revoke Resident QR Credential
    |--------------------------------------------------------------------------
    */

    /**
     * Revoke a resident's active QR credential.
     *
     * The credential row is flipped to inactive and stamped with a revocation
     * time. Every downstream consumer (public verification, staff token
     * resolution, and QR attendance-proof issuance) already treats an inactive
     * or revoked credential as invalid, so the raw token stops working
     * everywhere the moment this lands.
     */
    public function revokeQrCredential(
        Request $request,
        Resident $resident
    ): RedirectResponse {
        $this->authorizeResident($request, $resident);

        DB::transaction(function () use ($resident) {
            // Serialize concurrent credential changes for this resident.
            Resident::query()
                ->whereKey($resident->id)
                ->lockForUpdate()
                ->first();

            $credential = ResidentQrCredential::query()
                ->where('resident_id', $resident->id)
                ->lockForUpdate()
                ->first();

            if (! $credential || ! $this->qrCredentialIsActive($credential)) {
                throw ValidationException::withMessages([
                    'qr' => 'This resident has no active QR credential to revoke.',
                ]);
            }

            $credential->forceFill([
                'is_active' => false,
                'revoked_at' => now(),
            ])->save();
        });

        return back()->with(
            'success',
            'QR credential revoked successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Reissue Resident QR Credential
    |--------------------------------------------------------------------------
    */

    /**
     * Issue a fresh QR credential for a resident, invalidating the previous one.
     *
     * The credential row is updated in place inside a transaction and behind a
     * row lock. Because `resident_qr_credentials.resident_id` is unique there is
     * only ever one credential row per resident, so the replacement and the
     * invalidation of the old token commit atomically: there is no window in
     * which both an old and a new credential are usable.
     */
    public function reissueQrCredential(
        Request $request,
        Resident $resident
    ): RedirectResponse {
        $this->authorizeResident($request, $resident);

        DB::transaction(function () use ($resident) {
            // Serialize concurrent credential changes for this resident.
            Resident::query()
                ->whereKey($resident->id)
                ->lockForUpdate()
                ->first();

            $credential = ResidentQrCredential::query()
                ->where('resident_id', $resident->id)
                ->lockForUpdate()
                ->first();

            $token = 'KBQR-'.Str::random(64);

            if (! $credential) {
                try {
                    ResidentQrCredential::create([
                        'resident_id' => $resident->id,
                        'token' => $token,
                        'is_active' => true,
                        'generated_at' => now(),
                        'revoked_at' => null,
                    ]);
                } catch (QueryException $exception) {
                    /*
                    |--------------------------------------------------------------------------
                    | Defensive QR Credential Uniqueness Handling
                    |--------------------------------------------------------------------------
                    |
                    | A concurrent request may have created this resident's first
                    | credential between the locked read above and this insert.
                    | The UNIQUE(resident_id) constraint then rejects the insert,
                    | so surface a clear validation error instead of leaking a
                    | raw database exception. Any other query failure is
                    | rethrown untouched.
                    |
                    */

                    if ($this->isResidentQrCredentialUniqueViolation($exception)) {
                        throw ValidationException::withMessages([
                            'qr' => 'This resident already has a QR credential. Please try again.',
                        ]);
                    }

                    throw $exception;
                }

                return;
            }

            $credential->forceFill([
                'token' => $token,
                'is_active' => true,
                'generated_at' => now(),
                'revoked_at' => null,
            ])->save();
        });

        return back()->with(
            'success',
            'QR credential reissued successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */

    /**
     * Ensure the resident belongs to the authenticated admin's barangay.
     *
     * Returns 404 (instead of 403) so cross-barangay records are not
     * disclosed, matching the other resident admin endpoints.
     */
    private function authorizeResident(
        Request $request,
        Resident $resident
    ): void {
        $user = $request->user();

        abort_unless(
            (int) $resident->barangay_id === (int) $user->barangay_id,
            404
        );
    }

    /*
    |--------------------------------------------------------------------------
    | QR Credential Status
    |--------------------------------------------------------------------------
    */

    /**
     * Whether a credential is currently usable: active and not revoked.
     */
    private function qrCredentialIsActive(
        ResidentQrCredential $credential
    ): bool {
        return $credential->is_active && $credential->revoked_at === null;
    }

    private function ensureActiveQrCredential(Resident $resident): ResidentQrCredential
    {
        $credential = ResidentQrCredential::query()
            ->where('resident_id', $resident->id)
            ->lockForUpdate()
            ->first();

        if ($credential !== null && $this->qrCredentialIsActive($credential)) {
            return $credential;
        }

        $values = [
            'token' => 'KBQR-'.Str::random(64),
            'is_active' => true,
            'generated_at' => now(),
            'revoked_at' => null,
        ];

        if ($credential === null) {
            return ResidentQrCredential::query()->create([
                'resident_id' => $resident->id,
                ...$values,
            ]);
        }

        $credential->forceFill($values)->save();

        return $credential;
    }

    /**
     * Human-readable QR credential state for the admin list.
     *
     * Returns "active", "revoked", or "none". The raw token is never exposed.
     */
    private function resolveQrStatus(bool $exists, bool $active): string
    {
        if (! $exists) {
            return 'none';
        }

        return $active ? 'active' : 'revoked';
    }

    /*
    |--------------------------------------------------------------------------
    | Purok Filtering
    |--------------------------------------------------------------------------
    */

    /**
     * Resolve the `purok` query parameter against the barangay's loaded puroks.
     *
     * Unknown, non-numeric, or foreign purok ids resolve to null (All Puroks)
     * so a tampered query string can never expose another barangay's records.
     *
     * @param  array<int, int>  $purokIds
     */
    private function resolvePurokFilter(
        Request $request,
        array $purokIds
    ): ?int {
        $purokId = $request->query('purok');

        if (! is_numeric($purokId)) {
            return null;
        }

        $purokId = (int) $purokId;

        return in_array($purokId, $purokIds, true) ? $purokId : null;
    }

    /*
    |--------------------------------------------------------------------------
    | QR Credential Uniqueness
    |--------------------------------------------------------------------------
    */

    /**
     * Determine whether a query exception is a UNIQUE(resident_id) violation on
     * the `resident_qr_credentials` table.
     *
     * This constraint fails when two requests race to create the same
     * resident's first credential; the losing request must surface a validation
     * error instead of a raw database exception. The check requires both the
     * table name and the `resident_id` column so an unrelated unique violation
     * (for example on `token`) is not swallowed.
     */
    private function isResidentQrCredentialUniqueViolation(
        QueryException $exception
    ): bool {
        $sqlState = $exception->errorInfo[0] ?? (string) $exception->getCode();

        if (! in_array($sqlState, ['23000', '23505'], true)) {
            return false;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'resident_qr_credentials')
            && str_contains($message, 'resident_id');
    }

    /**
     * @return array<string, mixed>
     */
    private function documentPayload(
        RegistrationDocument $document,
        int $registrationId
    ): array {
        return [
            'id' => $document->id,
            'type' => $document->document_type,
            'label' => $this->documentLabel($document->document_type),
            'mime_type' => $document->mime_type,
            'file_size' => $document->file_size,
            'verification_status' => $document->verification_status,
            'sector_slug' => $document->sector?->slug,
            'is_superseded' => $document->superseded_at !== null,
            'superseded_at' => $document->superseded_at?->format('Y-m-d H:i'),
            'url' => route(
                'admin.residents.documents.show',
                [
                    'registration' => $registrationId,
                    'document' => $document->id,
                ],
                absolute: false,
            ),
            'is_image' => str_starts_with(
                (string) $document->mime_type,
                'image/'
            ),
        ];
    }

    /**
     * Barangay-scoped registration review queue. Correction document choices
     * are derived from current, unambiguous document records; the server still
     * rechecks them under lock when an Admin submits a request.
     *
     * @param  Collection<int, Purok>  $puroks
     */
    private function registrationList(
        int $barangayId,
        ?int $purokId,
        string $search,
        string $status,
        string $pageName,
        ?int $total,
        Collection &$puroks
    ): LengthAwarePaginator {
        $paginator = $this->registrationQuery($barangayId, $purokId, $search)
            ->with([
                'barangay:id,name',
                'sectors.sector:id,name,slug',
                'documents.sector:id,slug',
                'latestDecisionNotification',
            ])
            ->where('status', $status)
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate(15, ['*'], $pageName, null, $total)
            ->withQueryString();

        $this->hydratePuroks($paginator->getCollection(), $puroks);

        return $paginator->through(function (ResidentRegistration $registration): array {
            return [
                'id' => $registration->id,
                'reference' => $registration->public_id,
                'first_name' => $registration->first_name,
                'middle_name' => $registration->middle_name,
                'last_name' => $registration->last_name,
                'suffix' => $registration->suffix,
                'birthdate' => $registration->birthdate?->format('Y-m-d'),
                'sex' => $registration->sex,
                'civil_status' => $registration->civil_status,
                'address_line' => $registration->address_line,
                'mobile' => $registration->mobile,
                'email' => $registration->email,
                'barangay' => $registration->barangay?->name,
                'purok' => $registration->purok?->name,
                'sectors' => $registration->sectors
                    ->map(fn ($registrationSector) => [
                        'id' => $registrationSector->sector?->id,
                        'name' => $registrationSector->sector?->name,
                        'slug' => $registrationSector->sector?->slug,
                    ])
                    ->filter(fn ($sector) => $sector['id'] !== null)
                    ->values(),
                'documents' => $registration->documents
                    ->map(fn (RegistrationDocument $document) => $this->documentPayload(
                        $document,
                        $registration->id
                    ))
                    ->values(),
                'correction_field_options' => ResidentRegistrationCorrectionPolicy::FIELD_TARGETS,
                'correction_document_options' => ResidentRegistrationCorrectionPolicy::availableDocumentTargets(
                    $registration->documents
                ),
                'status' => $registration->status,
                'submitted_at' => $registration->submitted_at?->format('Y-m-d'),
                'review_notes' => $registration->review_notes,
                'reviewed_at' => $registration->reviewed_at?->format('Y-m-d'),
                'last_resubmitted_at' => $registration->last_resubmitted_at?->format('Y-m-d'),
                'decision_notification' => $registration->latestDecisionNotification === null ? null : [
                    'id' => $registration->latestDecisionNotification->id,
                    'status' => $registration->latestDecisionNotification->status,
                    'attempts' => $registration->latestDecisionNotification->attempts,
                ],
            ];
        });
    }

    /**
     * Shared filters for the registration review queue and its status counts.
     */
    private function registrationQuery(
        int $barangayId,
        ?int $purokId,
        string $search
    ): Builder {
        return ResidentRegistration::query()
            ->where('barangay_id', $barangayId)
            ->when($purokId !== null, fn ($query) => $query->where('purok_id', $purokId))
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.$search.'%';
                $query->where(function ($query) use ($like): void {
                    $query->where('public_id', 'like', $like)
                        ->orWhere('first_name', 'like', $like)
                        ->orWhere('middle_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('mobile', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhereHas('purok', fn ($purok) => $purok->where('name', 'like', $like));
                });
            });
    }

    /**
     * Load purok relations from the already-loaded barangay puroks. Puroks
     * outside that list are fetched once, preserving the original relation
     * behavior even if legacy data points across barangays.
     *
     * @param  Collection<int, Resident|ResidentRegistration>  $records
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
    | Resident directories (barangay-scoped, paginated)
    |--------------------------------------------------------------------------
    */

    /**
     * Barangay-scoped masterlist query, shared by the paginator and the
     * combined summary-count query.
     *
     * @param  'admin'|null  $source
     */
    private function residentListQuery(
        int $barangayId,
        ?int $purokId,
        string $search,
        ?string $source
    ): Builder {
        return Resident::query()
            ->with('sectors:id,name,slug')
            ->withExists([
                'account as account_exists',
                'account as account_active' => fn ($query) => $query->where('is_active', true),
                'qrCredential as qr_credential_exists',
                'qrCredential as qr_credential_active' => fn ($query) => $query
                    ->where('is_active', true)
                    ->whereNull('revoked_at'),
            ])
            ->where('barangay_id', $barangayId)
            ->when($source !== null, fn ($query) => $query->where('source', $source))
            ->when($purokId !== null, fn ($query) => $query->where('purok_id', $purokId))
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';

                $query->where(function ($query) use ($like) {
                    $query->where('resident_number', 'like', $like)
                        ->orWhere('first_name', 'like', $like)
                        ->orWhere('middle_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('mobile', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhereHas('purok', fn ($query) => $query->where('name', 'like', $like));
                });
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id');
    }

    /**
     * Barangay-scoped, server-side paginated masterlist records.
     *
     * @param  'admin'|null  $source
     */
    private function residentList(
        int $barangayId,
        ?int $purokId,
        string $search,
        ?string $source,
        string $pageName,
        int $total,
        Collection &$puroks
    ): LengthAwarePaginator {
        $paginator = $this->residentListQuery($barangayId, $purokId, $search, $source)
            ->paginate(15, ['*'], $pageName, null, $total)
            ->withQueryString();

        $this->hydratePuroks($paginator->getCollection(), $puroks);

        return $paginator->through(fn (Resident $resident) => [
            'id' => $resident->id,
            'residentId' => $resident->resident_number,
            'fullName' => $resident->full_name,
            'sex' => $resident->sex,
            'purok' => $resident->purok?->name ?? '—',
            'sectors' => $resident->sectors
                ->map(fn ($sector) => [
                    'id' => $sector->id,
                    'name' => $sector->name,
                    'slug' => $sector->slug,
                ])
                ->values(),
            'status' => $resident->status,
            'source' => $resident->source,
            'accountExists' => (bool) $resident->account_exists,
            'accountActive' => (bool) $resident->account_active,
            'emailVerified' => $resident->email_verified_at !== null,
            'qrStatus' => $this->resolveQrStatus(
                (bool) $resident->qr_credential_exists,
                (bool) $resident->qr_credential_active,
            ),
        ]);
    }

    /**
     * Query for all Residents: an active canonical Resident with an active
     * account and an approved registration association. Email
     * verification is not qualification evidence.
     */
    private function allResidentsQuery(
        int $barangayId,
        ?int $purokId,
        string $search
    ): Builder {
        return Resident::query()
            ->with('sectors:id,name,slug')
            ->withExists([
                'qrCredential as qr_credential_exists',
                'qrCredential as qr_credential_active' => fn ($query) => $query
                    ->where('is_active', true)
                    ->whereNull('revoked_at'),
            ])
            ->where('barangay_id', $barangayId)
            ->approvedAccount()
            ->when($purokId !== null, fn ($query) => $query->where('purok_id', $purokId))
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';

                $query->where(function ($query) use ($like) {
                    $query->where('resident_number', 'like', $like)
                        ->orWhere('first_name', 'like', $like)
                        ->orWhere('middle_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like);
                });
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id');
    }

    /**
     * Paginate the qualifying Residents directory using the total already
     * calculated by the shared statistics query.
     */
    private function allResidents(
        int $barangayId,
        ?int $purokId,
        string $search,
        int $total,
        Collection &$puroks
    ): LengthAwarePaginator {
        $paginator = $this->allResidentsQuery($barangayId, $purokId, $search)
            ->paginate(15, ['*'], 'all_page', null, $total)
            ->withQueryString();

        $this->hydratePuroks($paginator->getCollection(), $puroks);

        return $paginator->through(fn (Resident $resident) => [
            'type' => 'resident',
            'id' => $resident->id,
            'reference' => $resident->resident_number,
            'fullName' => $resident->full_name,
            'sex' => $resident->sex,
            'purok' => $resident->purok?->name ?? '—',
            'sectors' => $resident->sectors
                ->map(fn ($sector) => [
                    'id' => $sector->id,
                    'name' => $sector->name,
                    'slug' => $sector->slug,
                ])
                ->values(),
            'status' => $resident->status,
            'accountActive' => true,
            'qrStatus' => $this->resolveQrStatus(
                (bool) $resident->qr_credential_exists,
                (bool) $resident->qr_credential_active,
            ),
        ]);
    }

    private function households(int $barangayId, ?int $purokId, string $search): LengthAwarePaginator
    {
        $like = '%'.$search.'%';

        return Household::query()
            ->where('barangay_id', $barangayId)
            ->with(['purok:id,name', 'head:id,first_name,middle_name,last_name,suffix'])
            ->withCount('members')
            ->when($purokId !== null, fn ($query) => $query->where('purok_id', $purokId))
            ->when($search !== '', function ($query) use ($like) {
                $query->where(function ($query) use ($like) {
                    $query->where('household_code', 'like', $like)
                        ->orWhereHas('head', fn ($head) => $head
                            ->where('first_name', 'like', $like)
                            ->orWhere('middle_name', 'like', $like)
                            ->orWhere('last_name', 'like', $like));
                });
            })
            ->orderBy('household_code')
            ->paginate(15, ['*'], 'household_page')
            ->withQueryString()
            ->through(fn (Household $household) => [
                'id' => $household->id,
                'code' => $household->household_code,
                'head' => $household->head?->full_name ?? 'No head assigned',
                'purok' => $household->purok?->name ?? '—',
                'members_count' => $household->members_count,
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Document Label
    |--------------------------------------------------------------------------
    */

    private function documentLabel(string $type): string
    {
        return match ($type) {
            'valid_id' => 'Valid ID',
            'live_identity_photo' => 'Live Identity Photo',
            'senior_proof' => 'Senior Citizen Proof',
            'pwd_proof' => 'PWD Proof',
            default => ucwords(str_replace('_', ' ', $type)),
        };
    }
}
