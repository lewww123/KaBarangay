<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Purok;
use App\Models\Resident;
use App\Models\ResidentQrCredential;
use App\Support\CertificateTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

/**
 * Clerk certificate resident QR lookup.
 *
 * The Clerk certificate page resolves a scanned QR credential through the
 * existing staff endpoint (GET /api/qr/verify-token). A clerk may only resolve
 * active residents within their own barangay, and a failed lookup must yield no
 * resident identity payload (the frontend must not fall back to fabricated
 * name/ID/purok data).
 */
class ClerkCertificateResidentLookupTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Purok $purok;

    protected function setUp(): void
    {
        parent::setUp();

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $this->purok = $this->createPurok($this->barangay, 'Purok 1');
    }

    private function makeCredential(Resident $resident): ResidentQrCredential
    {
        return ResidentQrCredential::create([
            'resident_id' => $resident->id,
            'token' => 'KBQR-'.Str::random(64),
            'is_active' => true,
            'generated_at' => now(),
            'revoked_at' => null,
        ]);
    }

    public function test_clerk_resolves_an_active_resident_in_own_barangay(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'mobile' => '09170004001',
        ]);
        $credential = $this->makeCredential($resident);

        $this->actingAs($clerk)
            ->getJson('/api/qr/verify-token?token='.$credential->token)
            ->assertOk()
            ->assertJsonPath('resident.id', $resident->id)
            ->assertJsonPath('resident.full_name', 'Maria Santos');
    }

    public function test_clerk_cannot_resolve_a_foreign_barangay_resident(): void
    {
        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $otherPurok = $this->createPurok($otherBarangay, 'Purok 2');

        $clerk = $this->createStaff($this->barangay, 'clerk');
        $foreignResident = $this->createResident($otherBarangay, $otherPurok, [
            'mobile' => '09170004002',
        ]);
        $foreignCredential = $this->makeCredential($foreignResident);

        $this->actingAs($clerk)
            ->getJson('/api/qr/verify-token?token='.$foreignCredential->token)
            ->assertStatus(404);
    }

    public function test_lookup_failure_exposes_no_resident_identity(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $inactive = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170004003',
        ]);
        $inactive->update(['status' => 'inactive']);
        $inactiveCredential = $this->makeCredential($inactive);

        $unknown = $this->actingAs($clerk)
            ->getJson('/api/qr/verify-token?token=KBQR-'.Str::random(64));
        $unknown->assertStatus(404);

        $forInactive = $this->actingAs($clerk)
            ->getJson('/api/qr/verify-token?token='.$inactiveCredential->token);
        $forInactive->assertStatus(404);

        $unknownJson = $unknown->json();
        $inactiveJson = $forInactive->json();

        $this->assertArrayNotHasKey('resident', $unknownJson);
        $this->assertArrayNotHasKey('resident', $inactiveJson);
        $this->assertArrayNotHasKey('full_name', $unknownJson);
        $this->assertArrayNotHasKey('full_name', $inactiveJson);
    }

    public function test_scanner_suffix_is_tolerated_after_trimming(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'mobile' => '09170004004',
        ]);
        $credential = $this->makeCredential($resident);

        // A keyboard-wedge 2D scanner appends Enter; the clerk UI trims the
        // token before sending it, so the trimmed value must resolve.
        $scanned = trim($credential->token."\r\n");

        $this->actingAs($clerk)
            ->getJson('/api/qr/verify-token?token='.urlencode($scanned))
            ->assertOk()
            ->assertJsonPath('resident.full_name', 'Ana Reyes');
    }

    public function test_repeated_identical_scan_is_idempotent(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Ben',
            'last_name' => 'Cruz',
            'mobile' => '09170004005',
        ]);
        $credential = $this->makeCredential($resident);

        $first = $this->actingAs($clerk)
            ->getJson('/api/qr/verify-token?token='.$credential->token);
        $second = $this->actingAs($clerk)
            ->getJson('/api/qr/verify-token?token='.$credential->token);

        $first->assertOk()->assertJsonPath('resident.id', $resident->id);
        $second->assertOk()->assertJsonPath('resident.id', $resident->id);
        $this->assertSame($first->json('resident'), $second->json('resident'));
    }

    public function test_manual_entry_generates_without_a_resident_id(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        if (! CertificateTemplates::isAvailable('Tricycle')) {
            $this->markTestSkipped('The Tricycle template is not present in this environment.');
        }

        $response = $this->actingAs($clerk)->post('/clerk/generate-certificate', [
            'certificate_type' => 'Tricycle',
            'resident_name' => 'Manual Only Applicant',
            'purok' => 'Purok 1',
            'purpose' => 'Employment',
            'control_no' => 'CTRL-2026-9001',
            'fee' => '300',
            'franchise_no' => 'FR-9001',
            'business_name' => 'Manual Trading',
            'business_address' => 'Purok 1',
        ]);

        $response->assertOk();
        $this->assertStringContainsString(
            'attachment',
            (string) $response->headers->get('content-disposition')
        );
    }

    public function test_clerk_sidebar_certificates_link_targets_the_fixed_certificate_page(): void
    {
        $source = (string) file_get_contents(
            base_path('resources/js/layouts/ClerkLayout.tsx')
        );

        $this->assertStringContainsString(
            'href="/clerk/certificates"',
            $source,
            'The Clerk sidebar "Certificates" link must target /clerk/certificates (the C1/C2-fixed page).'
        );

        // Since C4, /clerk/dashboard is the dedicated Overview page (not the
        // duplicate certificate/simulated-QR page), so an Overview link to it
        // is expected alongside the Certificates link.
        $this->assertStringContainsString('label="Overview"', $source);
        $this->assertStringContainsString('label="Certificates"', $source);
    }
}
