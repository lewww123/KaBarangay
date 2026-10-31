<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Purok;
use App\Models\Resident;
use App\Models\ResidentQrCredential;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

/**
 * QR-3: administrator-authorized, barangay-scoped QR credential revocation and
 * secure reissue.
 *
 * A revoked credential must stop working everywhere (public verification,
 * staff token resolution, and QR attendance-proof issuance), a reissue must
 * invalidate the previous token the moment the replacement lands, exactly one
 * credential row may exist per resident, and cross-barangay or non-admin
 * attempts must be rejected. The raw token is never exposed in the admin list.
 */
class QrCredentialRevocationTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Barangay $other;

    private Purok $purok;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $this->other = $this->createBarangay('Barangay Two', 'BRGY-TWO');
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

    private function revokeUrl(Resident $resident): string
    {
        return '/admin/residents/'.$resident->id.'/qr/revoke';
    }

    private function reissueUrl(Resident $resident): string
    {
        return '/admin/residents/'.$resident->id.'/qr/reissue';
    }

    private function tokenFor(Resident $resident): string
    {
        return ResidentQrCredential::where('resident_id', $resident->id)
            ->firstOrFail()
            ->token;
    }

    /*
    |--------------------------------------------------------------------------
    | Revocation (authorized)
    |--------------------------------------------------------------------------
    */

    public function test_admin_can_revoke_an_active_qr_credential(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170009001',
        ]);
        $credential = $this->makeCredential($resident);
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post($this->revokeUrl($resident))
            ->assertRedirect()
            ->assertSessionHas('success');

        $credential->refresh();

        $this->assertFalse($credential->is_active);
        $this->assertNotNull($credential->revoked_at);
    }

    public function test_revoking_without_a_credential_fails(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170009002',
        ]);
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post($this->revokeUrl($resident))
            ->assertSessionHasErrors('qr');

        $this->assertDatabaseCount('resident_qr_credentials', 0);
    }

    public function test_revoking_an_already_revoked_credential_fails(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170009003',
        ]);
        $credential = $this->makeCredential($resident, [
            'is_active' => false,
            'revoked_at' => now()->subDay(),
        ]);
        $originalRevokedAt = $credential->revoked_at;
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post($this->revokeUrl($resident))
            ->assertSessionHasErrors('qr');

        $credential->refresh();

        $this->assertFalse($credential->is_active);
        $this->assertEquals(
            $originalRevokedAt?->getTimestamp(),
            $credential->revoked_at?->getTimestamp()
        );
        $this->assertDatabaseCount('resident_qr_credentials', 1);
    }

    /*
    |--------------------------------------------------------------------------
    | Revoked credential must fail every consumer
    |--------------------------------------------------------------------------
    */

    public function test_revoked_credential_fails_public_verification(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170009004',
        ]);
        $credential = $this->makeCredential($resident);
        $admin = $this->createStaff($this->barangay, 'admin');

        $token = $credential->token;

        $this->actingAs($admin)->post($this->revokeUrl($resident));

        $this->get('/verify/resident/'.$token)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Resident/VerifyResident')
                ->where('verification.valid', false)
                ->where('verification.barangay', null));
    }

    public function test_active_credential_passes_public_verification(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170009005',
        ]);
        $credential = $this->makeCredential($resident);

        $this->get('/verify/resident/'.$credential->token)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Resident/VerifyResident')
                ->where('verification.valid', true)
                ->where('verification.barangay', null));
    }

    public function test_revoked_credential_fails_staff_token_resolution(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170009006',
        ]);
        $credential = $this->makeCredential($resident);
        $admin = $this->createStaff($this->barangay, 'admin');
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $token = $credential->token;

        $this->actingAs($admin)->post($this->revokeUrl($resident));

        $this->actingAs($clerk)
            ->getJson('/api/qr/verify-token?token='.urlencode($token))
            ->assertStatus(404);
    }

    public function test_revoked_credential_cannot_mint_an_attendance_proof(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170009007',
        ]);
        $credential = $this->makeCredential($resident);
        $admin = $this->createStaff($this->barangay, 'admin');
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $announcement = $this->createAnnouncement($this->barangay, [
            'requires_attendance' => true,
        ]);

        $token = $credential->token;

        $this->actingAs($admin)->post($this->revokeUrl($resident));

        $this->actingAs($clerk)
            ->getJson(
                '/api/qr/verify-token?token='.urlencode($token).
                '&announcement_id='.$announcement->id
            )
            ->assertStatus(404);

        $this->assertDatabaseCount('announcement_attendances', 0);
    }

    public function test_credential_with_revoked_at_set_cannot_resolve_or_verify(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170009008',
        ]);
        $credential = $this->makeCredential($resident, [
            'is_active' => true,
            'revoked_at' => now()->subMinute(),
        ]);
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->getJson('/api/qr/verify-token?token='.urlencode($credential->token))
            ->assertStatus(404);

        $this->get('/verify/resident/'.$credential->token)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('verification.valid', false));
    }

    /*
    |--------------------------------------------------------------------------
    | Secure reissue
    |--------------------------------------------------------------------------
    */

    public function test_admin_can_reissue_and_previous_token_stops_working_immediately(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170009009',
        ]);
        $old = $this->makeCredential($resident);
        $oldToken = $old->token;
        $admin = $this->createStaff($this->barangay, 'admin');
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($admin)
            ->post($this->reissueUrl($resident))
            ->assertRedirect()
            ->assertSessionHas('success');

        $newToken = $this->tokenFor($resident);

        $this->assertNotSame($oldToken, $newToken);
        $this->assertStringStartsWith('KBQR-', $newToken);

        // The previous token is invalid everywhere, immediately.
        $this->get('/verify/resident/'.$oldToken)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('verification.valid', false));

        $this->actingAs($clerk)
            ->getJson('/api/qr/verify-token?token='.urlencode($oldToken))
            ->assertStatus(404);

        // The replacement token works.
        $this->get('/verify/resident/'.$newToken)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('verification.valid', true));

        $this->actingAs($clerk)
            ->getJson('/api/qr/verify-token?token='.urlencode($newToken))
            ->assertOk()
            ->assertJsonPath('resident.id', $resident->id);
    }

    public function test_reissue_reactivates_a_revoked_credential_with_a_new_token(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170009010',
        ]);
        $old = $this->makeCredential($resident, [
            'is_active' => false,
            'revoked_at' => now()->subDay(),
        ]);
        $oldToken = $old->token;
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post($this->reissueUrl($resident))
            ->assertRedirect();

        $fresh = ResidentQrCredential::where('resident_id', $resident->id)->firstOrFail();

        $this->assertTrue($fresh->is_active);
        $this->assertNull($fresh->revoked_at);
        $this->assertNotSame($oldToken, $fresh->token);
        $this->assertDatabaseCount('resident_qr_credentials', 1);
    }

    public function test_reissue_creates_a_credential_when_none_exists(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170009011',
        ]);
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->assertDatabaseCount('resident_qr_credentials', 0);

        $this->actingAs($admin)
            ->post($this->reissueUrl($resident))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('resident_qr_credentials', [
            'resident_id' => $resident->id,
            'is_active' => 1,
            'revoked_at' => null,
        ]);
        $this->assertDatabaseCount('resident_qr_credentials', 1);
    }

    public function test_reissue_keeps_exactly_one_credential_row_per_resident(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170009012',
        ]);
        $this->makeCredential($resident);
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)->post($this->reissueUrl($resident));
        $this->actingAs($admin)->post($this->reissueUrl($resident));

        $this->assertDatabaseCount('resident_qr_credentials', 1);

        $credential = ResidentQrCredential::where('resident_id', $resident->id)->firstOrFail();
        $this->assertTrue($credential->is_active);
        $this->assertNull($credential->revoked_at);
    }

    public function test_database_prevents_two_credentials_for_same_resident(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170009013',
        ]);
        $this->makeCredential($resident);

        $this->expectException(QueryException::class);

        $this->makeCredential($resident);
    }

    public function test_previous_token_cannot_obtain_an_attendance_proof_after_reissue(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170009021',
        ]);
        $old = $this->makeCredential($resident);
        $oldToken = $old->token;
        $admin = $this->createStaff($this->barangay, 'admin');
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $announcement = $this->createAnnouncement($this->barangay, [
            'requires_attendance' => true,
        ]);

        $this->actingAs($admin)
            ->post($this->reissueUrl($resident))
            ->assertRedirect()
            ->assertSessionHas('success');

        // The previous token can no longer obtain an attendance proof.
        $this->actingAs($clerk)
            ->getJson(
                '/api/qr/verify-token?token='.urlencode($oldToken).
                '&announcement_id='.$announcement->id
            )
            ->assertStatus(404);

        // The replacement token still can.
        $newToken = $this->tokenFor($resident);

        $this->actingAs($clerk)
            ->getJson(
                '/api/qr/verify-token?token='.urlencode($newToken).
                '&announcement_id='.$announcement->id
            )
            ->assertOk()
            ->assertJsonPath('qr_proof', fn ($proof) => is_string($proof) && $proof !== '');

        $this->assertDatabaseCount('announcement_attendances', 0);
    }

    public function test_reissue_surfaces_a_validation_error_when_a_concurrent_request_wins_the_resident_id_race(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170009022',
        ]);
        $admin = $this->createStaff($this->barangay, 'admin');

        /*
        |--------------------------------------------------------------------------
        | Deterministic UNIQUE(resident_id) race
        |--------------------------------------------------------------------------
        |
        | This injects the exact database failure a losing concurrent reissue
        | would hit: a competing row for the same resident appears between the
        | locked read and the insert, so the UNIQUE(resident_id) constraint
        | rejects the insert. It exercises the real constraint through the real
        | endpoint; it does not simulate threads or timing.
        |
        */

        Event::listen(
            'eloquent.creating: '.ResidentQrCredential::class,
            function (ResidentQrCredential $model): void {
                DB::table('resident_qr_credentials')->insert([
                    'resident_id' => $model->resident_id,
                    'token' => 'KBQR-CONCURRENT-WINNER-'.Str::random(8),
                    'is_active' => true,
                    'generated_at' => now(),
                    'revoked_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        );

        $this->actingAs($admin)
            ->post($this->reissueUrl($resident))
            ->assertRedirect()
            ->assertSessionHasErrors('qr');

        // The failed transaction rolled back cleanly: no partial credential.
        $this->assertDatabaseCount('resident_qr_credentials', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */

    public function test_revoke_and_reissue_require_admin_role(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170009014',
        ]);
        $this->makeCredential($resident);
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->post($this->revokeUrl($resident))
            ->assertStatus(403);

        $this->actingAs($clerk)
            ->post($this->reissueUrl($resident))
            ->assertStatus(403);

        $this->assertTrue(
            ResidentQrCredential::where('resident_id', $resident->id)->firstOrFail()->is_active
        );
    }

    public function test_cross_barangay_revoke_and_reissue_return_404(): void
    {
        $otherPurok = $this->createPurok($this->other, 'Purok 2');
        $otherResident = $this->createResident($this->other, $otherPurok, [
            'mobile' => '09170009015',
        ]);
        $credential = $this->makeCredential($otherResident);
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post($this->revokeUrl($otherResident))
            ->assertStatus(404);

        $this->actingAs($admin)
            ->post($this->reissueUrl($otherResident))
            ->assertStatus(404);

        $credential->refresh();

        $this->assertTrue($credential->is_active);
        $this->assertNull($credential->revoked_at);
    }

    /*
    |--------------------------------------------------------------------------
    | Admin list exposure
    |--------------------------------------------------------------------------
    */

    public function test_index_exposes_qr_status_and_never_the_raw_token(): void
    {
        $active = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170009016',
            'last_name' => 'Alpha',
        ]);
        $activeCredential = $this->makeCredential($active);

        $revoked = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170009017',
            'last_name' => 'Bravo',
        ]);
        $this->makeCredential($revoked, [
            'is_active' => false,
            'revoked_at' => now(),
        ]);

        $none = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170009018',
            'last_name' => 'Charlie',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/residents?resident_search=Alpha')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Residents')
                ->where('allResidents.data.0.qrStatus', 'active')
                ->missing('allResidents.data.0.token'))
            ->assertDontSee($activeCredential->token)
            ->assertDontSee('KBQR-');

        $this->actingAs($admin)
            ->get('/admin/residents?resident_search=Bravo')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('allResidents.data.0.qrStatus', 'revoked'));

        $this->actingAs($admin)
            ->get('/admin/residents?resident_search=Charlie')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('allResidents.data.0.qrStatus', 'none'));
    }

    /*
    |--------------------------------------------------------------------------
    | Resident QR-display regressions
    |--------------------------------------------------------------------------
    */

    public function test_resident_dashboard_qr_url_reflects_credential_state(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170009019',
        ]);
        $credential = $this->makeCredential($resident);
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->withSession(['resident_id' => $resident->id])
            ->get('/resident')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Resident/Dashboard')
                ->where('qrUrl', route('resident.qr.verify', ['token' => $credential->token])));

        $this->actingAs($admin)->post($this->revokeUrl($resident));

        $this->withSession(['resident_id' => $resident->id])
            ->get('/resident')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('qrUrl', null));
    }

    public function test_resident_profile_qr_token_reflects_credential_state(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170009020',
        ]);
        $credential = $this->makeCredential($resident);
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->withSession(['resident_id' => $resident->id])
            ->get('/resident/profile')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Resident/Profile')
                ->where('profile.qrToken', $credential->token));

        $this->actingAs($admin)->post($this->revokeUrl($resident));

        $this->withSession(['resident_id' => $resident->id])
            ->get('/resident/profile')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('profile.qrToken', null));
    }
}
