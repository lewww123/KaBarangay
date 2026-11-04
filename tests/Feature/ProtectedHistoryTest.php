<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProtectedHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_resident_logout_invalidates_session_and_rotates_history_on_login_response(): void
    {
        $this->withoutVite();
        $this->withSession(['resident_id' => 123, 'private_marker' => 'old-account', '_token' => 'old-token']);
        $oldId = session()->getId();
        $this->post('/resident/logout')->assertRedirect('/resident/login')
            ->assertSessionMissing('resident_id')->assertSessionMissing('private_marker');
        $this->assertNotSame($oldId, session()->getId());
        $this->assertNotSame('old-token', session()->token());
        $this->get('/resident/login', ['X-Inertia' => 'true', 'X-Inertia-Version' => \Inertia\Inertia::getVersion()])->assertOk()
            ->assertJsonPath('encryptHistory', true)->assertJsonPath('clearHistory', true);
        $this->get('/resident')->assertRedirect('/resident/login');
    }

    public function test_staff_logout_clears_history_after_invalidating_session(): void
    {
        $this->withoutVite();
        $this->withSession(['private_marker' => 'old-account', '_token' => 'old-token']);
        $this->post('/logout')->assertRedirect(route('login'))->assertSessionMissing('private_marker');
        $this->get('https://staff.kabarangay.com/staff/login', ['X-Inertia' => 'true', 'X-Inertia-Version' => \Inertia\Inertia::getVersion()])->assertOk()
            ->assertJsonPath('encryptHistory', true)->assertJsonPath('clearHistory', true);
        $this->get('/admin')->assertRedirect(route('login'));
    }
}
