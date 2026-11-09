<?php

namespace App\Http\Controllers\Lupon;

use App\Http\Controllers\Controller;
use App\Models\Barangay;
use App\Models\LuponCase;
use App\Models\LuponCaseParty;
use App\Models\LuponComplaint;
use App\Models\Resident;
use App\Support\AuditLogger;
use App\Support\CertificateOfficials;
use App\Support\LuponAccess;
use App\Support\LuponCache;
use App\Support\LuponCasePresenter;
use App\Support\LuponCaseWorkflow;
use App\Support\LuponCatalog;
use App\Support\LuponDocket;
use App\Support\LuponDocuments;
use App\Support\LuponEvidenceStore;
use App\Support\LuponReferences;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LuponCaseController extends Controller
{
    private const PHASES = ['Kapitan', 'Lupon', 'Arbitration', 'Closed'];

    private const STATUSES = ['filed', 'mediation_active', 'pangkat_formed', 'conciliation_active', 'settled', 'repudiated', 'dismissed', 'certified_to_file_action', 'arbitration_active', 'arbitration_awarded', 'executed', 'court_forwarded'];

    private const STAGES = ['all', 'mediation', 'conciliation', 'arbitration', 'resolved'];

    private const STAGE_PHASES = [
        'mediation' => 'Kapitan',
        'conciliation' => 'Lupon',
        'arbitration' => 'Arbitration',
        'resolved' => 'Closed',
    ];

    private const CASE_TYPES = LuponCatalog::CASE_TYPES;

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
        $statusFilter = is_string($request->query('status')) ? LuponCaseWorkflow::normalize($request->query('status')) : null;
        $phaseFilter = $request->query('phase');
        $stageFilter = in_array($request->query('stage'), self::STAGES, true) ? $request->query('stage') : 'all';

        $sort = in_array($request->query('sort'), ['oldest', 'case_number'], true) ? $request->query('sort') : 'newest';
        $query = LuponCase::query()
            ->where('barangay_id', $user->barangay_id)
            ->with([
                'parties', 'complaint:id,reference',
                'hearings' => fn ($q) => $q->latest('hearing_date')->limit(1),
            ])
            ->orderBy($sort === 'case_number' ? 'case_number' : 'filed_date', $sort === 'newest' ? 'desc' : 'asc')->orderBy('id', 'desc');

        if (is_string($search) && trim($search) !== '') {
            $trimmed = trim($search);
            $query->where(function ($q) use ($trimmed) {
                $q->where('case_number', 'like', "%{$trimmed}%")
                    ->orWhere('title', 'like', "%{$trimmed}%")
                    ->orWhereHas('complaint', fn ($cq) => $cq->where('reference', 'like', "%{$trimmed}%"))
                    ->orWhereHas('parties', fn ($pq) => $pq->where('name', 'like', "%{$trimmed}%"));
            });
        }

        if (is_string($statusFilter) && in_array($statusFilter, self::STATUSES, true)) {
            $query->where('status', $statusFilter);
        }

        if (is_string($phaseFilter) && in_array($phaseFilter, self::PHASES, true)) {
            $query->where('phase', $phaseFilter);
        }

        if ($stageFilter !== 'all') {
            $query->where('phase', self::STAGE_PHASES[$stageFilter]);
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
                    'complaintReference' => $case->complaint?->reference,
                    'title' => $case->title,
                    'caseType' => $case->case_type,
                    'phase' => $case->phase,
                    'status' => $case->status,
                    'paymentStatus' => $case->payment_status,
                    'filedDate' => $case->filed_date->format('F j, Y'),
                    'complainantName' => $primaryComplainant?->name ?? 'Unknown',
                    'respondentName' => $primaryRespondent?->name ?? 'Unknown',
                    'complainantsCount' => $case->complainants->count(),
                    'respondentsCount' => $case->respondents->count(),
                    'latestHearing' => $latestHearing ? [
                        'number' => $latestHearing->phase_number,
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
                'stage' => $stageFilter,
                'sort' => $sort,
            ],
            'counts' => $this->deferProp(fn () => $this->rememberAggregate(
                "lupon:cases:counts:v3:{$barangayId}",
                30,
                function () use ($barangayId): array {
                    $counts = LuponCase::query()
                        ->where('barangay_id', $barangayId)
                        ->selectRaw("COUNT(*) as total, COUNT(CASE WHEN phase = 'Kapitan' THEN 1 END) as mediation, COUNT(CASE WHEN phase = 'Lupon' THEN 1 END) as conciliation, COUNT(CASE WHEN phase = 'Arbitration' THEN 1 END) as arbitration, COUNT(CASE WHEN phase = 'Closed' THEN 1 END) as resolved, COUNT(CASE WHEN status IN ('filed', 'mediation_active', 'pangkat_formed', 'conciliation_active') THEN 1 END) as active, COUNT(CASE WHEN status = 'settled' THEN 1 END) as settled, COUNT(CASE WHEN status IN ('repudiated', 'certified_to_file_action') THEN 1 END) as unresolved")
                        ->first();

                    return [
                        'total' => (int) $counts->total,
                        'active' => (int) $counts->active,
                        'settled' => (int) $counts->settled,
                        'unresolved' => (int) $counts->unresolved,
                        'mediation' => (int) $counts->mediation,
                        'conciliation' => (int) $counts->conciliation,
                        'arbitration' => (int) $counts->arbitration,
                        'resolved' => (int) $counts->resolved,
                    ];
                },
            )),
            'canCreateCases' => LuponAccess::canVerify($request),
            'phases' => self::PHASES,
            'statuses' => self::STATUSES,
        ]);
    }

    /**
     * Show form for manually creating a walk-in / direct Lupon case.
     */
    public function create(Request $request): Response
    {
        LuponAccess::secretary($request);

        return Inertia::render('Lupon/Cases/Create', ['caseTypes' => self::CASE_TYPES, 'officials' => CertificateOfficials::resolve($request->user()->barangay_id)]);
    }

    public function residentLookup(Request $request, Resident $resident): JsonResponse
    {
        LuponAccess::staff($request);
        abort_unless((int) $resident->barangay_id === (int) $request->user()->barangay_id && $resident->status === 'active', 404);
        $resident->loadMissing('purok');

        return response()->json(['resident' => ['id' => $resident->id, 'name' => $resident->full_name, 'resident_number' => $resident->resident_number, 'address' => trim(implode(', ', array_filter([$resident->address_line, $resident->purok?->name]))), 'contact' => $resident->mobile]], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function qrLookup(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->staffAssignments()->where('department', 'lupon')->where('is_active', true)->exists()) {
            abort(403, 'Unauthorized access.');
        }

        $token = $request->query('token');

        if (! $token || ! is_string($token) || trim($token) === '' || mb_strlen($token) > 2048) {
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
            ->with('purok')
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

        return $this->residentLookup($request, $resident);
    }

    /**
     * Store a manually encoded Lupon case (e.g. walk-in).
     */
    public function store(Request $request): RedirectResponse
    {
        LuponAccess::secretary($request);
        $user = $request->user();

        if (! $user->staffAssignments()->where('department', 'lupon')->where('is_active', true)->exists()) {
            abort(403, 'Unauthorized.');
        }

        if (is_string($request->input('or_number'))) {
            $request->merge(['or_number' => strtoupper(trim($request->input('or_number')))]);
        }

        $validated = $request->validate([
            'payment_verified' => ['required', 'accepted'],
            'fee_amount' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'or_number' => ['required', 'string', 'max:100', Rule::unique('lupon_cases', 'or_number')->where('barangay_id', $user->barangay_id)],
            'payment_date' => ['required', 'date', 'before_or_equal:'.LuponCaseWorkflow::today()],
            'verification_note' => ['required', 'string', 'max:2000'],
            'case_type' => ['required', 'string', Rule::in(self::CASE_TYPES)],
            'acting_captain_id' => ['nullable', 'integer', 'min:1'],
            'hearing_date' => ['nullable', 'date', 'after_or_equal:'.LuponCaseWorkflow::today()],
            'hearing_time' => ['nullable', 'required_with:hearing_date', 'date_format:H:i'],
            'venue' => ['nullable', 'string', 'max:255'],
            'evidence' => LuponCatalog::EVIDENCE_RULES,
            'evidence.*' => LuponCatalog::FILE_RULES,
            'custom_case_type' => ['nullable', 'required_if:case_type,Other', 'string', 'max:80'],
            'incident_date' => ['required', 'date', 'before_or_equal:'.LuponCaseWorkflow::today()],
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

        $validated = LuponDocket::party(LuponDocket::party($validated, 'complainant', $user->barangay_id), 'respondent', $user->barangay_id);
        CertificateOfficials::resolve($user->barangay_id, filled($validated['acting_captain_id'] ?? null) ? (int) $validated['acting_captain_id'] : null);

        $resolvedCaseType = $validated['case_type'] === 'Other'
            ? 'Other: '.trim($validated['custom_case_type'])
            : $validated['case_type'];

        $newCase = $this->createManualCaseWithRetry($validated, $resolvedCaseType, $user, $request);
        LuponCache::forget((int) $user->barangay_id);

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
                    Barangay::whereKey($user->barangay_id)->lockForUpdate()->firstOrFail();
                    if (LuponCase::where('barangay_id', $user->barangay_id)->where('or_number', $validated['or_number'])->lockForUpdate()->exists()) {
                        throw ValidationException::withMessages(['or_number' => 'This OR number is already recorded in your barangay.']);
                    }
                    $complaint = LuponComplaint::create([
                        'barangay_id' => $user->barangay_id, 'resident_id' => $validated['complainant_resident_id'] ?? null,
                        'reference' => LuponReferences::next('LUP-'.Carbon::parse(LuponCaseWorkflow::today())->year.'-'),
                        'complainant_snapshot' => ['name' => $validated['complainant_name'], 'address' => $validated['complainant_address'] ?? null, 'contact' => $validated['complainant_contact'] ?? null],
                        'respondent_name' => $validated['respondent_name'], 'respondent_resident_status' => $validated['respondent_is_resident'] ? 'Yes' : 'No',
                        'respondent_address' => $validated['respondent_address'] ?? null, 'respondent_contact' => $validated['respondent_contact'] ?? null,
                        'complaint_type' => $resolvedCaseType, 'incident_date' => $validated['incident_date'], 'incident_location' => $validated['incident_location'],
                        'narrative' => $validated['narrative'], 'requested_action' => $validated['requested_action'], 'submitted_at' => now(), 'status' => 'Payment Verified',
                        'assessed_fee' => $validated['fee_amount'], 'payment_verification' => ['actor_id' => $user->id, 'verified_at' => now()->toIso8601String(), 'payment_date' => $validated['payment_date'], 'or_number' => $validated['or_number'], 'amount' => $validated['fee_amount'], 'verification_note' => $validated['verification_note']],
                    ]);
                    $caseNumber = $this->generateCaseNumber($user->barangay_id);

                    $case = LuponCase::create([
                        'barangay_id' => $user->barangay_id,
                        'complaint_id' => $complaint->id,
                        'origin' => 'manual',
                        'payment_status' => 'paid', 'payment_verification' => ['actor_id' => $user->id, 'verified_at' => now()->toIso8601String(), 'payment_date' => $validated['payment_date'], 'verification_note' => $validated['verification_note']], 'fee_amount' => $validated['fee_amount'], 'or_number' => $validated['or_number'], 'paid_at' => $validated['payment_date'],
                        'acting_captain_id' => $validated['acting_captain_id'] ?? null,
                        'intake_narrative' => $validated['narrative'],
                        'intake_requested_action' => $validated['requested_action'],
                        'incident_date' => $validated['incident_date'],
                        'incident_location' => $validated['incident_location'],
                        'case_number' => $caseNumber,
                        'title' => "{$resolvedCaseType}: {$validated['complainant_name']} vs. {$validated['respondent_name']}",
                        'case_type' => $resolvedCaseType,
                        'phase' => 'Kapitan',
                        'status' => 'filed',
                        'created_by' => $user->id,
                        'assigned_to' => $user->id,
                        'filed_date' => LuponCaseWorkflow::today(),
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
                            'new_status' => 'filed',
                        ],
                        ipAddress: $request->ip()
                    );

                    LuponDocket::finish($case, $validated, $user->id);
                    LuponEvidenceStore::store($request->file('evidence', []), $complaint, $case, null, $user->id);

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

        return Inertia::render('Lupon/Cases/Show', [
            'caseData' => $this->deferProp(function () use ($case, $loadCase, $request): array {
                $loadCase();

                return [
                    'id' => $case->id,
                    'canVerifyPayments' => LuponAccess::canVerify($request),
                    'caseNumber' => $case->case_number,
                    'origin' => $case->origin,
                    'title' => $case->title,
                    'caseType' => $case->case_type,
                    'phase' => $case->phase,
                    'status' => $case->status,
                    ...LuponCasePresenter::details($case),
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
                        'hearingNumber' => $h->phase_number,
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
            }),            'availablePhases' => self::PHASES,
            'availableStatuses' => self::STATUSES,
            'officials' => CertificateOfficials::resolve($user->barangay_id),
        ]);
    }

    /**
     * Verify an actual paid receipt and docket the online complaint atomically.
     */
    public function promote(Request $request, LuponComplaint $complaint): RedirectResponse
    {
        LuponAccess::secretary($request);
        $user = $request->user();

        if (! $user->staffAssignments()->where('department', 'lupon')->where('is_active', true)->exists()) {
            abort(403, 'Unauthorized access.');
        }

        if ($complaint->barangay_id !== $user->barangay_id) {
            abort(403, 'Unauthorized access to complaint.');
        }

        if ($complaint->case()->exists()) {
            return back()->with('success', 'This complaint already has an official case.');
        }

        if (! in_array($complaint->status, ['Pending Payment', 'Payment Verification', 'For Payment'], true) || ! $complaint->assessed_fee || (float) $complaint->assessed_fee <= 0) {
            return redirect()->back()->withErrors([
                'complaint' => 'Complete the complaint review and assess the filing fee before recording payment and creating a case.',
            ]);
        }

        // Prevent duplicate promotion
        if ($complaint->case()->exists()) {
            return redirect()->back()->withErrors([
                'complaint' => 'This complaint has already been promoted to a case.',
            ]);
        }

        if (is_string($request->input('or_number'))) {
            $request->merge(['or_number' => strtoupper(trim($request->input('or_number')))]);
        }
        $request->validate([
            'or_number' => ['required', 'string', 'max:100', Rule::unique('lupon_cases', 'or_number')->where('barangay_id', $user->barangay_id)],
            'acting_captain_id' => ['nullable', 'integer', 'min:1'],
            'payment_verified' => ['required', 'accepted'],
            'payment_date' => ['required', 'date', 'before_or_equal:'.LuponCaseWorkflow::today()],
            'verification_note' => ['required', 'string', 'max:2000'],
            'hearing_date' => ['nullable', 'date', 'after_or_equal:'.LuponCaseWorkflow::today()],
            'hearing_time' => ['required_with:hearing_date', 'nullable', 'date_format:H:i'],
            'venue' => ['required_with:hearing_date', 'nullable', 'string', 'max:255'],
        ]);
        CertificateOfficials::resolve($user->barangay_id, $request->filled('acting_captain_id') ? $request->integer('acting_captain_id') : null);
        $case = $this->createCaseWithRetry($complaint, $user, $request);
        LuponCache::forget((int) $user->barangay_id);

        return redirect()->back()
            ->with('success', "Payment verified and recorded. Official case {$case->case_number} created. Schedules are recorded separately.");
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
                    $complaint = LuponComplaint::whereKey($complaint->id)->where('barangay_id', $user->barangay_id)->lockForUpdate()->firstOrFail();
                    if ($existing = $complaint->case()->first()) {
                        return $existing;
                    }
                    if (! in_array($complaint->status, ['Pending Payment', 'Payment Verification', 'For Payment'], true) || (float) $complaint->assessed_fee <= 0) {
                        throw ValidationException::withMessages(['complaint' => 'This complaint is no longer awaiting payment. Refresh the queue.']);
                    }
                    // Serialize online receipt recording within this barangay.
                    Barangay::whereKey($user->barangay_id)->lockForUpdate()->firstOrFail();
                    if (LuponCase::where('barangay_id', $user->barangay_id)->where('or_number', $request->input('or_number'))->lockForUpdate()->exists()) {
                        throw ValidationException::withMessages(['or_number' => 'This OR number is already recorded in your barangay.']);
                    }
                    $complaint->loadMissing(['resident.purok:id,name', 'barangay:id,name']);

                    // A walk-in complainant has no resident record, so the party
                    // snapshot recorded at intake supplies the case title and the
                    // complainant party instead of the masterlist row.
                    $complainant = $complaint->resident;
                    $complainantName = $complainant?->full_name
                        ?? $complaint->complainant_snapshot['name']
                        ?? 'Walk-in complainant';
                    $complainantAddress = $complainant
                        ? trim(implode(', ', array_filter([$complainant->purok?->name, $complaint->barangay?->name])))
                        : ($complaint->complainant_snapshot['address'] ?? null);

                    $caseNumber = $this->generateCaseNumber($complaint->barangay_id);

                    $newCase = LuponCase::create([
                        'barangay_id' => $complaint->barangay_id,
                        'complaint_id' => $complaint->id,
                        'origin' => 'promoted',
                        'acting_captain_id' => $request->filled('acting_captain_id') ? $request->integer('acting_captain_id') : null,
                        'case_number' => $caseNumber,
                        'title' => "{$complaint->complaint_type}: {$complainantName} vs. {$complaint->respondent_name}",
                        'case_type' => $complaint->complaint_type,
                        'phase' => 'Kapitan',
                        'status' => 'filed',
                        'created_by' => $user->id,
                        'assigned_to' => $user->id,
                        'filed_date' => LuponCaseWorkflow::today(),
                        'promoted_at' => now(),
                        'payment_status' => 'paid',
                        'fee_amount' => $complaint->assessed_fee,
                        'or_number' => $request->input('or_number'),
                        'paid_at' => $request->input('payment_date'),
                    ]);

                    // Add primary complainant
                    LuponCaseParty::create([
                        'case_id' => $newCase->id,
                        'party_type' => 'complainant',
                        'resident_id' => $complaint->resident_id,
                        'name' => $complainantName,
                        'is_resident' => $complainant !== null,
                        'address' => $complainantAddress,
                        'contact_number' => $complainant?->mobile ?? ($complaint->complainant_snapshot['contact'] ?? null),
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
                            'new_status' => 'filed',
                        ],
                        ipAddress: $request->ip()
                    );

                    LuponDocket::finish($newCase, $request->only(['hearing_date', 'hearing_time', 'venue']), $user->id);
                    $complaint->update(['status' => 'Payment Verified', 'payment_verification' => ['actor_id' => $user->id, 'verified_at' => now()->toIso8601String(), 'payment_date' => $request->input('payment_date'), 'or_number' => $request->input('or_number'), 'amount' => $complaint->assessed_fee, 'verification_note' => $request->input('verification_note')]]);
                    AuditLogger::record('lupon_case.fee_verified', barangayId: $newCase->barangay_id,
                        actorId: $user->id, entityType: 'lupon_case', entityId: $newCase->id,
                        context: ['payment_status' => 'paid', 'case_number' => $newCase->case_number]);

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

        if (! in_array($case->status, ['filed', 'mediation_active', 'pangkat_formed', 'conciliation_active'], true)) {
            throw ValidationException::withMessages(['name' => 'Parties cannot be added to a closed case.']);
        }
        if (! empty($validated['resident_id'])) {
            $profile = Resident::with('purok')->where('barangay_id', $case->barangay_id)->where('status', 'active')->findOrFail($validated['resident_id']);
            if ($case->parties()->where('resident_id', $profile->id)->exists()) {
                throw ValidationException::withMessages(['resident_id' => 'This resident is already a party to this case.']);
            }
            $validated['name'] = $profile->full_name;
            $validated['address'] = trim(implode(', ', array_filter([$profile->address_line, $profile->purok?->name])));
            $validated['contact_number'] = $profile->mobile;
        }
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

        LuponDocuments::invalidateNotices($case);
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
                Rule::exists('residents', 'id')->where(fn ($q) => $q->where('barangay_id', $case->barangay_id)->where('status', 'active')),
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
            LuponDocuments::invalidateNotices($case);

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

        if ($case->parties()->where('id', '<>', $party->id)->where('resident_id', $validated['resident_id'])->exists()) {
            throw ValidationException::withMessages(['resident_id' => 'This resident is already another party to this case.']);
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
        LuponDocuments::invalidateNotices($case);

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
            'status' => ['required', 'string'], 'expected_status' => ['nullable', 'string'],
            'event_date' => ['nullable', 'date', 'before_or_equal:'.LuponCaseWorkflow::today()], 'notes' => ['nullable', 'string', 'max:5000'],
            'pangkat_members' => ['nullable', 'array', 'size:3'], 'pangkat_members.*' => ['string', 'max:255', 'distinct'],
            'pangkat_chair_name' => ['nullable', 'string', 'max:255'], 'pangkat_secretary_name' => ['nullable', 'string', 'max:255'],
            'repudiation_ground' => ['nullable', 'in:fraud,violence,intimidation'], 'sworn_statement_received' => ['nullable', 'boolean'],
            'first_hearing_date' => ['nullable', 'date', 'after_or_equal:'.LuponCaseWorkflow::today()], 'first_hearing_time' => ['required_with:first_hearing_date', 'nullable', 'date_format:H:i'], 'first_hearing_venue' => ['required_with:first_hearing_date', 'nullable', 'string', 'max:255'],
            'prerequisites_verified' => ['nullable', 'boolean'],
        ]);
        DB::transaction(function () use ($case, $validated, $user) {
            $changed = LuponCaseWorkflow::transition($case, $validated['status'], $user->id, $validated);
            if (! empty($validated['first_hearing_date'])) {
                if ($changed->status !== 'pangkat_formed' || $changed->hearings()->where('phase', 'Lupon')->exists()) {
                    throw ValidationException::withMessages(['first_hearing_date' => 'The first Lupon schedule belongs to a newly constituted Pangkat.']);
                }
                $date = Carbon::parse($validated['first_hearing_date']);
                if ($date->lt($changed->pangkat_formed_at->copy()->startOfDay()) || $date->gt($changed->pangkat_formed_at->copy()->addDays(3)->endOfDay())) {
                    throw ValidationException::withMessages(['first_hearing_date' => 'Set the first Pangkat hearing within three days of its constitution.']);
                }
                $hearing = $changed->hearings()->create(['barangay_id' => $changed->barangay_id, 'hearing_number' => ($changed->hearings()->max('hearing_number') ?? 0) + 1, 'phase' => 'Lupon', 'hearing_date' => $validated['first_hearing_date'], 'hearing_time' => $validated['first_hearing_time'], 'venue' => $validated['first_hearing_venue'], 'status' => 'Scheduled', 'officer_id' => $user->id]);
                LuponDocuments::queueNotices($changed, $hearing, $user->id);
                AuditLogger::record('lupon_hearing.scheduled', barangayId: $changed->barangay_id, actorId: $user->id, entityType: 'lupon_hearing', entityId: $hearing->id, context: ['phase' => 'Lupon', 'hearing_number' => 1]);
            }
            if ($changed->status === 'certified_to_file_action') {
                LuponDocuments::generate($changed, 'cfa', $user->id);
            }
        });
        LuponCache::forget((int) $user->barangay_id);

        return redirect()->back()->with('success', 'Case status updated successfully.');
    }

    private function generateCaseNumber(int $barangayId): string
    {
        $year = Carbon::parse(LuponCaseWorkflow::today())->year;
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
