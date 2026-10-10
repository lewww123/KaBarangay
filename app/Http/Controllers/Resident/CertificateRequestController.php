<?php

namespace App\Http\Controllers\Resident;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\CertificateRequest;
use App\Models\Resident;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
                return [
                    'id' => (string) $certificate->id,
                    'name' => $certificate->name,
                    'fee' => (float) $certificate->fee,
                    'description' => $certificate->description ?? '',
                    'requirements' => $certificate->requirements ?? [],
                    'requiresBusinessDetails' =>
                        (bool) $certificate->requires_business_details,
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
                    'additionalDetails' => $request->additional_details,
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

                    'barangay' => [
                        'id' => $resident->barangay?->id,
                        'name' => $resident->barangay?->name,
                        'code' => $resident->barangay?->code,
                        'city_municipality' =>
                            $resident->barangay?->city_municipality,
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

        $validated = $request->validate([
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

            'purpose' => [
                'required',
                'string',
                'max:500',
            ],

            'business_name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'business_address' => [
                'nullable',
                'string',
                'max:1000',
            ],

            'additional_details' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        // Confirm the selected certificate belongs to the resident's barangay.
        $certificate = Certificate::query()
            ->where('barangay_id', $resident->barangay_id)
            ->where('is_active', true)
            ->findOrFail($validated['certificate_id']);

        // Business certificates require a business name and address.
        if ($certificate->requires_business_details) {
            $request->validate([
                'business_name' => [
                    'required',
                    'string',
                    'max:255',
                ],

                'business_address' => [
                    'required',
                    'string',
                    'max:1000',
                ],
            ]);
        }

        // Generate a unique reference number.
        do {
            $reference = 'CERT-'
                . now()->format('Y')
                . '-'
                . Str::upper(Str::random(8));
        } while (
            CertificateRequest::query()
                ->where('reference', $reference)
                ->exists()
        );

        DB::transaction(function () use (
            $resident,
            $certificate,
            $validated,
            $reference
        ) {
            CertificateRequest::create([
                'barangay_id' => $resident->barangay_id,
                'resident_id' => $resident->id,
                'certificate_id' => $certificate->id,
                'reference' => $reference,
                'purpose' => $validated['purpose'],

                // Use the fee saved in the database.
                'fee' => $certificate->fee,

                'status' => 'Pending',
                'business_name' =>
                    $validated['business_name'] ?? null,
                'business_address' =>
                    $validated['business_address'] ?? null,
                'additional_details' =>
                    $validated['additional_details'] ?? null,
                'submitted_at' => now(),
            ]);
        });

        return redirect()
            ->route('resident.certificates')
            ->with(
                'success',
                'Certificate request submitted successfully.'
            );
    }
}
