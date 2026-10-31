<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Barangay;
use App\Models\Purok;
use App\Models\Resident;
use App\Models\ResidentQrCredential;
use App\Models\User;
use App\Support\QrAttendanceProof;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

/**
 * QR-1: server-verified QR attendance.
 *
 * A QR-scanner attendance submission is only accepted when it carries a
 * short-lived, single-use proof minted by the staff QR resolution endpoint for
 * the exact (staff, resident, announcement) operation. Manual attendance is
 * unaffected. Barangay isolation, audience eligibility, active-resident,
 * approved-announcement and requires_attendance rules must keep applying.
 */
class QrAttendanceProofTest extends TestCase
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

    private function resolveProof(User $staff, string $token, Announcement $announcement): string
    {
        $response = $this->actingAs($staff)
            ->getJson('/api/qr/verify-token?token='.urlencode($token).'&announcement_id='.$announcement->id)
            ->assertOk();

        return $response->json('qr_proof');
    }

    private function clerkMarkUrl(Announcement $announcement): string
    {
        return '/clerk/announcements/'.$announcement->id.'/attendance/mark';
    }

    private function seniorMarkUrl(Announcement $announcement): string
    {
        return '/senior/announcements/'.$announcement->id.'/attendance/mark';
    }

    private function pwdMarkUrl(Announcement $announcement): string
    {
        return '/pwd/announcements/'.$announcement->id.'/attendance/mark';
    }

    /*
    |--------------------------------------------------------------------------
    | Proof issuance endpoint
    |--------------------------------------------------------------------------
    */

    public function test_active_credential_with_announcement_mints_a_proof(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170005001']);
        $credential = $this->makeCredential($resident);
        $announcement = $this->createAnnouncement($this->barangay, ['requires_attendance' => true]);

        $this->actingAs($clerk)
            ->getJson('/api/qr/verify-token?token='.urlencode($credential->token).'&announcement_id='.$announcement->id)
            ->assertOk()
            ->assertJsonPath('resident.id', $resident->id)
            ->assertJsonPath('qr_proof', fn ($proof) => is_string($proof) && $proof !== '');
    }

    public function test_resident_number_cannot_mint_a_proof(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170005002']);
        $announcement = $this->createAnnouncement($this->barangay, ['requires_attendance' => true]);

        $this->actingAs($clerk)
            ->getJson('/api/qr/verify-token?token='.urlencode($resident->resident_number).'&announcement_id='.$announcement->id)
            ->assertStatus(422);

        $this->assertDatabaseCount('announcement_attendances', 0);
    }

    public function test_resident_number_still_resolves_without_announcement(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170005003']);

        $this->actingAs($clerk)
            ->getJson('/api/qr/verify-token?token='.urlencode($resident->resident_number))
            ->assertOk()
            ->assertJsonPath('resident.id', $resident->id)
            ->assertJsonPath('qr_proof', null);
    }

    public function test_pending_announcement_cannot_mint_a_proof(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170005004']);
        $credential = $this->makeCredential($resident);
        $announcement = $this->createAnnouncement($this->barangay, [
            'approval_status' => 'pending',
            'is_published' => false,
            'requires_attendance' => true,
        ]);

        $this->actingAs($clerk)
            ->getJson('/api/qr/verify-token?token='.urlencode($credential->token).'&announcement_id='.$announcement->id)
            ->assertStatus(422);
    }

    public function test_announcement_without_attendance_cannot_mint_a_proof(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170005005']);
        $credential = $this->makeCredential($resident);
        $announcement = $this->createAnnouncement($this->barangay, ['requires_attendance' => false]);

        $this->actingAs($clerk)
            ->getJson('/api/qr/verify-token?token='.urlencode($credential->token).'&announcement_id='.$announcement->id)
            ->assertStatus(422);
    }

    /*
    |--------------------------------------------------------------------------
    | Proof consumption
    |--------------------------------------------------------------------------
    */

    public function test_valid_proof_records_qr_attendance(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170005006']);
        $credential = $this->makeCredential($resident);
        $announcement = $this->createAnnouncement($this->barangay, ['requires_attendance' => true]);

        $proof = $this->resolveProof($clerk, $credential->token, $announcement);

        $this->actingAs($clerk)->post($this->clerkMarkUrl($announcement), [
            'resident_id' => $resident->id,
            'status' => 'present',
            'method' => 'qr_scanner',
            'qr_proof' => $proof,
        ])->assertRedirect();

        $this->assertDatabaseHas('announcement_attendances', [
            'announcement_id' => $announcement->id,
            'resident_id' => $resident->id,
            'method' => 'qr_scanner',
            'verified_by' => $clerk->id,
        ]);
    }

    public function test_qr_scanner_without_proof_is_rejected(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170005007']);
        $announcement = $this->createAnnouncement($this->barangay, ['requires_attendance' => true]);

        $this->actingAs($clerk)->post($this->clerkMarkUrl($announcement), [
            'resident_id' => $resident->id,
            'status' => 'present',
            'method' => 'qr_scanner',
        ])->assertSessionHasErrors('qr_proof');

        $this->assertDatabaseCount('announcement_attendances', 0);
    }

    public function test_altered_proof_is_rejected(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170005008']);
        $credential = $this->makeCredential($resident);
        $announcement = $this->createAnnouncement($this->barangay, ['requires_attendance' => true]);

        $proof = $this->resolveProof($clerk, $credential->token, $announcement);
        $altered = substr_replace($proof, $proof[10] === 'A' ? 'B' : 'A', 10, 1);

        $this->actingAs($clerk)->post($this->clerkMarkUrl($announcement), [
            'resident_id' => $resident->id,
            'status' => 'present',
            'method' => 'qr_scanner',
            'qr_proof' => $altered,
        ])->assertSessionHasErrors('qr_proof');

        $this->assertDatabaseCount('announcement_attendances', 0);
    }

    public function test_replayed_proof_is_rejected(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170005009']);
        $credential = $this->makeCredential($resident);
        $announcement = $this->createAnnouncement($this->barangay, ['requires_attendance' => true]);

        $proof = $this->resolveProof($clerk, $credential->token, $announcement);

        $this->actingAs($clerk)->post($this->clerkMarkUrl($announcement), [
            'resident_id' => $resident->id,
            'status' => 'present',
            'method' => 'qr_scanner',
            'qr_proof' => $proof,
        ])->assertRedirect();

        $this->actingAs($clerk)->post($this->clerkMarkUrl($announcement), [
            'resident_id' => $resident->id,
            'status' => 'claimed',
            'method' => 'qr_scanner',
            'qr_proof' => $proof,
        ])->assertSessionHasErrors('qr_proof');

        $this->assertDatabaseCount('announcement_attendances', 1);
        $this->assertDatabaseHas('announcement_attendances', [
            'resident_id' => $resident->id,
            'status' => 'present',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Atomic single-use consumption
    |--------------------------------------------------------------------------
    |
    | Two concurrent requests carrying the same proof must not both succeed.
    | A single PHPUnit process cannot faithfully interleave two parallel
    | requests, so the race is covered in three layers:
    |   1. the service refuses a second consumption of the same proof;
    |   2. the underlying atomic claim primitive (Cache::add) admits exactly one
    |      winner for a key, which is what makes the race safe on the real
    |      database cache store (insertOrIgnore against the cache table's
    |      unique key);
    |   3. consumption rejects a proof whose nonce was already claimed by
    |      another request.
    */

    public function test_proof_can_only_be_consumed_once_at_the_service_layer(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170005101']);
        $announcement = $this->createAnnouncement($this->barangay, ['requires_attendance' => true]);

        $proof = QrAttendanceProof::issue($clerk, $resident, $announcement);

        QrAttendanceProof::consume($proof, $clerk, $resident->id, $announcement->id);

        $this->expectException(ValidationException::class);

        QrAttendanceProof::consume($proof, $clerk, $resident->id, $announcement->id);
    }

    public function test_atomic_claim_primitive_admits_exactly_one_winner(): void
    {
        $key = 'qr-attendance-proof:test-'.Str::uuid();

        $this->assertTrue(Cache::add($key, true, 60));
        $this->assertFalse(Cache::add($key, true, 60));
    }

    public function test_issue_does_not_preclaim_a_nonce(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170005102']);
        $announcement = $this->createAnnouncement($this->barangay, ['requires_attendance' => true]);

        $proof = QrAttendanceProof::issue($clerk, $resident, $announcement);
        $claimKey = $this->claimKeyFor($proof);

        $this->assertFalse(Cache::has($claimKey));

        QrAttendanceProof::consume($proof, $clerk, $resident->id, $announcement->id);

        $this->assertTrue(Cache::has($claimKey));
    }

    public function test_proof_binds_the_issuing_user_id(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170005104']);
        $announcement = $this->createAnnouncement($this->barangay, ['requires_attendance' => true]);

        $payload = json_decode(Crypt::decryptString(QrAttendanceProof::issue($clerk, $resident, $announcement)), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($clerk->id, $payload['user_id']);
        $this->assertArrayNotHasKey('staff_id', $payload);
    }

    public function test_proof_is_rejected_when_another_request_has_already_claimed_it(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170005103']);
        $announcement = $this->createAnnouncement($this->barangay, ['requires_attendance' => true]);

        $proof = QrAttendanceProof::issue($clerk, $resident, $announcement);

        // Simulate the concurrent request that won the claim first.
        $this->assertTrue(Cache::add($this->claimKeyFor($proof), true, 60));

        $this->expectException(ValidationException::class);

        QrAttendanceProof::consume($proof, $clerk, $resident->id, $announcement->id);
    }

    /**
     * Resolve the cache key the service uses to claim a proof's nonce.
     */
    private function claimKeyFor(string $proof): string
    {
        $jti = json_decode(Crypt::decryptString($proof), true, flags: JSON_THROW_ON_ERROR)['jti'];
        $prefix = (new \ReflectionClass(QrAttendanceProof::class))->getConstant('CACHE_PREFIX');

        return $prefix.$jti;
    }

    public function test_expired_proof_is_rejected(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170005010']);
        $credential = $this->makeCredential($resident);
        $announcement = $this->createAnnouncement($this->barangay, ['requires_attendance' => true]);

        $proof = $this->resolveProof($clerk, $credential->token, $announcement);

        $this->travel(3)->minutes();

        $this->actingAs($clerk)->post($this->clerkMarkUrl($announcement), [
            'resident_id' => $resident->id,
            'status' => 'present',
            'method' => 'qr_scanner',
            'qr_proof' => $proof,
        ])->assertSessionHasErrors('qr_proof');

        $this->assertDatabaseCount('announcement_attendances', 0);
    }

    public function test_proof_for_another_resident_is_rejected(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170005011']);
        $otherResident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170005012']);
        $credential = $this->makeCredential($resident);
        $announcement = $this->createAnnouncement($this->barangay, ['requires_attendance' => true]);

        $proof = $this->resolveProof($clerk, $credential->token, $announcement);

        $this->actingAs($clerk)->post($this->clerkMarkUrl($announcement), [
            'resident_id' => $otherResident->id,
            'status' => 'present',
            'method' => 'qr_scanner',
            'qr_proof' => $proof,
        ])->assertSessionHasErrors('qr_proof');

        $this->assertDatabaseCount('announcement_attendances', 0);
    }

    public function test_proof_for_another_announcement_is_rejected(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170005013']);
        $credential = $this->makeCredential($resident);
        $announcement = $this->createAnnouncement($this->barangay, ['requires_attendance' => true]);
        $otherAnnouncement = $this->createAnnouncement($this->barangay, ['requires_attendance' => true]);

        $proof = $this->resolveProof($clerk, $credential->token, $announcement);

        $this->actingAs($clerk)->post($this->clerkMarkUrl($otherAnnouncement), [
            'resident_id' => $resident->id,
            'status' => 'present',
            'method' => 'qr_scanner',
            'qr_proof' => $proof,
        ])->assertSessionHasErrors('qr_proof');

        $this->assertDatabaseCount('announcement_attendances', 0);
    }

    public function test_proof_for_another_staff_is_rejected(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $otherClerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170005014']);
        $credential = $this->makeCredential($resident);
        $announcement = $this->createAnnouncement($this->barangay, ['requires_attendance' => true]);

        $proof = $this->resolveProof($clerk, $credential->token, $announcement);

        $this->actingAs($otherClerk)->post($this->clerkMarkUrl($announcement), [
            'resident_id' => $resident->id,
            'status' => 'present',
            'method' => 'qr_scanner',
            'qr_proof' => $proof,
        ])->assertSessionHasErrors('qr_proof');

        $this->assertDatabaseCount('announcement_attendances', 0);
    }

    public function test_reissued_proof_does_not_replace_an_existing_claim(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170005015']);
        $credential = $this->makeCredential($resident);
        $announcement = $this->createAnnouncement($this->barangay, ['requires_attendance' => true]);

        $firstProof = $this->resolveProof($clerk, $credential->token, $announcement);
        $this->actingAs($clerk)->post($this->clerkMarkUrl($announcement), [
            'resident_id' => $resident->id,
            'status' => 'claimed',
            'method' => 'qr_scanner',
            'qr_proof' => $firstProof,
        ])->assertRedirect()->assertSessionHas('attendance_outcome', 'recorded');

        $secondProof = $this->resolveProof($clerk, $credential->token, $announcement);
        $this->actingAs($clerk)->post($this->clerkMarkUrl($announcement), [
            'resident_id' => $resident->id,
            'status' => 'present',
            'method' => 'qr_scanner',
            'qr_proof' => $secondProof,
        ])->assertRedirect()->assertSessionHas('attendance_outcome', 'already_claimed');

        $this->assertDatabaseCount('announcement_attendances', 1);
        $this->assertDatabaseHas('announcement_attendances', [
            'resident_id' => $resident->id,
            'status' => 'claimed',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Existing preconditions must keep applying to QR attendance
    |--------------------------------------------------------------------------
    */

    public function test_manual_attendance_still_works_without_proof(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170005016']);
        $announcement = $this->createAnnouncement($this->barangay, ['requires_attendance' => true]);

        $this->actingAs($clerk)->post($this->clerkMarkUrl($announcement), [
            'resident_id' => $resident->id,
            'status' => 'present',
            'method' => 'manual',
        ])->assertRedirect();

        $this->assertDatabaseHas('announcement_attendances', [
            'resident_id' => $resident->id,
            'method' => 'manual',
        ]);
    }

    public function test_qr_attendance_rejects_foreign_resident(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $otherPurok = $this->createPurok($this->other, 'Purok 2');
        $foreignResident = $this->createResident($this->other, $otherPurok, ['mobile' => '09170006001']);
        $announcement = $this->createAnnouncement($this->barangay, ['requires_attendance' => true]);

        $this->actingAs($clerk)->post($this->clerkMarkUrl($announcement), [
            'resident_id' => $foreignResident->id,
            'status' => 'present',
            'method' => 'qr_scanner',
            'qr_proof' => 'anything',
        ])->assertStatus(404);

        $this->assertDatabaseCount('announcement_attendances', 0);
    }

    public function test_qr_attendance_rejects_wrong_sector_resident(): void
    {
        $staff = $this->createStaff($this->barangay, 'pwd_staff');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170005017']);
        $credential = $this->makeCredential($resident);
        $announcement = $this->createAnnouncement($this->barangay, [
            'target_audience' => 'pwd',
            'requires_attendance' => true,
        ]);

        $proof = $this->resolveProof($staff, $credential->token, $announcement);

        $this->actingAs($staff)->post($this->pwdMarkUrl($announcement), [
            'resident_id' => $resident->id,
            'status' => 'present',
            'method' => 'qr_scanner',
            'qr_proof' => $proof,
        ])->assertStatus(403);

        $this->assertDatabaseCount('announcement_attendances', 0);
    }

    public function test_qr_attendance_rejects_inactive_resident(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170005018']);
        $resident->update(['status' => 'inactive']);
        $announcement = $this->createAnnouncement($this->barangay, ['requires_attendance' => true]);

        $this->actingAs($clerk)->post($this->clerkMarkUrl($announcement), [
            'resident_id' => $resident->id,
            'status' => 'present',
            'method' => 'qr_scanner',
            'qr_proof' => 'anything',
        ])->assertStatus(403);

        $this->assertDatabaseCount('announcement_attendances', 0);
    }

    public function test_inactive_proof_does_not_overwrite_existing_manual_record(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170005019']);
        $announcement = $this->createAnnouncement($this->barangay, ['requires_attendance' => true]);

        $this->actingAs($clerk)->post($this->clerkMarkUrl($announcement), [
            'resident_id' => $resident->id,
            'status' => 'present',
            'method' => 'manual',
        ])->assertRedirect();

        $this->actingAs($clerk)->post($this->clerkMarkUrl($announcement), [
            'resident_id' => $resident->id,
            'status' => 'claimed',
            'method' => 'qr_scanner',
            'qr_proof' => 'not-a-real-proof',
        ])->assertSessionHasErrors('qr_proof');

        $this->assertDatabaseCount('announcement_attendances', 1);
        $this->assertDatabaseHas('announcement_attendances', [
            'resident_id' => $resident->id,
            'status' => 'present',
            'method' => 'manual',
        ]);
    }

    public function test_senior_qr_attendance_with_valid_proof_is_recorded(): void
    {
        $staff = $this->createStaff($this->barangay, 'senior_staff');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170005020']);
        $this->assignSector($resident, 'senior');
        $credential = $this->makeCredential($resident);
        $announcement = $this->createAnnouncement($this->barangay, [
            'target_audience' => 'senior_citizens',
            'requires_attendance' => true,
        ]);

        $proof = $this->resolveProof($staff, $credential->token, $announcement);

        $this->actingAs($staff)->post($this->seniorMarkUrl($announcement), [
            'resident_id' => $resident->id,
            'status' => 'claimed',
            'method' => 'qr_scanner',
            'qr_proof' => $proof,
        ])->assertRedirect();

        $this->assertDatabaseHas('announcement_attendances', [
            'resident_id' => $resident->id,
            'method' => 'qr_scanner',
            'verified_by' => $staff->id,
        ]);
    }
}
