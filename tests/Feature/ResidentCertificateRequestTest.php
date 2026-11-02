<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Certificate;
use App\Models\CertificateRequest;
use App\Models\Purok;
use App\Models\Resident;
use App\Support\CertificateRequestDetails;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

/**
 * Batch R1 (Resident): certificate request listing and submission scoping.
 *
 * Proves that the resident portal never exposes another resident's requests
 * or another barangay's certificates, and that submission rejects certificates
 * that are foreign to the resident's barangay or inactive at submission time.
 */
class ResidentCertificateRequestTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Barangay $other;

    private Purok $purok;

    private Purok $otherPurok;

    private Resident $resident;

    private Certificate $certificate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $this->other = $this->createBarangay('Barangay Two', 'BRGY-TWO');

        $this->purok = $this->createPurok($this->barangay, 'Purok 1');
        $this->otherPurok = $this->createPurok($this->other, 'Purok 1');

        $this->resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170000061',
        ]);

        $this->certificate = $this->createCertificate($this->barangay, [
            'code' => 'BRGY-INDIGENCY',
            'name' => 'Certificate of Indigency',
        ]);
    }

    private function createCertificate(Barangay $barangay, array $attributes = []): Certificate
    {
        return Certificate::create([
            'barangay_id' => $barangay->id,
            'code' => 'CERT-'.strtoupper(substr(uniqid(), -6)),
            'name' => 'Test Certificate',
            'fee' => 50,
            'is_active' => true,
            ...$attributes,
        ]);
    }

    private function createCertificateRequest(
        Resident $resident,
        Certificate $certificate,
        array $attributes = []
    ): CertificateRequest {
        return CertificateRequest::create([
            'barangay_id' => $resident->barangay_id,
            'resident_id' => $resident->id,
            'certificate_id' => $certificate->id,
            'reference' => 'CERT-2026-'.strtoupper(substr(uniqid(), -6)),
            'purpose' => 'Test purpose',
            'fee' => $certificate->fee,
            'status' => 'Pending',
            'submitted_at' => now(),
            ...$attributes,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Listing (GET /resident/certificates)
    |--------------------------------------------------------------------------
    */

    public function test_index_returns_only_the_logged_in_residents_own_requests(): void
    {
        $ownRequest = $this->createCertificateRequest(
            $this->resident,
            $this->certificate
        );

        $otherResident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170000062',
        ]);

        $this->createCertificateRequest($otherResident, $this->certificate);

        $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/certificates')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Resident/Certificates')
                ->has('requests.data', 1)
                ->where('requests.data.0.id', $ownRequest->id));
    }

    public function test_index_does_not_return_requests_from_another_resident(): void
    {
        $otherResident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170000063',
        ]);

        $foreign = $this->createCertificateRequest(
            $otherResident,
            $this->certificate
        );

        $response = $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/certificates')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Resident/Certificates')
                ->has('requests.data', 0));

        $this->assertStringNotContainsString(
            (string) $foreign->reference,
            $response->getContent()
        );
    }

    public function test_index_exposes_only_certificates_available_to_the_residents_barangay(): void
    {
        $ownActive = $this->createCertificate($this->barangay, [
            'code' => 'OWN-ACTIVE',
            'name' => 'Own Active Certificate',
        ]);

        $ownInactive = $this->createCertificate($this->barangay, [
            'code' => 'OWN-INACTIVE',
            'name' => 'Own Inactive Certificate',
            'is_active' => false,
        ]);

        $foreignActive = $this->createCertificate($this->other, [
            'code' => 'FOREIGN-ACTIVE',
            'name' => 'Foreign Active Certificate',
        ]);

        $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/certificates')
            ->assertOk()
            ->assertInertia(function (Assert $page) use ($ownActive, $ownInactive, $foreignActive) {
                $page->component('Resident/Certificates');

                $ids = collect($page->toArray()['props']['certificates'])
                    ->pluck('id')
                    ->all();

                $this->assertContains((string) $ownActive->id, $ids);
                $this->assertNotContains((string) $ownInactive->id, $ids);
                $this->assertNotContains((string) $foreignActive->id, $ids);
            });
    }

    public function test_index_keeps_active_catalog_certificates_requestable_without_templates(): void
    {
        $sariSari = $this->createCertificate($this->barangay, [
            'code' => 'SARI-SARI',
            'name' => 'Sari-Sari Store',
        ]);

        $clearance = $this->createCertificate($this->barangay, [
            'code' => 'BRGY-CLEARANCE',
            'name' => 'Barangay Clearance',
        ]);

        $response = $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/certificates')
            ->assertOk();

        $certificates = collect($response->inertiaProps('certificates'))
            ->keyBy('id');

        $this->assertTrue($certificates[(string) $sariSari->id]['available']);
        $this->assertFalse($certificates[(string) $sariSari->id]['templateAvailable']);
        $this->assertTrue($certificates[(string) $clearance->id]['available']);
        $this->assertFalse($certificates[(string) $clearance->id]['templateAvailable']);
        $this->assertTrue($certificates[(string) $this->certificate->id]['available']);
        $this->assertSame(
            'Certificate of Indigency',
            $certificates[(string) $this->certificate->id]['name'],
        );
    }

    public function test_index_still_lists_existing_requests_for_now_unavailable_certificates(): void
    {
        $sariSari = $this->createCertificate($this->barangay, [
            'code' => 'SARI-SARI',
            'name' => 'Sari-Sari Store',
        ]);

        $pending = $this->createCertificateRequest(
            $this->resident,
            $sariSari,
            [
                'reference' => 'CERT-2026-SARI',
                'status' => 'Processing',
            ],
        );

        $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/certificates')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Resident/Certificates')
                ->has('requests.data', 1)
                ->where('requests.data.0.id', $pending->id)
                ->where('requests.data.0.reference', 'CERT-2026-SARI')
                ->where('requests.data.0.status', 'Processing')
                ->where('requests.data.0.certificate', 'Sari-Sari Store'));
    }

    /*
    |--------------------------------------------------------------------------
    | Submission (POST /resident/certificates)
    |--------------------------------------------------------------------------
    */

    public function test_active_catalog_certificates_without_templates_can_be_requested(): void
    {
        $sariSari = $this->createCertificate($this->barangay, [
            'code' => 'SARI-SARI',
            'name' => 'Sari-Sari Store',
        ]);

        $clearance = $this->createCertificate($this->barangay, [
            'code' => 'BRGY-CLEARANCE',
            'name' => 'Barangay Clearance',
        ]);

        foreach ([$sariSari, $clearance] as $requestable) {
            $this->withSession(['resident_id' => $this->resident->id])
                ->post('/resident/certificates', [
                    'certificate_id' => $requestable->id,
                    'purpose' => 'Employment abroad',
                    'details' => $requestable->id === $sariSari->id ? ['business_type' => 'Sari-sari Store'] : [],
                ])
                ->assertRedirect(route('resident.certificates'));
        }

        $this->assertSame(
            2,
            CertificateRequest::query()
                ->where('resident_id', $this->resident->id)
                ->count()
        );
    }

    public function test_submitting_a_certificate_from_another_barangay_is_rejected(): void
    {
        $foreignCertificate = $this->createCertificate($this->other, [
            'code' => 'FOREIGN-CERT',
            'name' => 'Foreign Certificate',
        ]);

        $this->withSession(['resident_id' => $this->resident->id])
            ->post('/resident/certificates', [
                'certificate_id' => $foreignCertificate->id,
                'purpose' => 'Employment abroad',
            ])
            ->assertSessionHasErrors('certificate_id');

        $this->assertSame(
            0,
            CertificateRequest::query()
                ->where('resident_id', $this->resident->id)
                ->count()
        );
    }

    public function test_submitting_an_inactive_certificate_is_rejected(): void
    {
        $inactiveCertificate = $this->createCertificate($this->barangay, [
            'code' => 'INACTIVE-CERT',
            'name' => 'Inactive Certificate',
            'is_active' => false,
        ]);

        $this->withSession(['resident_id' => $this->resident->id])
            ->post('/resident/certificates', [
                'certificate_id' => $inactiveCertificate->id,
                'purpose' => 'Employment abroad',
            ])
            ->assertSessionHasErrors('certificate_id');

        $this->assertSame(
            0,
            CertificateRequest::query()
                ->where('resident_id', $this->resident->id)
                ->count()
        );
    }

    public function test_valid_submission_behavior_remains_intact(): void
    {
        $this->withSession(['resident_id' => $this->resident->id])
            ->post('/resident/certificates', [
                'certificate_id' => $this->certificate->id,
                'purpose' => 'Employment abroad',
            ])
            ->assertRedirect(route('resident.certificates'));

        $request = CertificateRequest::query()
            ->where('resident_id', $this->resident->id)
            ->firstOrFail();

        $this->assertSame($this->barangay->id, $request->barangay_id);
        $this->assertSame($this->certificate->id, $request->certificate_id);
        $this->assertSame('Pending', $request->status);
        $this->assertSame('Employment abroad', $request->purpose);
        $this->assertSame('50.00', $request->fee);
        $this->assertNotNull($request->reference);
        $this->assertNotNull($request->submitted_at);
    }

    public function test_requests_exceeding_the_limit_are_throttled(): void
    {
        $session = ['resident_id' => $this->resident->id];

        // Requests below the limit continue to work normally.
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->withSession($session)
                ->post('/resident/certificates', [
                    'certificate_id' => $this->certificate->id,
                    'purpose' => 'Employment abroad',
                ])
                ->assertRedirect(route('resident.certificates'));
        }

        // The request that exceeds the limit is rejected.
        $this->withSession($session)
            ->post('/resident/certificates', [
                'certificate_id' => $this->certificate->id,
                'purpose' => 'Employment abroad',
            ])
            ->assertStatus(429);
    }

    public function test_residency_request_uses_fixed_purpose_and_stores_details(): void
    {
        $residency = $this->createCertificate($this->barangay, [
            'code' => 'residency',
            'name' => 'Certificate of Residency',
        ]);

        $this->withSession(['resident_id' => $this->resident->id])
            ->post('/resident/certificates', [
                'certificate_id' => $residency->id,
                'details' => ['years_of_residency' => '5'],
                'additional_details' => 'Near the covered court.',
            ])
            ->assertRedirect(route('resident.certificates'));

        $request = CertificateRequest::query()
            ->where('resident_id', $this->resident->id)
            ->firstOrFail();

        $this->assertSame('Proof of Residency', $request->purpose);

        $payload = CertificateRequestDetails::decode($request->additional_details);

        $this->assertSame('Residency', $payload['type']);
        $this->assertSame('5', $payload['fields']['years_of_residency']);
        $this->assertSame('Near the covered court.', $payload['notes']);
    }

    public function test_resident_catalog_exposes_the_same_dynamic_fields_used_for_clerk_issuance(): void
    {
        $scholarship = $this->createCertificate($this->barangay, [
            'code' => 'scholarship',
            'name' => 'Scholarship Certificate',
        ]);

        $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/certificates')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Resident/Certificates')
                ->where('certificates.1.id', (string) $scholarship->id)
                ->where('certificates.1.certificateType', 'Scholarship Certificate')
                ->where('certificates.1.detailsFields.0.key', 'school_institution')
                ->where('certificates.1.detailsFields.3.key', 'father_monthly_income')
                ->where('certificates.1.detailsFields.6.key', 'mother_monthly_income'));
    }

    public function test_tricycle_request_requires_business_and_franchise_details(): void
    {
        $tricycle = $this->createCertificate($this->barangay, [
            'code' => 'tricycle',
            'name' => 'Tricycle',
            'fee' => 300,
        ]);

        $this->withSession(['resident_id' => $this->resident->id])
            ->post('/resident/certificates', [
                'certificate_id' => $tricycle->id,
                'purpose' => 'Business',
                'details' => ['franchise_no' => 'FR-2026-01'],
            ])
            ->assertSessionHasErrors(['business_name', 'business_address']);

        $this->withSession(['resident_id' => $this->resident->id])
            ->post('/resident/certificates', [
                'certificate_id' => $tricycle->id,
                'purpose' => 'Business',
                'business_name' => 'Juan\'s Tricycle',
                'business_address' => 'Purok 1',
                'details' => ['franchise_no' => 'FR-2026-01'],
            ])
            ->assertRedirect(route('resident.certificates'));

        $request = CertificateRequest::query()
            ->where('resident_id', $this->resident->id)
            ->firstOrFail();

        $this->assertSame('Juan\'s Tricycle', $request->business_name);
        $this->assertSame('Purok 1', $request->business_address);
        $this->assertSame(
            'FR-2026-01',
            CertificateRequestDetails::decode($request->additional_details)['fields']['franchise_no'],
        );
    }

    public function test_delayed_psa_request_rejects_residency_before_birth_year(): void
    {
        $delayedPsa = $this->createCertificate($this->barangay, [
            'code' => 'delayed-psa-registration',
            'name' => 'Delayed PSA Registration',
        ]);

        $this->withSession(['resident_id' => $this->resident->id])
            ->post('/resident/certificates', [
                'certificate_id' => $delayedPsa->id,
                'details' => [
                    'nationality' => 'FILIPINO',
                    'birth_place' => 'PUERTO PRINCESA CITY',
                    'father_name' => 'Pedro Dela Cruz',
                    'mother_maiden_name' => 'Maria Santos',
                    'parents_marriage_details' => 'Married in 1988',
                    'residency_start_year' => '1989',
                ],
            ])
            ->assertSessionHasErrors('details.residency_start_year');

        $this->assertSame(0, CertificateRequest::query()->count());
    }

    public function test_request_details_and_admin_note_are_exposed_to_the_owner(): void
    {
        $request = $this->createCertificateRequest(
            $this->resident,
            $this->certificate,
            [
                'additional_details' => CertificateRequestDetails::encode(
                    'Residency',
                    ['years_of_residency' => '7'],
                    'Please expedite.',
                ),
                'admin_note' => 'Bring a valid ID.',
            ],
        );

        $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/certificates')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Resident/Certificates')
                ->where('requests.data.0.id', $request->id)
                ->where('requests.data.0.additionalDetails', 'Please expedite.')
                ->where('requests.data.0.details.0.label', 'Years of Residency')
                ->where('requests.data.0.details.0.value', '7')
                ->where('requests.data.0.adminNote', 'Bring a valid ID.'));
    }

    public function test_legacy_plain_text_additional_details_remain_readable(): void
    {
        $request = $this->createCertificateRequest(
            $this->resident,
            $this->certificate,
            ['additional_details' => 'Legacy free-text note.'],
        );

        $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/certificates')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('requests.data.0.id', $request->id)
                ->where('requests.data.0.additionalDetails', 'Legacy free-text note.')
                ->has('requests.data.0.details', 0));
    }
}
