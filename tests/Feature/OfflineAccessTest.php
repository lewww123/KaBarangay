<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class OfflineAccessTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    public function test_guest_has_no_offline_identity_and_session_response_is_not_cacheable(): void
    {
        $this->get('/offline/session')->assertOk()->assertJsonPath('scope', null)
            ->assertJsonStructure(['csrfToken'])->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    }

    public function test_resident_scope_is_opaque_session_bound_and_cleared_on_logout(): void
    {
        $this->withoutVite();
        $barangay = $this->createBarangay('Offline Barangay', 'OFF');
        $resident = $this->createResident($barangay, $this->createPurok($barangay));
        $response = $this->withSession(['resident_id' => $resident->id])->get('/offline/session')->assertOk();
        $scope = $response->json('scope');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $scope);
        $response->assertHeader('X-KaBarangay-Offline-Scope', $scope);
        $this->withCookie(config('session.cookie'), $this->app['session']->getId());
        $this->get('/offline/session')->assertJsonPath('scope', $scope);
        $this->post('/resident/logout')->assertRedirect('/resident/login');
        $this->withCookie(config('session.cookie'), $this->app['session']->getId());
        $this->get('/offline/session')->assertJsonPath('scope', null);
        $newScope = $this->withSession(['resident_id' => $resident->id])->get('/offline/session')->json('scope');
        $this->assertNotSame($scope, $newScope);
    }

    public function test_different_staff_accounts_cannot_share_an_offline_scope(): void
    {
        $first = User::factory()->create(['status' => 'active']);
        $second = User::factory()->create(['status' => 'active']);
        $firstScope = $this->actingAs($first)->get('/offline/session')->assertOk()->json('scope');
        $secondScope = $this->actingAs($second)->get('/offline/session')->assertOk()->json('scope');
        $this->assertNotNull($firstScope);
        $this->assertNotSame($firstScope, $secondScope);
    }

    public function test_inactive_or_invalid_accounts_have_no_offline_scope(): void
    {
        $this->withSession(['resident_id' => 999999])->get('/offline/session')->assertJsonPath('scope', null);
        $this->actingAs(User::factory()->create(['status' => 'inactive']))->get('/offline/session')->assertJsonPath('scope', null);
    }
}
