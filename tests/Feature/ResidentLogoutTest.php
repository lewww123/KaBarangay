<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResidentLogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_resident_logout_clears_the_session_and_returns_to_resident_login(): void
    {
        $this->withSession([
            'resident_id' => 123,
            'resident_barangay_id' => 10,
            'resident_logged_in' => true,
            'resident_remember' => true,
        ])->post('/resident/logout')
            ->assertRedirect('/resident/login')
            ->assertSessionMissing('resident_id')
            ->assertSessionMissing('resident_barangay_id')
            ->assertSessionMissing('resident_logged_in')
            ->assertSessionMissing('resident_remember');

        $this->assertGuest();
        $this->get('/resident')->assertRedirect('/resident/login');
    }

    public function test_resident_logout_without_an_existing_session_still_uses_resident_login(): void
    {
        $this->post('/resident/logout')->assertRedirect('/resident/login');
    }

    public function test_old_resident_tabs_posting_to_generic_logout_do_not_reach_staff_login(): void
    {
        $this->withSession(['resident_id' => 123])->post('/logout')
            ->assertRedirect('/resident/login')
            ->assertSessionMissing('resident_id');
    }

    public function test_legacy_logout_from_an_expired_resident_page_still_uses_resident_login(): void
    {
        $this->withHeader('referer', 'https://staff.kabarangay.com/resident/settings')->post('/logout')
            ->assertRedirect('/resident/login');
    }

    public function test_external_referer_cannot_select_the_resident_redirect_for_staff(): void
    {
        $this->actingAs(User::factory()->create())
            ->withHeader('referer', 'https://another.example/resident/settings')
            ->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_staff_logout_still_returns_to_staff_login_even_with_a_resident_session(): void
    {
        $this->actingAs(User::factory()->create())
            ->withSession(['resident_id' => 123])
            ->withHeader('referer', 'https://staff.kabarangay.com/admin/residents')
            ->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertFalse(session()->has('resident_id'));
    }
}
