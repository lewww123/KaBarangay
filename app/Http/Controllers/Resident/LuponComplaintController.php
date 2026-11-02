<?php

namespace App\Http\Controllers\Resident;

use App\Http\Controllers\Controller;
use App\Models\LuponCase;
use App\Models\LuponComplaint;
use App\Models\Resident;
use App\Support\AuditLogger;
use App\Support\LuponCaseWorkflow;
use App\Support\LuponCatalog;
use App\Support\LuponEvidenceStore;
use App\Support\ResidentContext;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LuponComplaintController extends Controller
{
    private const COMPLAINT_TYPES = LuponCatalog::CASE_TYPES;

    private const RESPONDENT_STATUSES = ['Yes', 'No', 'Unknown'];

    /**
     * Display the Lupon page for the logged-in resident with tabs:
     * - File a Complaint
     * - My Complaints
     * - Complaints Against Me (Verified Respondent tab)
     */
    public function index(): Response
    {
        $resident = ResidentContext::get();

        return Inertia::render('Resident/Lupon', [
            'resident' => $this->deferProp(function () use ($resident): array {
                $resident->loadMissing(['barangay', 'purok']);

                return [
                    'id' => $resident->id,
                    'fullName' => $resident->full_name,
                    'residentNumber' => $resident->resident_number,
                    'address' => trim(
                        implode(', ', array_filter([
                            $resident->purok?->name,
                            $resident->barangay?->name,
                        ]))
                    ),
                ];
            }),
            'complaintTypes' => self::COMPLAINT_TYPES,
            // 1. Complaints filed by this resident
            'complaints' => $this->deferProp(fn () => LuponComplaint::query()
                ->where('resident_id', $resident->id)
                ->where('barangay_id', $resident->barangay_id)
                ->with(['case', 'evidence'])
                ->latest('created_at')
                ->paginate(10, ['*'], 'complaints_page')
                ->withQueryString()
                ->through(function (LuponComplaint $complaint) {
                    return [
                        'id' => $complaint->id,
                        'reference' => $complaint->reference,
                        'respondent' => $complaint->respondent_name,
                        'respondentResidentStatus' => $complaint->respondent_resident_status,
                        'respondentAddress' => $complaint->respondent_address,
                        'respondentContact' => $complaint->respondent_contact,
                        'complaintType' => $complaint->complaint_type,
                        'incidentDate' => $complaint->incident_date?->format('F j, Y') ?? '',
                        'incidentTime' => $complaint->incident_time ?: null,
                        'incidentLocation' => $complaint->incident_location,
                        'narrative' => $complaint->narrative,
                        'requestedAction' => $complaint->requested_action,
                        'status' => $complaint->status,
                        'luponNote' => $complaint->lupon_note,
                        'submittedDate' => (
                            $complaint->submitted_at
                            ?? $complaint->created_at
                        )?->format('F j, Y') ?? '',
                        'trackingStatus' => $complaint->case ? LuponCaseWorkflow::tracking($complaint->case) : ($complaint->status === 'Submitted' ? 'Pending' : ($complaint->status === 'Rejected' ? 'Rejected' : 'Under Review')),
                        'evidence' => $complaint->evidence->map(fn ($file) => LuponEvidenceStore::summary($file, 'resident'))->all(),
                        'case' => $complaint->case ? [
                            'id' => $complaint->case->id,
                            'caseNumber' => $complaint->case->case_number,
                            'phase' => $complaint->case->phase,
                            'status' => $complaint->case->status,
                        ] : null,
                    ];
                })),
            // 2. Verified cases where this resident is a respondent (Complaints Against Me)
            'complaintsAgainstMe' => $this->deferProp(fn () => LuponCase::query()
                ->where('barangay_id', $resident->barangay_id)
                ->whereHas('respondents', function ($q) use ($resident) {
                    $q->where('resident_id', $resident->id)
                        ->where('association_status', 'verified');
                })
                ->with([
                    'complainants' => fn ($q) => $q->where('is_primary', true)->limit(1),
                    'hearings' => fn ($q) => $q->whereIn('status', ['Scheduled', 'Rescheduled'])->orderBy('hearing_date')->limit(1),
                ])
                ->latest('filed_date')
                ->paginate(10, ['*'], 'against_page')
                ->withQueryString()
                ->through(function (LuponCase $case) {
                    $complainant = $case->complainants->first();
                    $nextHearing = $case->hearings->first();

                    return [
                        'id' => $case->id,
                        'caseNumber' => $case->case_number,
                        'caseType' => $case->case_type,
                        'phase' => $case->phase,
                        'status' => $case->status,
                        'trackingStatus' => LuponCaseWorkflow::tracking($case),
                        'filedDate' => $case->filed_date->format('F j, Y'),
                        'complainantName' => $complainant?->name ?? 'Complainant',
                        'nextHearing' => $nextHearing ? [
                            'date' => $nextHearing->hearing_date->format('F j, Y'),
                            'time' => $nextHearing->hearing_time,
                            'venue' => $nextHearing->venue,
                        ] : null,
                    ];
                })),
        ]);
    }

    /**
     * Save a new complaint for review by the Lupong Tagapamayapa.
     */
    public function store(Request $request): RedirectResponse
    {
        $resident = Resident::query()
            ->where('id', session('resident_id'))
            ->where('status', 'active')
            ->firstOrFail();

        $validated = $request->validate([
            'evidence' => LuponCatalog::EVIDENCE_RULES,
            'evidence.*' => LuponCatalog::FILE_RULES,
            'respondentName' => ['required', 'string', 'max:255'],
            'respondentResidentStatus' => [
                'required',
                'string',
                Rule::in(self::RESPONDENT_STATUSES),
            ],
            'respondentAddress' => ['nullable', 'string', 'max:1000'],
            'respondentContact' => ['nullable', 'string', 'max:50'],
            'complaintType' => [
                'required',
                'string',
                Rule::in(self::COMPLAINT_TYPES),
            ],
            'incidentDate' => [
                'required',
                'date',
                'before_or_equal:'.LuponCaseWorkflow::today(),
            ],
            'incidentTime' => ['nullable', 'date_format:H:i'],
            'incidentLocation' => ['required', 'string', 'max:500'],
            'narrative' => ['required', 'string', 'max:5000'],
            'requestedAction' => ['required', 'string', 'max:2000'],
        ]);

        $attributes = [
            'barangay_id' => $resident->barangay_id,
            'resident_id' => $resident->id,
            'respondent_name' => $validated['respondentName'],
            'respondent_resident_status' => $validated['respondentResidentStatus'],
            'respondent_address' => $validated['respondentAddress'] ?? null,
            'respondent_contact' => $validated['respondentContact'] ?? null,
            'complaint_type' => $validated['complaintType'],
            'incident_date' => $validated['incidentDate'],
            'incident_time' => $validated['incidentTime'] ?? null,
            'incident_location' => $validated['incidentLocation'],
            'narrative' => $validated['narrative'],
            'requested_action' => $validated['requestedAction'],
            'status' => 'Submitted',
            'submitted_at' => now(),
        ];

        $complaint = DB::transaction(function () use ($attributes, $request, $resident) {
            $complaint = $this->createWithReference($attributes);
            LuponEvidenceStore::store($request->file('evidence', []), $complaint, null, $resident->id);

            return $complaint;
        });

        AuditLogger::record(
            event: 'lupon_complaint.submitted',
            barangayId: $resident->barangay_id,
            actorId: null,
            entityType: 'lupon_complaint',
            entityId: $complaint->id,
            context: [
                'action' => 'submitted',
                'entity_reference' => $complaint->reference,
                'new_status' => 'Submitted',
            ],
            ipAddress: $request->ip()
        );

        return redirect()
            ->route('resident.lupon')
            ->with(
                'success',
                'Your complaint has been submitted for review by the Lupong Tagapamayapa.'
            );
    }

    private function createWithReference(array $attributes): LuponComplaint
    {
        $prefix = 'LUP-'.Carbon::parse(LuponCaseWorkflow::today())->year.'-';
        $attempts = 0;

        while ($attempts < 5) {
            $reference = $this->nextReference($prefix);

            try {
                return DB::transaction(function () use ($attributes, $reference) {
                    return LuponComplaint::create([
                        ...$attributes,
                        'reference' => $reference,
                    ]);
                });
            } catch (QueryException $exception) {
                if (! $this->isUniqueViolation($exception)) {
                    throw $exception;
                }

                $attempts++;
            }
        }

        throw new \RuntimeException('Unable to allocate a unique complaint reference.');
    }

    private function nextReference(string $prefix): string
    {
        $last = LuponComplaint::query()
            ->where('reference', 'like', $prefix.'%')
            ->orderByDesc('reference')
            ->value('reference');

        $sequence = $last !== null
            ? ((int) substr($last, strlen($prefix))) + 1
            : 1;

        do {
            $reference = $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
            $sequence++;
        } while (
            LuponComplaint::query()
                ->where('reference', $reference)
                ->exists()
        );

        return $reference;
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
