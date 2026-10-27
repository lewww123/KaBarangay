<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Purok;
use App\Models\Resident;
use App\Models\ResidentQrCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

/**
 * Public resident QR verification page (GET /verify/resident/{token}).
 *
 * The controller resolves an active, non-revoked credential bound to an active
 * resident, then renders Resident/VerifyResident with a minimal, non-identifying
 * `verification` prop: only `valid`, a generic message, and no location data.
 * Invalid credentials/residents render the same component with valid=false
 * instead of returning an error status code, and never reveal resident details.
 */
class QrVerificationTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Purok $purok;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $this->purok = $this->createPurok($this->barangay, 'Purok 1');
    }

    private function makeCredential(Resident $resident, array $attributes = []): ResidentQrCredential
    {
        return ResidentQrCredential::create([
            'resident_id' => $resident->id,
            'token' => $attributes['token'] ?? 'KBQR-'.Str::random(64),
            'is_active' => $attributes['is_active'] ?? true,
            'generated_at' => now(),
            'revoked_at' => $attributes['revoked_at'] ?? null,
        ]);
    }

    public function test_active_credential_and_active_resident_verifies_with_minimal_fields(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170002001']);
        $credential = $this->makeCredential($resident);

        $this->get('/verify/resident/'.$credential->token)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Resident/VerifyResident')
                ->where('verification.valid', true)
                ->where('verification.message', 'This credential is valid.')
                ->where('verification.barangay', null)
                ->missing('verification.resident')
                ->missing('verification.name')
                ->missing('verification.residentId')
                ->missing('verification.city')
                ->missing('verification.province')
                ->missing('verification.classification')
                ->missing('verification.status'));
    }

    public function test_valid_verification_does_not_render_resident_pii(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'mobile' => '09170002009',
        ]);
        $this->assignSector($resident, 'senior');
        $credential = $this->makeCredential($resident);

        $response = $this->get('/verify/resident/'.$credential->token)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Resident/VerifyResident')
                ->where('verification.valid', true)
                ->missing('verification.classification')
                ->missing('verification.status')
                ->missing('verification.resident'));

        $response->assertDontSee($resident->full_name);
        $response->assertDontSee($resident->resident_number);
        $response->assertDontSee($resident->mobile);
        $response->assertDontSee('Senior');
        $response->assertDontSee('Regular Resident');
    }

    public function test_unknown_token_is_invalid_without_resident_details(): void
    {
        $this->get('/verify/resident/KBQR-'.Str::random(64))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Resident/VerifyResident')
                ->where('verification.valid', false)
                ->where('verification.barangay', null)
                ->missing('verification.resident')
                ->missing('verification.name')
                ->missing('verification.residentId')
                ->missing('verification.classification')
                ->missing('verification.status'));
    }

    public function test_inactive_credential_is_invalid_without_resident_details(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170002002']);
        $credential = $this->makeCredential($resident, ['is_active' => false]);

        $this->get('/verify/resident/'.$credential->token)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('verification.valid', false)
                ->where('verification.barangay', null)
                ->missing('verification.resident')
                ->missing('verification.name'));
    }

    public function test_revoked_credential_is_invalid_without_resident_details(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170002003']);
        $credential = $this->makeCredential($resident, ['revoked_at' => now()]);

        $this->get('/verify/resident/'.$credential->token)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('verification.valid', false)
                ->where('verification.barangay', null)
                ->missing('verification.resident')
                ->missing('verification.name'));
    }

    public function test_inactive_resident_is_invalid_without_resident_details(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170002004']);
        $resident->update(['status' => 'inactive']);
        $credential = $this->makeCredential($resident);

        $this->get('/verify/resident/'.$credential->token)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('verification.valid', false)
                ->where('verification.barangay', null)
                ->missing('verification.resident')
                ->missing('verification.name'))
            ->assertDontSee($resident->full_name)
            ->assertDontSee($resident->resident_number);
    }

    public function test_public_verification_is_rate_limited_per_ip(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170002005']);
        $credential = $this->makeCredential($resident);

        $url = '/verify/resident/'.$credential->token;

        for ($i = 0; $i < 120; $i++) {
            $this->get($url)->assertOk();
        }

        $this->get($url)->assertStatus(429);
    }
}
