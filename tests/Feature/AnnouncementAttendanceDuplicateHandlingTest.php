<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\AnnouncementAttendance;
use App\Models\Barangay;
use App\Models\Purok;
use App\Models\Resident;
use App\Models\ResidentQrCredential;
use App\Models\User;
use App\Support\QrAttendanceProof;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class AnnouncementAttendanceDuplicateHandlingTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Barangay $otherBarangay;

    private Purok $purok;

    private int $residentSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $this->otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $this->purok = $this->createPurok($this->barangay, 'Purok 1');
    }

    public static function portalProvider(): array
    {
        return [
            'clerk' => ['clerk', 'clerk', null, 'all'],
            'pwd staff' => ['pwd', 'pwd_staff', 'pwd', 'pwd'],
            'senior staff' => ['senior', 'senior_staff', 'senior', 'senior_citizens'],
        ];
    }

    private function markUrl(string $portal, Announcement $announcement): string
    {
        return '/'.$portal.'/announcements/'.$announcement->id.'/attendance/mark';
    }

    private function newResident(): Resident
    {
        $this->residentSequence++;

        return $this->createResident($this->barangay, $this->purok, [
            'mobile' => '0917'.str_pad((string) $this->residentSequence, 7, '0', STR_PAD_LEFT),
        ]);
    }

    /**
     * @return array{0: User, 1: Resident, 2: Announcement}
     */
    private function portalFixture(string $role, ?string $sector, string $audience): array
    {
        $staff = $this->createStaff($this->barangay, $role);
        $resident = $this->newResident();

        if ($sector !== null) {
            $this->assignSector($resident, $sector);
        }

        $announcement = $this->createAnnouncement($this->barangay, [
            'target_audience' => $audience,
            'requires_attendance' => true,
        ]);

        return [$staff, $resident, $announcement];
    }

    private function makeCredential(Resident $resident): ResidentQrCredential
    {
        return ResidentQrCredential::create([
            'resident_id' => $resident->id,
            'token' => 'KBQR-'.Str::random(64),
            'is_active' => true,
            'generated_at' => now(),
        ]);
    }

    private function resolveProof(User $staff, ResidentQrCredential $credential, Announcement $announcement, Resident $resident): string
    {
        $response = $this->actingAs($staff)
            ->getJson('/api/qr/verify-token?token='.urlencode($credential->token).'&announcement_id='.$announcement->id)
            ->assertOk()
            ->assertJsonPath('resident.id', $resident->id);

        $proof = $response->json('qr_proof');

        $this->assertIsString($proof);
        $this->assertNotSame('', $proof);

        return $proof;
    }

    #[DataProvider('portalProvider')]
    public function test_first_attendance_is_recorded_for_each_portal(
        string $portal,
        string $role,
        ?string $sector,
        string $audience,
    ): void {
        [$staff, $resident, $announcement] = $this->portalFixture($role, $sector, $audience);

        $this->actingAs($staff)->post($this->markUrl($portal, $announcement), [
            'resident_id' => $resident->id,
            'status' => 'present',
            'method' => 'manual',
        ])->assertRedirect()
            ->assertSessionHas('attendance_outcome', 'recorded')
            ->assertSessionHas('success');

        $this->assertDatabaseCount('announcement_attendances', 1);
        $this->assertDatabaseHas('announcement_attendances', [
            'announcement_id' => $announcement->id,
            'resident_id' => $resident->id,
            'status' => 'present',
            'method' => 'manual',
            'verified_by' => $staff->id,
        ]);
    }

    #[DataProvider('portalProvider')]
    public function test_duplicate_present_request_preserves_the_original_record(
        string $portal,
        string $role,
        ?string $sector,
        string $audience,
    ): void {
        [$staff, $resident, $announcement] = $this->portalFixture($role, $sector, $audience);
        $secondStaff = $this->createStaff($this->barangay, $role);

        $this->actingAs($staff)->post($this->markUrl($portal, $announcement), [
            'resident_id' => $resident->id,
            'status' => 'present',
            'method' => 'manual',
        ])->assertRedirect()->assertSessionHas('attendance_outcome', 'recorded');

        $original = AnnouncementAttendance::firstOrFail();
        $attendedAt = $original->attended_at->getTimestamp();
        $method = $original->method;
        $verifier = $original->verified_by;

        $this->actingAs($secondStaff)->post($this->markUrl($portal, $announcement), [
            'resident_id' => $resident->id,
            'status' => 'present',
            'method' => 'manual',
        ])->assertRedirect()
            ->assertSessionHas('attendance_outcome', 'already_present')
            ->assertSessionHas('warning', 'Already present. This attendee has already been scanned.');

        $this->assertDatabaseCount('announcement_attendances', 1);
        $fresh = $original->fresh();
        $this->assertSame('present', $fresh->status);
        $this->assertSame($attendedAt, $fresh->attended_at->getTimestamp());
        $this->assertSame($method, $fresh->method);
        $this->assertSame($verifier, $fresh->verified_by);
    }

    #[DataProvider('portalProvider')]
    public function test_duplicate_claimed_request_preserves_the_original_record(
        string $portal,
        string $role,
        ?string $sector,
        string $audience,
    ): void {
        [$staff, $resident, $announcement] = $this->portalFixture($role, $sector, $audience);
        $secondStaff = $this->createStaff($this->barangay, $role);

        $this->actingAs($staff)->post($this->markUrl($portal, $announcement), [
            'resident_id' => $resident->id,
            'status' => 'claimed',
            'method' => 'manual',
        ])->assertRedirect()->assertSessionHas('attendance_outcome', 'recorded');

        $original = AnnouncementAttendance::firstOrFail();
        $attendedAt = $original->attended_at->getTimestamp();
        $method = $original->method;
        $verifier = $original->verified_by;

        $this->actingAs($secondStaff)->post($this->markUrl($portal, $announcement), [
            'resident_id' => $resident->id,
            'status' => 'claimed',
            'method' => 'manual',
        ])->assertRedirect()
            ->assertSessionHas('attendance_outcome', 'already_claimed')
            ->assertSessionHas('warning', 'Already claimed. This attendee has already claimed their aid.');

        $this->assertDatabaseCount('announcement_attendances', 1);
        $fresh = $original->fresh();
        $this->assertSame('claimed', $fresh->status);
        $this->assertSame($attendedAt, $fresh->attended_at->getTimestamp());
        $this->assertSame($method, $fresh->method);
        $this->assertSame($verifier, $fresh->verified_by);
    }

    #[DataProvider('portalProvider')]
    public function test_present_followed_by_claimed_reports_existing_record_conflict(
        string $portal,
        string $role,
        ?string $sector,
        string $audience,
    ): void {
        [$staff, $resident, $announcement] = $this->portalFixture($role, $sector, $audience);

        $this->actingAs($staff)->post($this->markUrl($portal, $announcement), [
            'resident_id' => $resident->id,
            'status' => 'present',
            'method' => 'manual',
        ])->assertRedirect()->assertSessionHas('attendance_outcome', 'recorded');

        $original = AnnouncementAttendance::firstOrFail();
        $attendedAt = $original->attended_at->getTimestamp();

        $this->actingAs($staff)->post($this->markUrl($portal, $announcement), [
            'resident_id' => $resident->id,
            'status' => 'claimed',
            'method' => 'manual',
        ])->assertRedirect()
            ->assertSessionHas('attendance_outcome', 'existing_record_conflict')
            ->assertSessionHas('warning', fn (string $message) => str_contains($message, 'not supported in this phase'));

        $this->assertDatabaseCount('announcement_attendances', 1);
        $fresh = $original->fresh();
        $this->assertSame('present', $fresh->status);
        $this->assertSame($attendedAt, $fresh->attended_at->getTimestamp());
    }

    #[DataProvider('portalProvider')]
    public function test_claimed_followed_by_present_reports_already_claimed(
        string $portal,
        string $role,
        ?string $sector,
        string $audience,
    ): void {
        [$staff, $resident, $announcement] = $this->portalFixture($role, $sector, $audience);

        $this->actingAs($staff)->post($this->markUrl($portal, $announcement), [
            'resident_id' => $resident->id,
            'status' => 'claimed',
            'method' => 'manual',
        ])->assertRedirect()->assertSessionHas('attendance_outcome', 'recorded');

        $original = AnnouncementAttendance::firstOrFail();
        $attendedAt = $original->attended_at->getTimestamp();

        $this->actingAs($staff)->post($this->markUrl($portal, $announcement), [
            'resident_id' => $resident->id,
            'status' => 'present',
            'method' => 'manual',
        ])->assertRedirect()
            ->assertSessionHas('attendance_outcome', 'already_claimed')
            ->assertSessionHas('warning', 'Already claimed. This attendee has already claimed their aid.');

        $this->assertDatabaseCount('announcement_attendances', 1);
        $fresh = $original->fresh();
        $this->assertSame('claimed', $fresh->status);
        $this->assertSame($attendedAt, $fresh->attended_at->getTimestamp());
    }

    #[DataProvider('portalProvider')]
    public function test_duplicate_absent_request_reports_conflict_without_overwrite(
        string $portal,
        string $role,
        ?string $sector,
        string $audience,
    ): void {
        [$staff, $resident, $announcement] = $this->portalFixture($role, $sector, $audience);

        $this->actingAs($staff)->post($this->markUrl($portal, $announcement), [
            'resident_id' => $resident->id,
            'status' => 'absent',
            'method' => 'manual',
        ])->assertRedirect()->assertSessionHas('attendance_outcome', 'recorded');

        $original = AnnouncementAttendance::firstOrFail();
        $attendedAt = $original->attended_at->getTimestamp();

        $this->actingAs($staff)->post($this->markUrl($portal, $announcement), [
            'resident_id' => $resident->id,
            'status' => 'absent',
            'method' => 'manual',
        ])->assertRedirect()
            ->assertSessionHas('attendance_outcome', 'existing_record_conflict')
            ->assertSessionHas('warning');

        $this->assertDatabaseCount('announcement_attendances', 1);
        $fresh = $original->fresh();
        $this->assertSame('absent', $fresh->status);
        $this->assertSame($attendedAt, $fresh->attended_at->getTimestamp());
    }

    #[DataProvider('portalProvider')]
    public function test_duplicate_via_qr_after_manual_keeps_manual_metadata(
        string $portal,
        string $role,
        ?string $sector,
        string $audience,
    ): void {
        [$staff, $resident, $announcement] = $this->portalFixture($role, $sector, $audience);
        $secondStaff = $this->createStaff($this->barangay, $role);
        $credential = $this->makeCredential($resident);

        $this->actingAs($staff)->post($this->markUrl($portal, $announcement), [
            'resident_id' => $resident->id,
            'status' => 'present',
            'method' => 'manual',
        ])->assertRedirect()->assertSessionHas('attendance_outcome', 'recorded');

        $original = AnnouncementAttendance::firstOrFail();
        $attendedAt = $original->attended_at->getTimestamp();
        $proof = $this->resolveProof($secondStaff, $credential, $announcement, $resident);

        $this->actingAs($secondStaff)->post($this->markUrl($portal, $announcement), [
            'resident_id' => $resident->id,
            'status' => 'present',
            'method' => 'qr_scanner',
            'qr_proof' => $proof,
        ])->assertRedirect()
            ->assertSessionHas('attendance_outcome', 'already_present')
            ->assertSessionHas('warning');

        $this->assertDatabaseCount('announcement_attendances', 1);
        $fresh = $original->fresh();
        $this->assertSame('present', $fresh->status);
        $this->assertSame('manual', $fresh->method);
        $this->assertSame($attendedAt, $fresh->attended_at->getTimestamp());
        $this->assertSame($staff->id, $fresh->verified_by);
    }

    public function test_invalid_qr_proof_is_rejected_before_duplicate_or_insert_handling(): void
    {
        $staff = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->newResident();
        $announcement = $this->createAnnouncement($this->barangay, ['requires_attendance' => true]);

        $this->actingAs($staff)->post($this->markUrl('clerk', $announcement), [
            'resident_id' => $resident->id,
            'status' => 'present',
            'method' => 'qr_scanner',
            'qr_proof' => 'not-a-real-proof',
        ])->assertSessionHasErrors('qr_proof');

        $this->assertDatabaseCount('announcement_attendances', 0);
    }

    public function test_expired_qr_proof_is_rejected(): void
    {
        $staff = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->newResident();
        $announcement = $this->createAnnouncement($this->barangay, ['requires_attendance' => true]);
        $proof = QrAttendanceProof::issue($staff, $resident, $announcement);

        $this->travel(3)->minutes();

        $this->actingAs($staff)->post($this->markUrl('clerk', $announcement), [
            'resident_id' => $resident->id,
            'status' => 'present',
            'method' => 'qr_scanner',
            'qr_proof' => $proof,
        ])->assertSessionHasErrors('qr_proof');

        $this->assertDatabaseCount('announcement_attendances', 0);
    }

    public function test_reused_qr_proof_is_rejected_and_preserves_the_existing_record(): void
    {
        $staff = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->newResident();
        $credential = $this->makeCredential($resident);
        $announcement = $this->createAnnouncement($this->barangay, ['requires_attendance' => true]);
        $proof = $this->resolveProof($staff, $credential, $announcement, $resident);

        $this->actingAs($staff)->post($this->markUrl('clerk', $announcement), [
            'resident_id' => $resident->id,
            'status' => 'present',
            'method' => 'qr_scanner',
            'qr_proof' => $proof,
        ])->assertRedirect()->assertSessionHas('attendance_outcome', 'recorded');

        $this->actingAs($staff)->post($this->markUrl('clerk', $announcement), [
            'resident_id' => $resident->id,
            'status' => 'claimed',
            'method' => 'qr_scanner',
            'qr_proof' => $proof,
        ])->assertSessionHasErrors('qr_proof');

        $this->assertDatabaseCount('announcement_attendances', 1);
        $this->assertDatabaseHas('announcement_attendances', [
            'resident_id' => $resident->id,
            'status' => 'present',
            'method' => 'qr_scanner',
        ]);
    }

    public function test_unauthorized_role_cannot_mark_attendance(): void
    {
        $lupon = $this->createStaff($this->barangay, 'lupon');
        $resident = $this->newResident();
        $announcement = $this->createAnnouncement($this->barangay, ['requires_attendance' => true]);

        $this->actingAs($lupon)->post($this->markUrl('clerk', $announcement), [
            'resident_id' => $resident->id,
            'status' => 'present',
            'method' => 'manual',
        ])->assertStatus(403);

        $this->assertDatabaseCount('announcement_attendances', 0);
    }

    public function test_cross_barangay_announcement_and_resident_are_rejected(): void
    {
        $staff = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->newResident();
        $otherPurok = $this->createPurok($this->otherBarangay, 'Purok 2');
        $foreignResident = $this->createResident($this->otherBarangay, $otherPurok);
        $announcement = $this->createAnnouncement($this->barangay, ['requires_attendance' => true]);
        $foreignAnnouncement = $this->createAnnouncement($this->otherBarangay, ['requires_attendance' => true]);

        $this->actingAs($staff)->post($this->markUrl('clerk', $announcement), [
            'resident_id' => $foreignResident->id,
            'status' => 'present',
            'method' => 'manual',
        ])->assertStatus(404);

        $this->actingAs($staff)->post($this->markUrl('clerk', $foreignAnnouncement), [
            'resident_id' => $resident->id,
            'status' => 'present',
            'method' => 'manual',
        ])->assertStatus(404);

        $this->assertDatabaseCount('announcement_attendances', 0);
    }

    public function test_wrong_audience_and_sector_are_rejected(): void
    {
        $pwdStaff = $this->createStaff($this->barangay, 'pwd_staff');
        $seniorStaff = $this->createStaff($this->barangay, 'senior_staff');
        $nonPwdResident = $this->newResident();
        $seniorResident = $this->newResident();
        $this->assignSector($seniorResident, 'senior');

        $pwdAnnouncement = $this->createAnnouncement($this->barangay, [
            'target_audience' => 'pwd',
            'requires_attendance' => true,
        ]);
        $seniorAnnouncement = $this->createAnnouncement($this->barangay, [
            'target_audience' => 'senior_citizens',
            'requires_attendance' => true,
        ]);

        $this->actingAs($pwdStaff)->post($this->markUrl('pwd', $pwdAnnouncement), [
            'resident_id' => $nonPwdResident->id,
            'status' => 'present',
            'method' => 'manual',
        ])->assertStatus(403);

        $this->actingAs($seniorStaff)->post($this->markUrl('pwd', $seniorAnnouncement), [
            'resident_id' => $seniorResident->id,
            'status' => 'present',
            'method' => 'manual',
        ])->assertStatus(403);

        $this->assertDatabaseCount('announcement_attendances', 0);
    }

    public function test_inactive_resident_is_rejected(): void
    {
        $staff = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->newResident();
        $resident->update(['status' => 'inactive']);
        $announcement = $this->createAnnouncement($this->barangay, ['requires_attendance' => true]);

        $this->actingAs($staff)->post($this->markUrl('clerk', $announcement), [
            'resident_id' => $resident->id,
            'status' => 'present',
            'method' => 'manual',
        ])->assertStatus(403);

        $this->assertDatabaseCount('announcement_attendances', 0);
    }

    public function test_concurrent_insert_conflict_resolves_to_a_duplicate_without_overwriting(): void
    {
        $staff = $this->createStaff($this->barangay, 'clerk');
        $winner = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->newResident();
        $announcement = $this->createAnnouncement($this->barangay, ['requires_attendance' => true]);

        $insertedByRace = false;

        AnnouncementAttendance::creating(function (AnnouncementAttendance $attendance) use (&$insertedByRace, $resident, $announcement, $winner): void {
            if ($insertedByRace || $attendance->resident_id !== $resident->id) {
                return;
            }

            $insertedByRace = true;
            $winnerAttendedAt = now()->subMinutes(5);

            AnnouncementAttendance::withoutEvents(function () use ($resident, $announcement, $winner, $winnerAttendedAt): void {
                AnnouncementAttendance::create([
                    'barangay_id' => $announcement->barangay_id,
                    'announcement_id' => $announcement->id,
                    'resident_id' => $resident->id,
                    'status' => 'present',
                    'method' => 'manual',
                    'attended_at' => $winnerAttendedAt,
                    'verified_by' => $winner->id,
                ]);
            });
        });

        $this->actingAs($staff)->post($this->markUrl('clerk', $announcement), [
            'resident_id' => $resident->id,
            'status' => 'present',
            'method' => 'manual',
        ])->assertRedirect()
            ->assertSessionHas('attendance_outcome', 'already_present')
            ->assertSessionHas('warning', 'Already present. This attendee has already been scanned.');

        $this->assertTrue($insertedByRace);
        $this->assertDatabaseCount('announcement_attendances', 1);
        $this->assertDatabaseHas('announcement_attendances', [
            'announcement_id' => $announcement->id,
            'resident_id' => $resident->id,
            'status' => 'present',
            'method' => 'manual',
            'verified_by' => $winner->id,
        ]);
    }
}
