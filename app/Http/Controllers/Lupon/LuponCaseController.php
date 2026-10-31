<?php

namespace App\Http\Controllers\Lupon;

use App\Http\Controllers\Controller;
use App\Models\LuponCase;
use App\Models\LuponCaseParty;
use App\Models\LuponComplaint;
use App\Models\Resident;
use App\Support\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LuponCaseController extends Controller
{
    private const PHASES = ['Kapitan', 'Lupon', 'Closed'];

    private const STATUSES = [
        'Active',
        'Under Mediation',
        'Under Conciliation',
        'Settled',
        'Unresolved',
        'Dismissed',
    ];

    private const CASE_TYPES = [
        'Property / Boundary Dispute',
        'Noise / Disturbance',
        'Debt / Money Dispute',
        'Damage to Property',
        'Threat / Harassment',
        'Family / Neighbor Dispute',
        'Other',
    ];

    /**
     * List all cases for the authenticated lupon staff's barangay.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        if (! $user->staffAssignments()->where('department', 'lupon')->where('is_active', true)->exists()) {
            abort(403, 'Unauthorized access to Lupon cases.');
        }

        $search = $request->query('search');
        $statusFilter = $request->query('status');
        $phaseFilter = $request->query('phase');

        $query = LuponCase::query()
            ->where('barangay_id', $user->barangay_id)
            ->with([
                'parties',
                'hearings' => fn ($q) => $q->latest('hearing_date')->limit(1),
            ])
            ->latest('filed_date');

        if (is_string($search) && trim($search) !== '') {
            $trimmed = trim($search);
            $query->where(function ($q) use ($trimmed) {
                $q->where('case_number', 'like', "%{$trimmed}%")
                    ->orWhere('title', 'like', "%{$trimmed}%")
                    ->orWhereHas('parties', fn ($pq) => $pq->where('name', 'like', "%{$trimmed}%"));
            });
        }

        if (is_string($statusFilter) && in_array($statusFilter, self::STATUSES, true)) {
            $query->where('status', $statusFilter);
        }

        if (is_string($phaseFilter) && in_array($phaseFilter, self::PHASES, true)) {
            $query->where('phase', $phaseFilter);
        }

        $barangayId = $user->barangay_id;

        return Inertia::render('Lupon/Cases/Index', [
            'cases' => $this->deferProp(fn () => $query->paginate(15)->withQueryString()->through(function (LuponCase $case) {
                $case->setRelation('complainants', $case->parties->where('party_type', 'complainant')->values());
                $case->setRelation('respondents', $case->parties->where('party_type', 'respondent')->values());
                $primaryComplainant = $case->complainants->firstWhere('is_primary', true) ?? $case->complainants->first();
                $primaryRespondent = $case->respondents->firstWhere('is_primary', true) ?? $case->respondents->first();
                $latestHearing = $case->hearings->first();

                return [
                    'id' => $case->id,
                    'caseNumber' => $case->case_number,
                    'origin' => $case->origin,
                    'title' => $case->title,
                    'caseType' => $case->case_type,
                    'phase' => $case->phase,
                    'status' => $case->status,
                    'filedDate' => $case->filed_date->format('F j, Y'),
                    'complainantName' => $primaryComplainant?->name ?? 'Unknown',
                    'respondentName' => $primaryRespondent?->name ?? 'Unknown',
                    'complainantsCount' => $case->complainants->count(),
                    'respondentsCount' => $case->respondents->count(),
                    'latestHearing' => $latestHearing ? [
                        'number' => $latestHearing->hearing_number,
                        'date' => $latestHearing->hearing_date->format('F j, Y'),
                        'time' => $latestHearing->hearing_time,
                        'status' => $latestHearing->status,
                    ] : null,
                ];
            })),
            'filters' => [
                'search' => $search ?? '',
                'status' => $statusFilter ?? '',
                'phase' => $phaseFilter ?? '',
            ],
            'counts' => $this->deferProp(fn () => $this->rememberAggregate(
                "lupon:cases:counts:{$barangayId}",
                30,
                function () use ($barangayId): array {
                    $counts = LuponCase::query()
                        ->where('barangay_id', $barangayId)
                        ->selectRaw("COUNT(*) as total, COUNT(CASE WHEN status IN ('Active', 'Under Mediation', 'Under Conciliation') THEN 1 END) as active, COUNT(CASE WHEN status = 'Settled' THEN 1 END) as settled, COUNT(CASE WHEN status = 'Unresolved' THEN 1 END) as unresolved")
                        ->first();

                    return [
                        'total' => (int) $counts->total,
                        'active' => (int) $counts->active,
                        'settled' => (int) $counts->settled,
                        'unresolved' => (int) $counts->unresolved,
                    ];
                },
            )),
            'phases' => self::PHASES,
            'statuses' => self::STATUSES,
        ]);
    }

    /**
     * Show form for manually creating a walk-in / direct Lupon case.
     */
    public function create(Request $request): Response
    {
        $user = $request->user();

        if (! $user->staffAssignments()->where('department', 'lupon')->where('is_active', true)->exists()) {
            abort(403, 'Unauthorized access.');
        }

        return Inertia::render('Lupon/Cases/Create', [
            'caseTypes' => self::CASE_TYPES,
            'residents' => $this->deferProp(fn () => Resident::query()
                ->where('barangay_id', $user->barangay_id)
                ->where('status', 'active')
                ->select('id', 'first_name', 'middle_name', 'last_name', 'resident_number', 'mobile')
                ->get()
                ->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->full_name,
                    'residentNumber' => $r->resident_number,
                    'mobile' => $r->mobile,
                ])),
        ]);
    }

    /**
     * Look up an active resident within the authenticated Lupon staff's barangay by QR token or resident number.
     */
    public function qrLookup(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->staffAssignments()->where('department', 'lupon')->where('is_active', true)->exists()) {
            abort(403, 'Unauthorized access.');
        }

        $token = $request->query('token');

        if (! $token || ! is_string($token) || trim($token) === '') {
            return response()->json([
                'message' => 'QR token or resident reference is missing.',
            ], 400);
        }

        $token = trim($token);

        // Normalize URL if scanned as full verification URL
        $path = null;
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $token) === 1) {
            $parsed = parse_url($token);
            $path = is_array($parsed) && isset($parsed['path']) ? $parsed['path'] : null;
        } else {
            $parsed = parse_url($token, PHP_URL_PATH);
            $path = is_string($parsed) ? $parsed : null;
        }

        if (is_string($path) && preg_match('#^/verify/resident/([^/]+)$#', $path, $matches) === 1) {
            $token = urldecode($matches[1]);
        }

        $resident = Resident::query()
            ->with(['barangay', 'purok'])
            ->where('barangay_id', $user->barangay_id)
            ->where(function ($query) use ($token) {
                $query->where('resident_number', strtoupper($token))
                    ->orWhereHas('qrCredential', function ($query) use ($token) {
                        $query->where('token', $token)
                            ->where('is_active', true)
                            ->whereNull('revoked_at');
                    });
            })
            ->first();

        if (! $resident || $resident->status !== 'active') {
            return response()->json([
                'message' => 'No active resident found matching this QR code in your barangay.',
            ], 404);
        }

        $address = trim(implode(', ', array_filter([
            $resident->address_line,
            $resident->purok?->name,
            $resident->barangay?->name,
        ])));

        return response()->json([
            'resident' => [
                'id' => $resident->id,
                'name' => $resident->full_name,
                'resident_number' => $resident->resident_number,
                'address' => $address,
                'contact' => $resident->mobile,
            ],
        ]);
    }

    /**
     * Store a manually encoded Lupon case (e.g. walk-in).
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->staffAssignments()->where('department', 'lupon')->where('is_active', true)->exists()) {
            abort(403, 'Unauthorized.');
        }

        $validated = $request->validate([
            'case_type' => ['required', 'string', Rule::in(self::CASE_TYPES)],
            'custom_case_type' => ['nullable', 'required_if:case_type,Other', 'string', 'max:80'],
            'incident_date' => ['required', 'date', 'before_or_equal:today'],
            'incident_location' => ['required', 'string', 'max:500'],
            'narrative' => ['required', 'string', 'max:5000'],
            'requested_action' => ['required', 'string', 'max:2000'],
            // Primary Complainant
            'complainant_name' => ['required', 'string', 'max:255'],
            'complainant_is_resident' => ['required', 'boolean'],
            'complainant_resident_id' => [
                'nullable',
                Rule::exists('residents', 'id')->where(fn ($q) => $q->where('barangay_id', $user->barangay_id)),
            ],
            'complainant_address' => ['nullable', 'string', 'max:500'],
            'complainant_contact' => ['nullable', 'string', 'max:50'],
            // Primary Respondent
            'respondent_name' => ['required', 'string', 'max:255'],
            'respondent_is_resident' => ['required', 'boolean'],
            'respondent_resident_id' => [
                'nullable',
                Rule::exists('residents', 'id')->where(fn ($q) => $q->where('barangay_id', $user->barangay_id)),
            ],
            'respondent_address' => ['nullable', 'string', 'max:500'],
            'respondent_contact' => ['nullable', 'string', 'max:50'],
        ]);

        if (
            ! empty($validated['complainant_resident_id']) &&
            ! empty($validated['respondent_resident_id']) &&
            $validated['complainant_resident_id'] == $validated['respondent_resident_id']
        ) {
            return redirect()->back()->withErrors([
                'respondent_resident_id' => 'Complainant and Respondent cannot be the same registered resident.',
            ]);
        }

        $resolvedCaseType = $validated['case_type'] === 'Other'
            ? 'Other: '.trim($validated['custom_case_type'])
            : $validated['case_type'];

        $newCase = $this->createManualCaseWithRetry($validated, $resolvedCaseType, $user, $request);

        return redirect()->route('lupon.cases.show', $newCase->id)
            ->with('success', "Case {$newCase->case_number} successfully encoded.");
    }

    private function createManualCaseWithRetry(array $validated, string $resolvedCaseType, $user, Request $request): LuponCase
    {
        $attempts = 0;
        $maxAttempts = 5;

        while ($attempts < $maxAttempts) {
            try {
                return DB::transaction(function () use ($validated, $resolvedCaseType, $user, $request) {
                    $caseNumber = $this->generateCaseNumber($user->barangay_id);

                    $case = LuponCase::create([
                        'barangay_id' => $user->barangay_id,
                        'complaint_id' => null,
                        'origin' => 'manual',
                        'case_number' => $caseNumber,
                        'title' => "{$resolvedCaseType}: {$validated['complainant_name']} vs. {$validated['respondent_name']}",
                        'case_type' => $resolvedCaseType,
                        'phase' => 'Kapitan',
                        'status' => 'Under Mediation',
                        'created_by' => $user->id,
                        'assigned_to' => $user->id,
                        'filed_date' => now()->toDateString(),
                    ]);

                    // Primary Complainant
                    LuponCaseParty::create([
                        'case_id' => $case->id,
                        'party_type' => 'complainant',
                        'resident_id' => $validated['complainant_resident_id'] ?? null,
                        'name' => $validated['complainant_name'],
                        'is_resident' => (bool) $validated['complainant_is_resident'],
                        'address' => $validated['complainant_address'] ?? null,
                        'contact_number' => $validated['complainant_contact'] ?? null,
                        'is_primary' => true,
                    ]);

                    // Primary Respondent
                    $respondentResidentId = $validated['respondent_resident_id'] ?? null;
                    $assocStatus = 'unlinked';
                    $associatedBy = null;
                    $verifiedBy = null;
                    $verifiedAt = null;

                    if ($respondentResidentId) {
                        $assocStatus = 'verified';
                        $associatedBy = $user->id;
                        $verifiedBy = $user->id;
                        $verifiedAt = now();
                    }

                    LuponCaseParty::create([
                        'case_id' => $case->id,
                        'party_type' => 'respondent',
                        'resident_id' => $respondentResidentId,
                        'name' => $validated['respondent_name'],
                        'is_resident' => (bool) $validated['respondent_is_resident'],
                        'association_status' => $assocStatus,
                        'associated_by' => $associatedBy,
                        'verified_by' => $verifiedBy,
                        'verified_at' => $verifiedAt,
                        'address' => $validated['respondent_address'] ?? null,
                        'contact_number' => $validated['respondent_contact'] ?? null,
                        'is_primary' => true,
                    ]);

                    AuditLogger::record(
                        event: 'lupon_case.created_manual',
                        barangayId: $user->barangay_id,
                        actorId: $user->id,
                        entityType: 'lupon_case',
                        entityId: $case->id,
                        context: [
                            'action' => 'created_manual',
                            'entity_reference' => $case->case_number,
                            'new_status' => 'Under Mediation',
                        ],
                        ipAddress: $request->ip()
                    );

                    return $case;
                });
            } catch (QueryException $exception) {
                if ($this->isUniqueViolation($exception)) {
                    $attempts++;

                    continue;
                }
                throw $exception;
            }
        }

        throw new \RuntimeException('Unable to allocate a unique case number. Please retry.');
    }

    /**
     * Show case detail view.
     */
    public function show(Request $request, LuponCase $case): Response
    {
        $user = $request->user();

        if (! $user->staffAssignments()->where('department', 'lupon')->where('is_active', true)->exists()) {
            abort(403, 'Unauthorized access to Lupon cases.');
        }

        if ($case->barangay_id !== $user->barangay_id) {
            abort(403, 'Unauthorized access to case from another barangay.');
        }

        $loadCase = function () use ($case): void {
            $case->load([
                'complaint',
                'parties.resident',
                'hearings.officer',
                'settlement.recorder',
                'creator',
                'assignedStaff',
            ]);
            $case->setRelation('complainants', $case->parties->where('party_type', 'complainant')->values());
            $case->setRelation('respondents', $case->parties->where('party_type', 'respondent')->values());
        };

        $barangayResidents = fn () => Resident::query()
            ->where('barangay_id', $user->barangay_id)
            ->where('status', 'active')
            ->select('id', 'first_name', 'middle_name', 'last_name', 'resident_number')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->full_name,
                'residentNumber' => $r->resident_number,
            ]);

        return Inertia::render('Lupon/Cases/Show', [
            'caseData' => $this->deferProp(function () use ($case, $loadCase): array {
                $loadCase();

                return [
                    'id' => $case->id,
                    'caseNumber' => $case->case_number,
                    'origin' => $case->origin,
                    'title' => $case->title,
                    'caseType' => $case->case_type,
                    'phase' => $case->phase,
                    'status' => $case->status,
                    'filedDate' => $case->filed_date->format('F j, Y'),
                    'promotedAt' => $case->promoted_at?->format('F j, Y g:i A'),
                    'createdByName' => $case->creator?->name,
                    'assignedStaffName' => $case->assignedStaff?->name,
                    'complaint' => $case->complaint ? [
                        'id' => $case->complaint->id,
                        'reference' => $case->complaint->reference,
                        'incidentDate' => $case->complaint->incident_date?->format('F j, Y'),
                        'incidentLocation' => $case->complaint->incident_location,
                        'narrative' => $case->complaint->narrative,
                        'requestedAction' => $case->complaint->requested_action,
                        'luponNote' => $case->complaint->lupon_note,
                    ] : null,
                    'complainants' => $case->complainants->map(fn ($p) => [
                        'id' => $p->id,
                        'name' => $p->name,
                        'isResident' => $p->is_resident,
                        'residentNumber' => $p->resident?->resident_number,
                        'address' => $p->address,
                        'contactNumber' => $p->contact_number,
                        'isPrimary' => $p->is_primary,
                    ]),
                    'respondents' => $case->respondents->map(fn ($p) => [
                        'id' => $p->id,
                        'name' => $p->name,
                        'isResident' => $p->is_resident,
                        'residentId' => $p->resident_id,
                        'residentName' => $p->resident?->full_name,
                        'residentNumber' => $p->resident?->resident_number,
                        'associationStatus' => $p->association_status,
                        'verifiedAt' => $p->verified_at?->format('F j, Y'),
                        'address' => $p->address,
                        'contactNumber' => $p->contact_number,
                        'isPrimary' => $p->is_primary,
                    ]),
                    'hearings' => $case->hearings->map(fn ($h) => [
                        'id' => $h->id,
                        'hearingNumber' => $h->hearing_number,
                        'phase' => $h->phase,
                        'date' => $h->hearing_date->format('F j, Y'),
                        'time' => $h->hearing_time,
                        'venue' => $h->venue,
                        'status' => $h->status,
                        'outcome' => $h->outcome,
                        'notes' => $h->notes,
                        'officerName' => $h->officer?->name,
                    ]),
                    'settlement' => $case->settlement ? [
                        'id' => $case->settlement->id,
                        'settlementType' => $case->settlement->settlement_type,
                        'settlementDate' => $case->settlement->settlement_date->format('F j, Y'),
                        'terms' => $case->settlement->terms,
                        'status' => $case->settlement->status,
                        'recordedByName' => $case->settlement->recorder?->name,
                    ] : null,
                ];
            }),
            'barangayResidents' => $this->deferProp($barangayResidents),
            'availablePhases' => self::PHASES,
            'availableStatuses' => self::STATUSES,
        ]);
    }

    /**
     * Promote an Accepted complaint into an active LuponCase.
     */
    public function promote(Request $request, LuponComplaint $complaint): RedirectResponse
    {
        $user = $request->user();

        if (! $user->staffAssignments()->where('department', 'lupon')->where('is_active', true)->exists()) {
            abort(403, 'Unauthorized access.');
        }

        if ($complaint->barangay_id !== $user->barangay_id) {
            abort(403, 'Unauthorized access to complaint.');
        }

        if ($complaint->status !== 'Accepted') {
            return redirect()->back()->withErrors([
                'complaint' => 'Only complaints with "Accepted" status can be promoted to a case.',
            ]);
        }

        // Prevent duplicate promotion
        if ($complaint->case()->exists()) {
            return redirect()->back()->withErrors([
                'complaint' => 'This complaint has already been promoted to a case.',
            ]);
        }

        $case = $this->createCaseWithRetry($complaint, $user, $request);

        return redirect()->route('lupon.cases.show', $case->id)
            ->with('success', "Complaint successfully promoted to Case {$case->case_number}.");
    }

    /**
     * Create case inside a transaction with retry on unique constraint collision.
     */
    private function createCaseWithRetry(LuponComplaint $complaint, $user, Request $request): LuponCase
    {
        $attempts = 0;
        $maxAttempts = 5;

        while ($attempts < $maxAttempts) {
            try {
                return DB::transaction(function () use ($complaint, $user, $request) {
                    $complaint->loadMissing(['resident.purok:id,name', 'barangay:id,name']);

                    $caseNumber = $this->generateCaseNumber($complaint->barangay_id);

                    $newCase = LuponCase::create([
                        'barangay_id' => $complaint->barangay_id,
                        'complaint_id' => $complaint->id,
                        'origin' => 'promoted',
                        'case_number' => $caseNumber,
                        'title' => "{$complaint->complaint_type}: {$complaint->resident->full_name} vs. {$complaint->respondent_name}",
                        'case_type' => $complaint->complaint_type,
                        'phase' => 'Kapitan',
                        'status' => 'Under Mediation',
                        'created_by' => $user->id,
                        'assigned_to' => $user->id,
                        'filed_date' => now()->toDateString(),
                        'promoted_at' => now(),
                    ]);

                    // Add primary complainant
                    LuponCaseParty::create([
                        'case_id' => $newCase->id,
                        'party_type' => 'complainant',
                        'resident_id' => $complaint->resident_id,
                        'name' => $complaint->resident->full_name,
                        'is_resident' => true,
                        'address' => trim(implode(', ', array_filter([$complaint->resident->purok?->name, $complaint->barangay?->name]))),
                        'contact_number' => $complaint->resident->mobile,
                        'is_primary' => true,
                    ]);

                    // Add primary respondent (unlinked by default)
                    LuponCaseParty::create([
                        'case_id' => $newCase->id,
                        'party_type' => 'respondent',
                        'resident_id' => null,
                        'name' => $complaint->respondent_name,
                        'is_resident' => $complaint->respondent_resident_status === 'Yes',
                        'association_status' => 'unlinked',
                        'address' => $complaint->respondent_address,
                        'contact_number' => $complaint->respondent_contact,
                        'is_primary' => true,
                    ]);

                    AuditLogger::record(
                        event: 'lupon_case.promoted',
                        barangayId: $user->barangay_id,
                        actorId: $user->id,
                        entityType: 'lupon_case',
                        entityId: $newCase->id,
                        context: [
                            'action' => 'promoted',
                            'entity_reference' => $newCase->case_number,
                            'new_status' => 'Under Mediation',
                        ],
                        ipAddress: $request->ip()
                    );

                    return $newCase;
                });
            } catch (QueryException $exception) {
                if ($this->isUniqueViolation($exception)) {
                    $attempts++;

                    continue;
                }

                throw $exception;
            }
        }

        throw new \RuntimeException('Unable to allocate a unique case number. Please retry.');
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'UNIQUE')
            || str_contains($message, 'Duplicate entry')
            || str_contains($message, 'SQLSTATE[23000]')
            || str_contains($message, 'SQLSTATE[23505]');
    }

    /**
     * Add an additional complainant (supporting M2 multi-complainant).
     */
    public function addComplainant(Request $request, LuponCase $case): RedirectResponse
    {
        $user = $request->user();

        if (! $user->staffAssignments()->where('department', 'lupon')->where('is_active', true)->exists()) {
            abort(403, 'Unauthorized.');
        }

        if ($case->barangay_id !== $user->barangay_id) {
            abort(403, 'Unauthorized.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'contact_number' => ['nullable', 'string', 'max:50'],
            'resident_id' => [
                'nullable',
                Rule::exists('residents', 'id')->where(fn ($query) => $query->where('barangay_id', $case->barangay_id)),
            ],
        ], [
            'resident_id.exists' => 'The selected resident does not belong to this barangay.',
        ]);

        LuponCaseParty::create([
            'case_id' => $case->id,
            'party_type' => 'complainant',
            'resident_id' => $validated['resident_id'] ?? null,
            'name' => $validated['name'],
            'is_resident' => ! empty($validated['resident_id']),
            'address' => $validated['address'] ?? null,
            'contact_number' => $validated['contact_number'] ?? null,
            'is_primary' => false,
        ]);

        AuditLogger::record(
            event: 'lupon_case.party_added',
            barangayId: $user->barangay_id,
            actorId: $user->id,
            entityType: 'lupon_case',
            entityId: $case->id,
            context: [
                'action' => 'party_added',
                'entity_reference' => $case->case_number,
            ],
            ipAddress: $request->ip()
        );

        return redirect()->back()->with('success', 'Additional complainant added to case.');
    }

    /**
     * Associate and verify a respondent resident account.
     */
    public function associateRespondent(Request $request, LuponCaseParty $party): RedirectResponse
    {
        $user = $request->user();

        if (! $user->staffAssignments()->where('department', 'lupon')->where('is_active', true)->exists()) {
            abort(403, 'Unauthorized.');
        }

        if ($party->party_type !== 'respondent') {
            abort(400, 'Invalid party type for respondent association.');
        }

        $case = $party->case;
        if ($case->barangay_id !== $user->barangay_id) {
            abort(403, 'Unauthorized access.');
        }

        $validated = $request->validate([
            'resident_id' => [
                'required_if:action,link_verify',
                'nullable',
                Rule::exists('residents', 'id')->where(fn ($q) => $q->where('barangay_id', $case->barangay_id)),
            ],
            'action' => ['required', 'string', 'in:link_verify,unlink'],
            'verification_notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'resident_id.exists' => 'The selected resident does not belong to this barangay.',
        ]);

        if ($validated['action'] === 'unlink') {
            $party->update([
                'resident_id' => null,
                'association_status' => 'unlinked',
                'associated_by' => null,
                'verified_by' => null,
                'verified_at' => null,
                'verification_notes' => $validated['verification_notes'] ?? 'Association unlinked by staff.',
            ]);

            AuditLogger::record(
                event: 'lupon_respondent.unlinked',
                barangayId: $user->barangay_id,
                actorId: $user->id,
                entityType: 'lupon_case',
                entityId: $case->id,
                context: [
                    'action' => 'respondent_unlinked',
                    'entity_reference' => $case->case_number,
                ],
                ipAddress: $request->ip()
            );

            return redirect()->back()->with('success', 'Respondent account association unlinked.');
        }

        $party->update([
            'resident_id' => $validated['resident_id'],
            'is_resident' => true,
            'association_status' => 'verified',
            'associated_by' => $user->id,
            'verified_by' => $user->id,
            'verified_at' => now(),
            'verification_notes' => $validated['verification_notes'] ?? 'Verified by Lupon Staff.',
        ]);

        AuditLogger::record(
            event: 'lupon_respondent.verified',
            barangayId: $user->barangay_id,
            actorId: $user->id,
            entityType: 'lupon_case',
            entityId: $case->id,
            context: [
                'action' => 'respondent_verified',
                'entity_reference' => $case->case_number,
                'new_status' => 'verified',
            ],
            ipAddress: $request->ip()
        );

        return redirect()->back()->with('success', 'Respondent account verified and linked successfully.');
    }

    /**
     * Update case phase or status.
     */
    public function updateStatus(Request $request, LuponCase $case): RedirectResponse
    {
        $user = $request->user();

        if (! $user->staffAssignments()->where('department', 'lupon')->where('is_active', true)->exists()) {
            abort(403, 'Unauthorized.');
        }

        if ($case->barangay_id !== $user->barangay_id) {
            abort(403, 'Unauthorized.');
        }

        $validated = $request->validate([
            'phase' => ['required', 'string', 'in:'.implode(',', self::PHASES)],
            'status' => ['required', 'string', 'in:'.implode(',', self::STATUSES)],
        ]);

        $previousStatus = $case->status;

        $case->update([
            'phase' => $validated['phase'],
            'status' => $validated['status'],
        ]);

        AuditLogger::record(
            event: 'lupon_case.status_updated',
            barangayId: $user->barangay_id,
            actorId: $user->id,
            entityType: 'lupon_case',
            entityId: $case->id,
            context: [
                'action' => 'status_updated',
                'entity_reference' => $case->case_number,
                'previous_status' => $previousStatus,
                'new_status' => $validated['status'],
            ],
            ipAddress: $request->ip()
        );

        return redirect()->back()->with('success', 'Case status updated successfully.');
    }

    private function generateCaseNumber(int $barangayId): string
    {
        $year = now()->year;
        $prefix = "KP-{$year}-";

        $lastNumber = LuponCase::where('barangay_id', $barangayId)
            ->where('case_number', 'like', "{$prefix}%")
            ->orderByDesc('id')
            ->value('case_number');

        $sequence = 1;
        if ($lastNumber && preg_match("/{$prefix}(\d+)/", $lastNumber, $matches)) {
            $sequence = ((int) $matches[1]) + 1;
        }

        do {
            $candidate = $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
            $sequence++;
        } while (LuponCase::where('case_number', $candidate)->exists());

        return $candidate;
    }
}
