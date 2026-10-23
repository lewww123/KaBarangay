<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Barangay;
use App\Models\Purok;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class AttendanceRecordingTest extends TestCase
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

    public function test_senior_attendance_status_change_updates_a_single_record(): void
    {
        $staff = $this->createStaff($this->barangay, 'senior_staff');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170001001']);
        $this->assignSector($resident, 'senior');
        $announcement = $this->createAnnouncement($this->barangay, ['target_audience' => 'senior_citizens', 'requires_attendance' => true]);

        $this->actingAs($staff)->post($this->seniorMarkUrl($announcement), [
            'resident_id' => $resident->id,
            'status' => 'present',
        ])->assertRedirect();

        $this->actingAs($staff)->post($this->seniorMarkUrl($announcement), [
            'resident_id' => $resident->id,
            'status' => 'claimed',
        ])->assertRedirect();

        $this->assertDatabaseCount('announcement_attendances', 1);
        $this->assertDatabaseHas('announcement_attendances', [
            'announcement_id' => $announcement->id,
            'resident_id' => $resident->id,
            'status' => 'claimed',
        ]);
    }

    public function test_repeated_identical_submission_creates_one_record(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170001002']);
        $announcement = $this->createAnnouncement($this->barangay, ['requires_attendance' => true]);

        for ($i = 0; $i < 2; $i++) {
            $this->actingAs($clerk)->post($this->clerkMarkUrl($announcement), [
                'resident_id' => $resident->id,
                'status' => 'present',
            ])->assertRedirect();
        }

        $this->assertDatabaseCount('announcement_attendances', 1);
    }

    public function test_attendance_records_the_authenticated_staff_as_verifier(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170001003']);
        $announcement = $this->createAnnouncement($this->barangay, ['requires_attendance' => true]);

        $this->actingAs($clerk)->post($this->clerkMarkUrl($announcement), [
            'resident_id' => $resident->id,
            'status' => 'present',
            'method' => 'manual',
        ])->assertRedirect();

        $this->assertDatabaseHas('announcement_attendances', [
            'announcement_id' => $announcement->id,
            'resident_id' => $resident->id,
            'verified_by' => $clerk->id,
        ]);
    }

    public function test_client_supplied_verifier_is_ignored(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $otherStaff = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170001004']);
        $announcement = $this->createAnnouncement($this->barangay, ['requires_attendance' => true]);

        $this->actingAs($clerk)->post($this->clerkMarkUrl($announcement), [
            'resident_id' => $resident->id,
            'status' => 'present',
            'verified_by' => $otherStaff->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('announcement_attendances', [
            'announcement_id' => $announcement->id,
            'resident_id' => $resident->id,
            'verified_by' => $clerk->id,
        ]);

        $this->assertDatabaseMissing('announcement_attendances', [
            'announcement_id' => $announcement->id,
            'resident_id' => $resident->id,
            'verified_by' => $otherStaff->id,
        ]);
    }

    public function test_pwd_attendance_sheet_lists_only_pwd_members(): void
    {
        $staff = $this->createStaff($this->barangay, 'pwd_staff');
        $eligible = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170001004']);
        $ineligible = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170001005']);
        $this->assignSector($eligible, 'pwd');
        $announcement = $this->createAnnouncement($this->barangay, ['target_audience' => 'pwd', 'requires_attendance' => true]);

        $this->actingAs($staff)
            ->get('/pwd/announcements/'.$announcement->id.'/attendance')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Pwd/Announcements/Attendance')
                ->has('residents', 1)
                ->where('residents.0.id', $eligible->id));
    }

    public function test_pwd_attendance_rejects_ineligible_resident(): void
    {
        $staff = $this->createStaff($this->barangay, 'pwd_staff');
        $ineligible = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170001006']);
        $announcement = $this->createAnnouncement($this->barangay, ['target_audience' => 'pwd', 'requires_attendance' => true]);

        $this->actingAs($staff)->post($this->pwdMarkUrl($announcement), [
            'resident_id' => $ineligible->id,
            'status' => 'present',
        ])->assertStatus(403);

        $this->assertDatabaseCount('announcement_attendances', 0);
    }

    public function test_senior_attendance_sheet_lists_only_senior_members(): void
    {
        $staff = $this->createStaff($this->barangay, 'senior_staff');
        $eligible = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170001006']);
        $ineligible = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170001007']);
        $this->assignSector($eligible, 'senior');
        $announcement = $this->createAnnouncement($this->barangay, ['target_audience' => 'senior_citizens', 'requires_attendance' => true]);

        $this->actingAs($staff)
            ->get('/senior/announcements/'.$announcement->id.'/attendance')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Senior/Announcements/Attendance')
                ->has('residents', 1)
                ->where('residents.0.id', $eligible->id));
    }

    public function test_clerk_attendance_sheet_opens_for_approved_attendance_required_announcement(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $announcement = $this->createAnnouncement($this->barangay, ['requires_attendance' => true]);

        $this->actingAs($clerk)
            ->get('/clerk/announcements/'.$announcement->id.'/attendance')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Clerk/Announcements/Attendance'));
    }

    public function test_senior_attendance_rejects_ineligible_resident(): void
    {
        $staff = $this->createStaff($this->barangay, 'senior_staff');
        $ineligible = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170001006']);
        $announcement = $this->createAnnouncement($this->barangay, ['target_audience' => 'senior_citizens', 'requires_attendance' => true]);

        $this->actingAs($staff)->post($this->seniorMarkUrl($announcement), [
            'resident_id' => $ineligible->id,
            'status' => 'present',
        ])->assertStatus(403);
    }

    public function test_general_audience_event_allows_any_active_resident(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170001006']);
        $announcement = $this->createAnnouncement($this->barangay, ['target_audience' => 'all', 'requires_attendance' => true]);

        $this->actingAs($clerk)->post($this->clerkMarkUrl($announcement), [
            'resident_id' => $resident->id,
            'status' => 'present',
        ])->assertRedirect();

        $this->assertDatabaseHas('announcement_attendances', [
            'announcement_id' => $announcement->id,
            'resident_id' => $resident->id,
        ]);
    }

    public function test_unsupported_attendance_method_is_rejected(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170001007']);
        $announcement = $this->createAnnouncement($this->barangay, ['requires_attendance' => true]);

        $this->actingAs($clerk)->post($this->clerkMarkUrl($announcement), [
            'resident_id' => $resident->id,
            'status' => 'present',
            'method' => 'telepathy',
        ])->assertSessionHasErrors('method');

        $this->assertDatabaseCount('announcement_attendances', 0);
    }

    public function test_qr_scanner_method_without_proof_is_rejected(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170001008']);
        $announcement = $this->createAnnouncement($this->barangay, ['requires_attendance' => true]);

        $this->actingAs($clerk)->post($this->clerkMarkUrl($announcement), [
            'resident_id' => $resident->id,
            'status' => 'claimed',
            'method' => 'qr_scanner',
        ])->assertSessionHasErrors('qr_proof');

        $this->assertDatabaseCount('announcement_attendances', 0);
    }

    public function test_attendance_rejects_resident_from_another_barangay(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $otherPurok = $this->createPurok($this->other, 'Purok 2');
        $foreignResident = $this->createResident($this->other, $otherPurok, ['mobile' => '09170002001']);
        $announcement = $this->createAnnouncement($this->barangay, ['requires_attendance' => true]);

        $this->actingAs($clerk)->post($this->clerkMarkUrl($announcement), [
            'resident_id' => $foreignResident->id,
            'status' => 'present',
        ])->assertStatus(404);
    }

    /*
    |--------------------------------------------------------------------------
    | Approval / attendance-precondition enforcement
    |--------------------------------------------------------------------------
    */

    public function test_attendance_cannot_be_recorded_for_pending_announcement(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170003001']);
        $announcement = $this->createAnnouncement($this->barangay, [
            'approval_status' => 'pending',
            'is_published' => false,
            'requires_attendance' => true,
        ]);

        $this->actingAs($clerk)->get('/clerk/announcements/'.$announcement->id.'/attendance')->assertStatus(403);
        $this->actingAs($clerk)->post($this->clerkMarkUrl($announcement), [
            'resident_id' => $resident->id,
            'status' => 'present',
        ])->assertStatus(403);

        $this->assertDatabaseCount('announcement_attendances', 0);
    }

    public function test_attendance_cannot_be_recorded_for_rejected_announcement(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170003002']);
        $announcement = $this->createAnnouncement($this->barangay, [
            'approval_status' => 'rejected',
            'is_published' => false,
            'requires_attendance' => true,
        ]);

        $this->actingAs($clerk)->get('/clerk/announcements/'.$announcement->id.'/attendance')->assertStatus(403);
        $this->actingAs($clerk)->post($this->clerkMarkUrl($announcement), [
            'resident_id' => $resident->id,
            'status' => 'present',
        ])->assertStatus(403);

        $this->assertDatabaseCount('announcement_attendances', 0);
    }

    public function test_attendance_cannot_be_recorded_when_not_required(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170003003']);
        $announcement = $this->createAnnouncement($this->barangay, [
            'approval_status' => 'approved',
            'is_published' => true,
            'requires_attendance' => false,
        ]);

        $this->actingAs($clerk)->get('/clerk/announcements/'.$announcement->id.'/attendance')->assertStatus(403);
        $this->actingAs($clerk)->post($this->clerkMarkUrl($announcement), [
            'resident_id' => $resident->id,
            'status' => 'present',
        ])->assertStatus(403);

        $this->assertDatabaseCount('announcement_attendances', 0);
    }

    public function test_senior_attendance_sheet_denied_for_pending_rejected_or_non_attendance(): void
    {
        $staff = $this->createStaff($this->barangay, 'senior_staff');

        $pending = $this->createAnnouncement($this->barangay, [
            'target_audience' => 'senior_citizens',
            'approval_status' => 'pending',
            'is_published' => false,
            'requires_attendance' => true,
        ]);
        $this->actingAs($staff)->get('/senior/announcements/'.$pending->id.'/attendance')->assertStatus(403);

        $rejected = $this->createAnnouncement($this->barangay, [
            'target_audience' => 'senior_citizens',
            'approval_status' => 'rejected',
            'is_published' => false,
            'requires_attendance' => true,
        ]);
        $this->actingAs($staff)->get('/senior/announcements/'.$rejected->id.'/attendance')->assertStatus(403);

        $noAttendance = $this->createAnnouncement($this->barangay, [
            'target_audience' => 'senior_citizens',
            'approval_status' => 'approved',
            'is_published' => true,
            'requires_attendance' => false,
        ]);
        $this->actingAs($staff)->get('/senior/announcements/'.$noAttendance->id.'/attendance')->assertStatus(403);
    }

    public function test_pwd_attendance_sheet_denied_for_pending_rejected_or_non_attendance(): void
    {
        $staff = $this->createStaff($this->barangay, 'pwd_staff');

        $pending = $this->createAnnouncement($this->barangay, [
            'target_audience' => 'pwd',
            'approval_status' => 'pending',
            'is_published' => false,
            'requires_attendance' => true,
        ]);
        $this->actingAs($staff)->get('/pwd/announcements/'.$pending->id.'/attendance')->assertStatus(403);

        $rejected = $this->createAnnouncement($this->barangay, [
            'target_audience' => 'pwd',
            'approval_status' => 'rejected',
            'is_published' => false,
            'requires_attendance' => true,
        ]);
        $this->actingAs($staff)->get('/pwd/announcements/'.$rejected->id.'/attendance')->assertStatus(403);

        $noAttendance = $this->createAnnouncement($this->barangay, [
            'target_audience' => 'pwd',
            'approval_status' => 'approved',
            'is_published' => true,
            'requires_attendance' => false,
        ]);
        $this->actingAs($staff)->get('/pwd/announcements/'.$noAttendance->id.'/attendance')->assertStatus(403);
    }

    public function test_attendance_rejects_inactive_resident(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170003004']);
        $resident->update(['status' => 'inactive']);
        $announcement = $this->createAnnouncement($this->barangay, ['requires_attendance' => true]);

        $this->actingAs($clerk)->post($this->clerkMarkUrl($announcement), [
            'resident_id' => $resident->id,
            'status' => 'present',
        ])->assertStatus(403);

        $this->assertDatabaseCount('announcement_attendances', 0);
    }
}
