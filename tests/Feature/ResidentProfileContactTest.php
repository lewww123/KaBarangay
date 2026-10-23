<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Purok;
use App\Models\Resident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class ResidentProfileContactTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Purok $purok;

    private Resident $resident;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $this->purok = $this->createPurok($this->barangay, 'Purok 1');
        $this->resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170000051',
        ]);
    }

    public function test_guest_is_redirected_to_resident_login(): void
    {
        $this->post('/resident/profile/contact', [
            'mobileNumber' => '09170000052',
        ])->assertRedirect('/resident/login');
    }

    public function test_empty_mobile_is_rejected(): void
    {
        $this->withSession(['resident_id' => $this->resident->id])
            ->post('/resident/profile/contact', [
                'mobileNumber' => '',
                'email' => 'updated@example.com',
            ])
            ->assertSessionHasErrors('mobileNumber');

        $fresh = $this->resident->fresh();
        $this->assertSame('09170000051', $fresh->mobile);
    }

    public function test_missing_mobile_is_rejected(): void
    {
        $this->withSession(['resident_id' => $this->resident->id])
            ->post('/resident/profile/contact', [
                'email' => 'updated@example.com',
            ])
            ->assertSessionHasErrors('mobileNumber');

        $this->assertSame('09170000051', $this->resident->fresh()->mobile);
    }

    public function test_valid_mobile_and_email_are_saved(): void
    {
        $this->withSession(['resident_id' => $this->resident->id])
            ->post('/resident/profile/contact', [
                'mobileNumber' => '09170000053',
                'email' => 'updated@example.com',
            ])
            ->assertRedirect();

        $fresh = $this->resident->fresh();
        $this->assertSame('09170000053', $fresh->mobile);
        $this->assertSame('updated@example.com', $fresh->email);
    }

    public function test_duplicate_mobile_in_same_barangay_is_rejected(): void
    {
        $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170000054',
        ]);

        $this->withSession(['resident_id' => $this->resident->id])
            ->post('/resident/profile/contact', [
                'mobileNumber' => '09170000054',
            ])
            ->assertSessionHasErrors('mobileNumber');

        $this->assertSame('09170000051', $this->resident->fresh()->mobile);
    }
}
