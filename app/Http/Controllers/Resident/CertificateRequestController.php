<?php

namespace App\Http\Controllers\Resident;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\CertificateRequest;
use App\Models\Resident;
use App\Support\AuditLogger;
use App\Support\CertificateRequestDetails;
use App\Support\CertificateTemplates;
use App\Support\DelayedPsaCertificate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CertificateRequestController extends Controller
{
    /**
     * Display certificates and the logged-in resident's requests.
     */
    public function index(): Response
    {
        $resident = Resident::query()
            ->with([
                'barangay',
                'sectors',
                'purok',
            ])
            ->where('id', session('resident_id'))
            ->where('status', 'active')
            ->firstOrFail();

        // Only show active certificates belonging to this barangay.
        $certificates = Certificate::query()
            ->where('barangay_id', $resident->barangay_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(function (Certificate $certificate) {
                $type = CertificateRequestDetails::typeForCertificate($certificate);

                return [
                    'id' => (string) $certificate->id,
                    'name' => $certificate->name,
                    'fee' => (float) $certificate->fee,
                    'description' => $certificate->description ?? '',
                    'requirements' => $certificate->requirements ?? [],
                    'requiresBusinessDetails' => CertificateRequestDetails::requiresBusinessDetails(
                        $type,
                        (bool) $certificate->requires_business_details,
                    ),
                    'certificateType' => $type,
                    'fixedPurpose' => CertificateRequestDetails::fixedPurpose($type),
                    'detailsFields' => CertificateRequestDetails::fieldsForType($type),
                    // Requestability follows the active catalog. A missing
                    // generation template does not prevent an active service
                    // from accepting a Resident request.
                    'available' => $type !== null,
                    'templateAvailable' => $type !== null
                        && CertificateTemplates::isAvailable($type),
                ];
            })
            ->values();

        // Only show requests belonging to this resident.
        $requests = CertificateRequest::query()
            ->with('certificate')
            ->where('resident_id', $resident->id)
            ->where('barangay_id', $resident->barangay_id)
            ->latest('created_at')
            ->get()
            ->map(function (CertificateRequest $request) {
                $payload = CertificateRequestDetails::decode(
                    $request->additional_details,
                );
                $type = $payload['type']
                    ?? CertificateRequestDetails::typeForCertificate(
                        $request->certificate,
                    );

                return [
                    'id' => $request->id,
                    'reference' => $request->reference,
                    'certificate' => $request->certificate?->name
                        ?? 'Certificate',
                    'purpose' => $request->purpose,
                    'fee' => (float) $request->fee,
                    'status' => $request->status,
                    'date' => (
                        $request->submitted_at
                        ?? $request->created_at
                    )?->format('F j, Y') ?? '',
                    'businessName' => $request->business_name,
                    'businessAddress' => $request->business_address,
                    'additionalDetails' => $payload['notes'],
                    'details' => CertificateRequestDetails::displayRows(
                        $type,
                        $payload['fields'],
                    ),
                    'adminNote' => $request->admin_note,
                ];
            })
            ->values();

        return Inertia::render(
            'Resident/Certificates',
            [
                'resident' => [
                    'id' => $resident->id,
                    'first_name' => $resident->first_name,
                    'middle_name' => $resident->middle_name,
                    'last_name' => $resident->last_name,
                    'suffix' => $resident->suffix,
                    'full_name' => $resident->full_name,
                    'resident_number' => $resident->resident_number,
                    'birthdate' => $resident->birthdate?->format('Y-m-d'),
                    'sex' => $resident->sex,
                    'purok' => $resident->purok?->name,

                    'barangay' => [
                        'id' => $resident->barangay?->id,
                        'name' => $resident->barangay?->name,
                        'code' => $resident->barangay?->code,
                        'city_municipality' => $resident->barangay?->city_municipality,
                        'province' => $resident->barangay?->province,
                    ],

                    'sectors' => $resident->sectors
                        ->map(function ($sector) {
                            return [
                                'id' => $sector->id,
                                'name' => $sector->name,
                                'slug' => $sector->slug,
                            ];
                        })
                        ->values(),
                ],

                'certificates' => $certificates,
                'requests' => $requests,
            ]
        );
    }

    /**
     * Save a new certificate request.
     */
    public function store(Request $request): RedirectResponse
    {
        $resident = Resident::query()
            ->where('id', session('resident_id'))
            ->where('status', 'active')
            ->firstOrFail();

        $certificateInput = $request->validate([
            'certificate_id' => [
                'required',
                'integer',
                Rule::exists('certificates', 'id')
                    ->where(function ($query) use ($resident) {
                        $query
                            ->where(
                                'barangay_id',
                                $resident->barangay_id
                            )
                            ->where('is_active', true);
                    }),
            ],
        ]);

        // Confirm the selected certificate belongs to the resident's barangay.
        $certificate = Certificate::query()
            ->where('barangay_id', $resident->barangay_id)
            ->where('is_active', true)
            ->findOrFail($certificateInput['certificate_id']);

        $certificateType = CertificateRequestDetails::typeForCertificate(
            $certificate,
        );

        if ($certificateType === null) {
            throw ValidationException::withMessages([
                'certificate_id' => 'This certificate is not supported for requests yet. Please contact the barangay office.',
            ]);
        }

        $fixedPurpose = CertificateRequestDetails::fixedPurpose($certificateType);
        $requiresBusinessDetails =
            CertificateRequestDetails::requiresBusinessDetails(
                $certificateType,
                (bool) $certificate->requires_business_details,
            );

        $validated = $request->validate(
            CertificateRequestDetails::validationRules(
                $certificateType,
                $requiresBusinessDetails,
            ),
        );

        $details = CertificateRequestDetails::normalizeDetails(
            $certificateType,
            $validated['details'] ?? [],
        );

        if ($certificateType === DelayedPsaCertificate::TYPE
            && isset($details['residency_start_year'])) {
            $birthdate = $resident->birthdate;
            $earliestYear = DelayedPsaCertificate::earliestResidencyYear(
                $birthdate === null ? null : Carbon::instance($birthdate),
            );

            if ((int) $details['residency_start_year'] < $earliestYear) {
                throw ValidationException::withMessages([
                    'details.residency_start_year' => 'The residency start year cannot be earlier than the resident\'s birth year.',
                ]);
            }
        }

        $purpose = $fixedPurpose
            ?? ($validated['purpose'] ?? null);
        $additionalDetails = CertificateRequestDetails::encode(
            $certificateType,
            $details,
            $validated['additional_details'] ?? null,
        );

        // Generate a unique reference number.
        do {
            $reference = 'CERT-'
                .now()->format('Y')
                .'-'
                .Str::upper(Str::random(8));
        } while (
            CertificateRequest::query()
                ->where('reference', $reference)
                ->exists()
        );

        $certificateRequest = DB::transaction(function () use (
            $resident,
            $certificate,
            $validated,
            $purpose,
            $additionalDetails,
            $reference
        ) {
            return CertificateRequest::create([
                'barangay_id' => $resident->barangay_id,
                'resident_id' => $resident->id,
                'certificate_id' => $certificate->id,
                'reference' => $reference,
                'purpose' => $purpose,

                // Use the fee saved in the database.
                'fee' => $certificate->fee,

                'status' => 'Pending',
                'business_name' => $validated['business_name'] ?? null,
                'business_address' => $validated['business_address'] ?? null,
                'additional_details' => $additionalDetails,
                'submitted_at' => now(),
            ]);
        });

        /*
        |--------------------------------------------------------------------------
        | Audit: certificate request created
        |--------------------------------------------------------------------------
        |
        | Resident submissions have no staff/User identity, so actor_id stays
        | null. The barangay comes from the persisted resident record and the
        | certificate was validated against that same barangay, so a request
        | can never be logged against a foreign barangay. Only the system
        | reference and workflow status are recorded.
        |
        */

        AuditLogger::record(
            'certificate_request.created',
            $resident->barangay_id,
            null,
            'certificate_request',
            $certificateRequest->id,
            [
                'entity_reference' => $reference,
                'new_status' => $certificateRequest->status,
            ],
            $request->ip(),
        );

        return redirect()
            ->route('resident.certificates')
            ->with(
                'success',
                'Certificate request submitted successfully.'
            );
    }
}
