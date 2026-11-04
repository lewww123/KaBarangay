<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\AnnouncementAttendance;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class OfflineAttendanceTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private User $staff;

    private Resident $resident;

    private Announcement $event;

    public static function portalMethods(): array
    {
        return [
            ['clerk', 'all', null, 'manual'], ['clerk', 'all', null, 'qr_scanner'],
            ['pwd', 'pwd', 'pwd', 'manual'], ['pwd', 'pwd', 'pwd', 'qr_scanner'],
            ['senior', 'senior_citizens', 'senior', 'manual'], ['senior', 'senior_citizens', 'senior', 'qr_scanner'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $barangay = $this->createBarangay('One', 'ONE');
        $this->staff = $this->createStaff($barangay, 'clerk');
        $this->resident = $this->createResident($barangay, $this->createPurok($barangay));
        $this->resident->qrCredential()->create(['token' => Str::random(64), 'is_active' => true]);
        $this->event = $this->createAnnouncement($barangay, ['requires_attendance' => true, 'target_audience' => 'all']);
        $this->actingAs($this->staff);
    }

    private function url(string $action, string $department = 'clerk'): string
    {
        return '/attendance/offline/'.$department.'/'.$this->event->id.'/'.$action;
    }

    private function payload(string $method = 'manual'): array
    {
        $context = $this->getJson($this->url('context'))->assertOk()->json();

        return [
            'client_request_id' => (string) Str::uuid(), 'offline_owner' => $context['owner'],
            'grant' => $context['grant'], 'resident_id' => $this->resident->id, 'status' => 'present',
            'method' => $method, 'qr_token' => $method === 'qr_scanner' ? $this->resident->qrCredential->token : null,
            'captured_at' => now()->toIso8601String(),
        ];
    }

    #[DataProvider('portalMethods')]
    public function test_manual_and_qr_offline_sync_work_for_each_staff_portal(string $department, string $audience, ?string $sector, string $method): void
    {
        $staff = $this->createStaff($this->staff->barangay, $department);
        if ($sector !== null) {
            $this->assignSector($this->resident, $sector);
        }
        $this->event->update(['target_audience' => $audience, 'category' => 'Event', 'program_status' => 'ongoing']);
        $this->actingAs($staff);
        $context = $this->getJson($this->url('context', $department))->assertOk()->json();
        $payload = [
            'client_request_id' => (string) Str::uuid(), 'offline_owner' => $context['owner'], 'grant' => $context['grant'],
            'resident_id' => $this->resident->id, 'status' => 'present', 'method' => $method,
            'qr_token' => $method === 'qr_scanner' ? $this->resident->qrCredential->token : null, 'captured_at' => now()->toIso8601String(),
        ];
        $this->postJson($this->url('sync', $department), $payload)->assertCreated()->assertJsonPath('result.outcome', 'recorded');
        $this->assertDatabaseHas('announcement_attendances', ['resident_id' => $this->resident->id, 'verified_by' => $staff->id, 'method' => $method]);
    }

    public function test_manual_offline_sync_is_idempotent_and_keeps_the_original_attendance(): void
    {
        $payload = $this->payload();
        $this->postJson($this->url('sync'), $payload)->assertCreated()->assertJsonPath('result.outcome', 'recorded');
        $this->postJson($this->url('sync'), $payload)->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('announcement_attendances', 1);
        $this->assertDatabaseCount('offline_attendance_submissions', 1);
        $this->postJson($this->url('sync'), [...$payload, 'status' => 'claimed'])->assertConflict();
        $this->assertSame('present', AnnouncementAttendance::sole()->status);
    }

    public function test_qr_is_verified_again_on_sync_and_revoked_qr_is_rejected(): void
    {
        $payload = $this->payload('qr_scanner');
        $this->resident->qrCredential()->update(['revoked_at' => now(), 'is_active' => false]);
        $this->postJson($this->url('sync'), $payload)->assertUnprocessable()->assertJsonValidationErrors('qr_token');
        $this->assertDatabaseCount('announcement_attendances', 0);
    }

    public function test_valid_qr_sync_records_the_scanner_method_and_server_verification_time(): void
    {
        $payload = $this->payload('qr_scanner');
        $this->travel(10)->minutes();
        $this->postJson($this->url('sync'), $payload)->assertCreated()->assertJsonPath('result.outcome', 'recorded');
        $attendance = AnnouncementAttendance::sole();
        $this->assertSame('qr_scanner', $attendance->method);
        $this->assertSame($this->staff->id, $attendance->verified_by);
        $this->assertSame(now()->timestamp, $attendance->attended_at->timestamp);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'attendance.offline.synced')->count());
    }

    public function test_prepared_roster_has_qr_hashes_and_never_raw_qr_tokens(): void
    {
        $token = $this->resident->qrCredential->token;
        $response = $this->getJson($this->url('context'))->assertOk();
        $this->assertStringNotContainsString($token, $response->getContent());
        $this->assertContains(hash('sha256', $token), array_column($response->json('residents'), 'qrHash'));
    }

    public function test_other_staff_cannot_sync_someone_elses_prepared_entries(): void
    {
        $payload = $this->payload();
        $other = $this->createStaff($this->staff->barangay, 'clerk');
        $this->actingAs($other)->postJson($this->url('sync'), $payload)->assertForbidden();
        $this->assertDatabaseCount('announcement_attendances', 0);
    }

    public function test_current_role_account_resident_and_event_rules_are_rechecked(): void
    {
        $payload = $this->payload();
        $this->resident->update(['status' => 'inactive']);
        $this->postJson($this->url('sync'), $payload)->assertForbidden();
        $this->resident->update(['status' => 'active']);
        $this->event->update(['approval_status' => 'rejected']);
        $this->postJson($this->url('sync'), $payload)->assertForbidden();
        $this->event->update(['approval_status' => 'approved']);
        $this->staff->staffAssignments()->update(['is_active' => false]);
        $this->postJson($this->url('sync'), $payload)->assertForbidden();
        $this->assertDatabaseCount('announcement_attendances', 0);
    }

    public function test_expired_or_tampered_grants_and_foreign_residents_are_rejected(): void
    {
        $payload = $this->payload();
        $this->postJson($this->url('sync'), [...$payload, 'grant' => 'tampered'])->assertUnprocessable();
        $other = $this->createBarangay('Two', 'TWO');
        $resident = $this->createResident($other, $this->createPurok($other));
        $this->postJson($this->url('sync'), [...$payload, 'resident_id' => $resident->id])->assertNotFound();
        $this->travel(25)->hours();
        $this->postJson($this->url('sync'), $payload)->assertUnprocessable();
        $this->assertDatabaseCount('announcement_attendances', 0);
    }

    public function test_online_and_offline_duplicates_never_overwrite_the_original_record(): void
    {
        $payload = $this->payload();
        $this->post('/clerk/announcements/'.$this->event->id.'/attendance/mark', ['resident_id' => $this->resident->id, 'status' => 'present', 'method' => 'manual'])->assertRedirect();
        $this->postJson($this->url('sync'), $payload)->assertCreated()->assertJsonPath('result.outcome', 'already_present');
        $this->postJson($this->url('sync'), [...$payload, 'client_request_id' => (string) Str::uuid(), 'status' => 'claimed'])->assertCreated()->assertJsonPath('result.outcome', 'existing_record_conflict');
        $this->assertDatabaseCount('announcement_attendances', 1);
        $this->assertSame('present', AnnouncementAttendance::sole()->status);
    }

    public function test_sector_roles_and_foreign_barangay_contexts_cannot_prepare_an_unrelated_sheet(): void
    {
        $senior = $this->createStaff($this->staff->barangay, 'senior');
        $this->actingAs($senior)->getJson($this->url('context', 'senior'))->assertForbidden();
        $other = $this->createStaff($this->createBarangay('Two', 'TWO'), 'clerk');
        $this->actingAs($other)->getJson($this->url('context'))->assertNotFound();
    }
}
