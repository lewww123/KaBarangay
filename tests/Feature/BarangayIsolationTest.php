<?php

namespace Tests\Feature;

use App\Models\Barangay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class BarangayIsolationTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Barangay $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $this->other = $this->createBarangay('Barangay Two', 'BRGY-TWO');
    }

    public function test_clerk_dashboard_counts_are_scoped_to_own_barangay(): void
    {
        $purok = $this->createPurok($this->barangay, 'Purok 1');
        $otherPurok = $this->createPurok($this->other, 'Purok 2');

        $this->createResident($this->barangay, $purok, ['resident_number' => 'A-1', 'mobile' => '09171110001']);
        $this->createResident($this->barangay, $purok, ['resident_number' => 'A-2', 'mobile' => '09171110002']);
        $this->createResident($this->other, $otherPurok, ['resident_number' => 'B-1', 'mobile' => '09172220001']);

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->get('/clerk/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Clerk/Dashboard')
                ->where('stats.totalResidents', 3));
    }

    public function test_senior_dashboard_counts_are_scoped_to_own_barangay(): void
    {
        $purok = $this->createPurok($this->barangay, 'Purok 1');
        $otherPurok = $this->createPurok($this->other, 'Purok 2');

        $seniorResident = $this->createResident($this->barangay, $purok, ['resident_number' => 'A-1', 'mobile' => '09171110001']);
        $this->createResident($this->other, $otherPurok, ['resident_number' => 'B-1', 'mobile' => '09172220001']);
        $this->createResident($this->other, $otherPurok, ['resident_number' => 'B-2', 'mobile' => '09172220002']);
        $this->assignSector($seniorResident, 'senior');

        $senior = $this->createAssignedStaff(['barangay' => $this->barangay, 'purok' => $purok], 'senior', 'Senior Staff');

        $this->actingAs($senior)
            ->get('/senior/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Senior/Dashboard')
                ->where('stats.totalResidents', 1));
    }

    public function test_pwd_dashboard_counts_are_scoped_to_own_barangay(): void
    {
        $purok = $this->createPurok($this->barangay, 'Purok 1');
        $otherPurok = $this->createPurok($this->other, 'Purok 2');

        $this->createResident($this->barangay, $purok, ['resident_number' => 'A-1', 'mobile' => '09171110001']);
        $this->createResident($this->other, $otherPurok, ['resident_number' => 'B-1', 'mobile' => '09172220001']);

        $pwd = $this->createAssignedStaff(['barangay' => $this->barangay, 'purok' => $purok], 'pwd', 'PWD Staff');

        $this->actingAs($pwd)
            ->get('/pwd/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Pwd/Dashboard')
                ->where('stats.totalResidents', 0));
    }

    public function test_senior_dashboard_announcements_are_scoped_to_own_barangay(): void
    {
        $ownPending = $this->createAnnouncement($this->barangay, [
            'title' => 'Own Senior Pending',
            'target_audience' => 'senior_citizens',
            'approval_status' => 'pending',
            'is_published' => false,
        ]);

        $this->travel(1)->second();

        $ownApproved = $this->createAnnouncement($this->barangay, [
            'title' => 'Own Senior Approved',
            'target_audience' => 'senior_citizens',
            'approval_status' => 'approved',
        ]);

        // Foreign barangay data, created later so it is the "newest" row.
        $this->createAnnouncement($this->other, [
            'title' => 'Foreign Senior Pending',
            'target_audience' => 'senior_citizens',
            'approval_status' => 'pending',
            'is_published' => false,
        ]);
        $this->createAnnouncement($this->other, [
            'title' => 'Foreign Senior Approved',
            'target_audience' => 'senior_citizens',
            'approval_status' => 'approved',
        ]);

        // Foreign announcements aimed at another sector must never surface here.
        $this->createAnnouncement($this->other, [
            'title' => 'Foreign PWD Announcement',
            'target_audience' => 'pwd',
            'approval_status' => 'approved',
        ]);

        $purok = $this->createPurok($this->barangay, 'Senior Purok');
        $senior = $this->createAssignedStaff(['barangay' => $this->barangay, 'purok' => $purok], 'senior', 'Senior Staff');

        $this->actingAs($senior)
            ->get('/senior/dashboard')
            ->assertOk()
            ->assertInertia(function ($page) use ($ownPending, $ownApproved) {
                $props = $page->toArray()['props'];

                $this->assertSame(2, $props['stats']['totalAnnouncements']);
                $this->assertSame(1, $props['stats']['pendingAnnouncements']);
                $this->assertSame(1, $props['stats']['approvedAnnouncements']);

                $recent = collect($props['recentAnnouncements']);

                $this->assertCount(2, $recent);
                $this->assertSame(
                    [$ownApproved->id, $ownPending->id],
                    $recent->pluck('id')->all()
                );
            });
    }

    public function test_senior_dashboard_announcements_are_empty_for_a_barangay_without_any(): void
    {
        $this->createAnnouncement($this->other, [
            'title' => 'Foreign Senior Announcement',
            'target_audience' => 'senior_citizens',
            'approval_status' => 'approved',
        ]);

        $purok = $this->createPurok($this->barangay, 'Senior Purok');
        $senior = $this->createAssignedStaff(['barangay' => $this->barangay, 'purok' => $purok], 'senior', 'Senior Staff');

        $this->actingAs($senior)
            ->get('/senior/dashboard')
            ->assertOk()
            ->assertInertia(function ($page) {
                $props = $page->toArray()['props'];

                $this->assertSame(0, $props['stats']['totalAnnouncements']);
                $this->assertSame(0, $props['stats']['pendingAnnouncements']);
                $this->assertSame(0, $props['stats']['approvedAnnouncements']);
                $this->assertSame([], $props['recentAnnouncements']);
            });
    }

    public function test_pwd_dashboard_announcements_are_scoped_to_own_barangay(): void
    {
        $ownApproved = $this->createAnnouncement($this->barangay, [
            'title' => 'Own PWD Approved',
            'target_audience' => 'pwd',
            'approval_status' => 'approved',
        ]);

        // Foreign barangay data, created later so it is the "newest" row.
        $this->createAnnouncement($this->other, [
            'title' => 'Foreign PWD Pending',
            'target_audience' => 'pwd',
            'approval_status' => 'pending',
            'is_published' => false,
        ]);

        // Foreign senior announcements must never surface on the PWD portal.
        $this->createAnnouncement($this->other, [
            'title' => 'Foreign Senior Announcement',
            'target_audience' => 'senior_citizens',
            'approval_status' => 'approved',
        ]);

        $purok = $this->createPurok($this->barangay, 'PWD Purok');
        $pwd = $this->createAssignedStaff(['barangay' => $this->barangay, 'purok' => $purok], 'pwd', 'PWD Staff');

        $this->actingAs($pwd)
            ->get('/pwd/dashboard')
            ->assertOk()
            ->assertInertia(function ($page) use ($ownApproved) {
                $props = $page->toArray()['props'];

                $this->assertSame(1, $props['stats']['totalAnnouncements']);
                $this->assertSame(0, $props['stats']['pendingAnnouncements']);
                $this->assertSame(1, $props['stats']['approvedAnnouncements']);

                $recent = collect($props['recentAnnouncements']);

                $this->assertCount(1, $recent);
                $this->assertSame($ownApproved->id, $recent->first()['id']);
            });
    }

    public function test_pwd_dashboard_announcements_are_empty_for_a_barangay_without_any(): void
    {
        $this->createAnnouncement($this->other, [
            'title' => 'Foreign PWD Announcement',
            'target_audience' => 'pwd',
            'approval_status' => 'approved',
        ]);

        $purok = $this->createPurok($this->barangay, 'PWD Purok');
        $pwd = $this->createAssignedStaff(['barangay' => $this->barangay, 'purok' => $purok], 'pwd', 'PWD Staff');

        $this->actingAs($pwd)
            ->get('/pwd/dashboard')
            ->assertOk()
            ->assertInertia(function ($page) {
                $props = $page->toArray()['props'];

                $this->assertSame(0, $props['stats']['totalAnnouncements']);
                $this->assertSame(0, $props['stats']['pendingAnnouncements']);
                $this->assertSame(0, $props['stats']['approvedAnnouncements']);
                $this->assertSame([], $props['recentAnnouncements']);
            });
    }

    public function test_resident_announcement_feed_is_scoped_to_own_barangay(): void
    {
        $purok = $this->createPurok($this->barangay, 'Purok 1');
        $otherPurok = $this->createPurok($this->other, 'Purok 2');

        $resident = $this->createResident($this->barangay, $purok, ['mobile' => '09173330001']);

        $ownAnnouncement = $this->createAnnouncement($this->barangay, ['title' => 'Own Barangay']);
        $this->createAnnouncement($this->other, ['title' => 'Foreign Barangay']);

        $this->withSession(['resident_id' => $resident->id])
            ->get('/resident/announcements')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Resident/Announcements')
                ->has('announcements.data', 1)
                ->where('announcements.data.0.id', $ownAnnouncement->id));
    }

    public function test_resident_dashboard_latest_announcement_is_scoped_to_own_barangay(): void
    {
        $purok = $this->createPurok($this->barangay, 'Purok 1');
        $resident = $this->createResident($this->barangay, $purok, ['mobile' => '09173330002']);

        $ownAnnouncement = $this->createAnnouncement($this->barangay, ['title' => 'Own Barangay']);

        $this->travel(2)->minutes();
        $this->createAnnouncement($this->other, ['title' => 'Foreign Barangay Newer']);

        $this->withSession(['resident_id' => $resident->id])
            ->get('/resident')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Resident/Dashboard')
                ->where('latestAnnouncement.id', $ownAnnouncement->id));
    }

    public function test_qr_lookup_is_scoped_to_staff_barangay(): void
    {
        $purok = $this->createPurok($this->barangay, 'Purok 1');
        $otherPurok = $this->createPurok($this->other, 'Purok 2');

        $this->createResident($this->barangay, $purok, ['resident_number' => 'AAA-0001', 'mobile' => '09174440001']);
        $this->createResident($this->other, $otherPurok, ['resident_number' => 'BBB-0001', 'mobile' => '09175550001']);

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->getJson('/api/qr/verify-token?token=AAA-0001')
            ->assertOk()
            ->assertJsonPath('resident.resident_number', 'AAA-0001');

        $this->actingAs($clerk)
            ->getJson('/api/qr/verify-token?token=BBB-0001')
            ->assertStatus(404);
    }

    public function test_cross_barangay_registration_cannot_be_approved(): void
    {
        $otherPurok = $this->createPurok($this->other, 'Purok 2');
        $registration = $this->createPendingRegistration($this->other, $otherPurok);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post('/admin/residents/'.$registration->id.'/approve')
            ->assertStatus(404);

        $this->assertDatabaseMissing('residents', [
            'resident_registration_id' => $registration->id,
        ]);
    }

    public function test_same_barangay_registration_can_be_approved(): void
    {
        $purok = $this->createPurok($this->barangay, 'Purok 1');
        $registration = $this->createPendingRegistration($this->barangay, $purok, ['email' => 'applicant@example.test']);
        $candidate = $this->createResident($this->barangay, $purok);
        $candidate->account()->delete();
        $candidate->update(['source' => 'admin', 'email' => $registration->email]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post('/admin/residents/'.$registration->id.'/approve', ['resident_id' => $candidate->id])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('resident_registrations', [
            'id' => $registration->id,
            'matched_resident_id' => $candidate->id,
            'status' => 'approved',
            'barangay_id' => $this->barangay->id,
        ]);
    }

    public function test_senior_attendance_rejects_non_senior_announcement(): void
    {
        $purok = $this->createPurok($this->barangay, 'Purok 1');
        $resident = $this->createResident($this->barangay, $purok, ['mobile' => '09176660001']);

        $announcement = $this->createAnnouncement($this->barangay, ['target_audience' => 'all']);

        $senior = $this->createStaff($this->barangay, 'senior_staff');

        $this->actingAs($senior)
            ->post('/senior/announcements/'.$announcement->id.'/attendance/mark', [
                'resident_id' => $resident->id,
            ])
            ->assertStatus(403);
    }

    public function test_pwd_attendance_rejects_non_pwd_announcement(): void
    {
        $purok = $this->createPurok($this->barangay, 'Purok 1');
        $resident = $this->createResident($this->barangay, $purok, ['mobile' => '09176660002']);

        $announcement = $this->createAnnouncement($this->barangay, ['target_audience' => 'all']);

        $pwd = $this->createStaff($this->barangay, 'pwd_staff');

        $this->actingAs($pwd)
            ->post('/pwd/announcements/'.$announcement->id.'/attendance/mark', [
                'resident_id' => $resident->id,
            ])
            ->assertStatus(403);
    }

    public function test_senior_attendance_rejects_announcement_from_other_barangay(): void
    {
        $announcement = $this->createAnnouncement($this->other, ['target_audience' => 'senior_citizens']);

        $senior = $this->createStaff($this->barangay, 'senior_staff');

        $this->actingAs($senior)
            ->post('/senior/announcements/'.$announcement->id.'/attendance/mark', [
                'resident_id' => 1,
            ])
            ->assertStatus(404);
    }
}
