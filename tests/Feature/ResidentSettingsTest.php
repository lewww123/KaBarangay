<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Purok;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class ResidentSettingsTest extends TestCase
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

    public function test_settings_page_renders_for_authenticated_resident(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09182000001']);

        $this->withSession(['resident_id' => $resident->id])
            ->get('/resident/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Resident/Settings'));
    }

    public function test_guest_cannot_change_resident_password(): void
    {
        $this->post('/resident/settings/password', [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertRedirect('/resident/login');
    }

    public function test_resident_can_change_password_with_current_password(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09182000002']);
        $account = $resident->account()->firstOrFail();
        $account->forceFill(['remember_token' => 'old-token'])->save();

        DB::table('resident_password_reset_tokens')->insert([
            'resident_id' => $resident->id,
            'email' => 'resident@example.com',
            'token' => Hash::make('pending-reset-token'),
            'created_at' => now(),
        ]);

        $this->withSession(['resident_id' => $resident->id])
            ->post('/resident/settings/password', [
                'current_password' => 'password',
                'password' => 'new-secure-password',
                'password_confirmation' => 'new-secure-password',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $account->refresh();

        $this->assertTrue(Hash::check('new-secure-password', $account->password));
        $this->assertFalse(Hash::check('password', $account->password));
        $this->assertNotNull($account->password_changed_at);
        $this->assertNull($account->remember_token);
        $this->assertDatabaseMissing('resident_password_reset_tokens', [
            'resident_id' => $resident->id,
        ]);
    }

    public function test_wrong_current_password_is_rejected_without_changes(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09182000003']);
        $account = $resident->account()->firstOrFail();

        $this->withSession(['resident_id' => $resident->id])
            ->post('/resident/settings/password', [
                'current_password' => 'not-the-password',
                'password' => 'new-secure-password',
                'password_confirmation' => 'new-secure-password',
            ])
            ->assertSessionHasErrors('current_password');

        $account->refresh();

        $this->assertTrue(Hash::check('password', $account->password));
        $this->assertNull($account->password_changed_at);
    }

    public function test_password_confirmation_must_match(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09182000004']);

        $this->withSession(['resident_id' => $resident->id])
            ->post('/resident/settings/password', [
                'current_password' => 'password',
                'password' => 'new-secure-password',
                'password_confirmation' => 'different-password',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('password', $resident->account()->firstOrFail()->password));
    }

    public function test_new_password_must_be_at_least_eight_characters(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09182000005']);

        $this->withSession(['resident_id' => $resident->id])
            ->post('/resident/settings/password', [
                'current_password' => 'password',
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('password', $resident->account()->firstOrFail()->password));
    }
}
