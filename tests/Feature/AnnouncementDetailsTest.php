<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Purok;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class AnnouncementDetailsTest extends TestCase
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

    public function test_clerk_can_open_an_event_details_page(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $announcement = $this->createAnnouncement($this->barangay, [
            'title' => 'Barangay Assembly',
            'category' => 'Event',
            'event_start_at' => '2026-11-15 08:30:00',
            'location' => 'Barangay Hall',
        ]);

        $this->actingAs($clerk)
            ->get('/clerk/announcements/'.$announcement->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Clerk/Announcements/Show')
                ->where('announcement.id', $announcement->id)
                ->where('announcement.type', 'event')
                ->where('announcement.location', 'Barangay Hall'));
    }

    public function test_clerk_list_exposes_the_server_derived_announcement_type(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->createAnnouncement($this->barangay, [
            'title' => 'Community Assembly',
            'category' => 'Event',
        ]);
        $this->createAnnouncement($this->barangay, [
            'title' => 'Water Advisory',
            'category' => 'General',
            'requires_attendance' => false,
        ]);

        $this->actingAs($clerk)
            ->get('/clerk/announcements')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('announcements', function ($announcements) {
                    $announcements = collect($announcements);

                    return $announcements->firstWhere('title', 'Community Assembly')['type'] === 'event'
                        && $announcements->firstWhere('title', 'Water Advisory')['type'] === 'informational';
                }));
    }

    public function test_clerk_cannot_open_another_barangays_announcement(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $foreign = $this->createAnnouncement($this->other, [
            'title' => 'Foreign Announcement',
        ]);

        $this->actingAs($clerk)
            ->get('/clerk/announcements/'.$foreign->id)
            ->assertStatus(404);
    }

    public function test_legacy_attendance_announcement_is_presented_as_an_event(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $announcement = $this->createAnnouncement($this->barangay, [
            'category' => 'Relief Goods',
            'requires_attendance' => true,
            'event_start_at' => '2026-12-01 09:00:00',
            'location' => 'Covered Court',
        ]);

        $this->actingAs($clerk)
            ->get('/clerk/announcements/'.$announcement->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('announcement.type', 'event')
                ->where('announcement.category', 'Relief Goods'));
    }

    public function test_informational_announcement_details_have_no_event_type(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $announcement = $this->createAnnouncement($this->barangay, [
            'title' => 'Water Service Advisory',
            'category' => 'General',
            'requires_attendance' => false,
            'event_start_at' => null,
            'location' => null,
        ]);

        $this->actingAs($clerk)
            ->get('/clerk/announcements/'.$announcement->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('announcement.type', 'informational')
                ->where('announcement.event_start_at', null));
    }

    public function test_senior_staff_can_only_open_senior_announcements(): void
    {
        $staff = $this->createStaff($this->barangay, 'senior_staff');
        $senior = $this->createAnnouncement($this->barangay, [
            'target_audience' => 'senior_citizens',
        ]);
        $pwd = $this->createAnnouncement($this->barangay, [
            'target_audience' => 'pwd',
        ]);

        $this->actingAs($staff)
            ->get('/senior/announcements/'.$senior->id)
            ->assertOk();

        $this->actingAs($staff)
            ->get('/senior/announcements/'.$pwd->id)
            ->assertForbidden();
    }

    public function test_pwd_staff_can_only_open_pwd_announcements(): void
    {
        $staff = $this->createStaff($this->barangay, 'pwd_staff');
        $pwd = $this->createAnnouncement($this->barangay, [
            'target_audience' => 'pwd',
        ]);
        $senior = $this->createAnnouncement($this->barangay, [
            'target_audience' => 'senior_citizens',
        ]);

        $this->actingAs($staff)
            ->get('/pwd/announcements/'.$pwd->id)
            ->assertOk();

        $this->actingAs($staff)
            ->get('/pwd/announcements/'.$senior->id)
            ->assertForbidden();
    }

    public function test_resident_can_open_a_published_announcement_for_their_audience(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok);
        $this->assignSector($resident, 'senior');
        $announcement = $this->createAnnouncement($this->barangay, [
            'target_audience' => 'senior_citizens',
            'category' => 'Event',
            'event_start_at' => '2026-11-15 08:30:00',
            'location' => 'Barangay Hall',
        ]);

        $this->withSession(['resident_id' => $resident->id])
            ->get('/resident/announcements/'.$announcement->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Resident/AnnouncementDetails')
                ->where('announcement.id', $announcement->id)
                ->where('announcement.type', 'event'));
    }

    public function test_resident_cannot_open_an_unpublished_or_out_of_audience_announcement(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok);

        $pending = $this->createAnnouncement($this->barangay, [
            'approval_status' => 'pending',
            'is_published' => false,
        ]);
        $unpublished = $this->createAnnouncement($this->barangay, [
            'approval_status' => 'approved',
            'is_published' => false,
        ]);
        $notTheirAudience = $this->createAnnouncement($this->barangay, [
            'target_audience' => 'pwd',
        ]);

        foreach ([$pending, $unpublished, $notTheirAudience] as $announcement) {
            $this->withSession(['resident_id' => $resident->id])
                ->get('/resident/announcements/'.$announcement->id)
                ->assertStatus(404);
        }
    }

    public function test_resident_cannot_open_another_barangays_announcement(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok);
        $foreign = $this->createAnnouncement($this->other, [
            'target_audience' => 'all',
        ]);

        $this->withSession(['resident_id' => $resident->id])
            ->get('/resident/announcements/'.$foreign->id)
            ->assertStatus(404);
    }
}
