<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Certificate;
use App\Models\CertificateRequest;
use App\Models\Purok;
use App\Models\Resident;
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
            'code' => 'BRGY-CLEARANCE',
            'name' => 'Barangay Clearance',
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
                ->has('requests', 1)
                ->where('requests.0.id', $ownRequest->id));
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
                ->has('requests', 0));

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

    /*
    |--------------------------------------------------------------------------
    | Submission (POST /resident/certificates)
    |--------------------------------------------------------------------------
    */

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
}
