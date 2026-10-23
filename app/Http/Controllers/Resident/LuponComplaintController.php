<?php

namespace App\Http\Controllers\Resident;

use App\Http\Controllers\Controller;
use App\Models\LuponComplaint;
use App\Models\Resident;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LuponComplaintController extends Controller
{
    /**
     * Complaint types offered by the resident intake form.
     */
    private const COMPLAINT_TYPES = [
        'Property / Boundary Dispute',
        'Noise / Disturbance',
        'Debt / Money Dispute',
        'Damage to Property',
        'Threat / Harassment',
        'Family / Neighbor Dispute',
        'Other',
    ];

    /**
     * Respondent residency answers offered by the intake form.
     */
    private const RESPONDENT_STATUSES = ['Yes', 'No', 'Unknown'];

    /**
     * Display the Lupon page for the logged-in resident.
     *
     * Only the resident's own complaints are returned, scoped to their
     * barangay. Hearing and case-management data are intentionally absent:
     * they are not part of this intake batch.
     */
    public function index(): Response
    {
        $resident = Resident::query()
            ->with(['barangay', 'purok'])
            ->where('id', session('resident_id'))
            ->where('status', 'active')
            ->firstOrFail();

        $complaints = LuponComplaint::query()
            ->where('resident_id', $resident->id)
            ->where('barangay_id', $resident->barangay_id)
            ->latest('created_at')
            ->get()
            ->map(function (LuponComplaint $complaint) {
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
                ];
            })
            ->values();

        return Inertia::render('Resident/Lupon', [
            'resident' => [
                'id' => $resident->id,
                'fullName' => $resident->full_name,
                'residentNumber' => $resident->resident_number,
                'address' => trim(
                    implode(', ', array_filter([
                        $resident->purok?->name,
                        $resident->barangay?->name,
                    ]))
                ),
            ],
            'complaintTypes' => self::COMPLAINT_TYPES,
            'complaints' => $complaints,
        ]);
    }

    /**
     * Save a new complaint for review by the Lupong Tagapamayapa.
     *
     * The complaint is stored as "Submitted". Nothing is auto-accepted and no
     * case, hearing, or pangkat record is created by this endpoint.
     */
    public function store(Request $request): RedirectResponse
    {
        $resident = Resident::query()
            ->where('id', session('resident_id'))
            ->where('status', 'active')
            ->firstOrFail();

        $validated = $request->validate([
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
                'before_or_equal:today',
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

        $this->createWithReference($attributes);

        return redirect()
            ->route('resident.lupon')
            ->with(
                'success',
                'Your complaint has been submitted for review by the Lupong Tagapamayapa.'
            );
    }

    /**
     * Create the complaint with a unique sequential LUP-YYYY-NNNNN reference.
     *
     * The reference is allocated inside the transaction and retried on a
     * unique-constraint collision so two concurrent submissions can never
     * claim the same number.
     */
    private function createWithReference(array $attributes): LuponComplaint
    {
        $prefix = 'LUP-'.now()->year.'-';

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

    /**
     * Next sequential reference for the current year, based on the highest
     * reference already stored for that year.
     */
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
            || str_contains($message, 'SQLSTATE[23000]');
    }
}
