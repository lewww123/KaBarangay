<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Purok;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class P02PagesRenderTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Purok $purok;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $this->purok = $this->createPurok($this->barangay, 'Purok 1');
    }

    public function test_clerk_announcements_index_renders(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->get('/clerk/announcements')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Clerk/Announcements/Index'));
    }

    public function test_clerk_attendance_sheet_renders(): void
    {
        $announcement = $this->createAnnouncement($this->barangay, ['requires_attendance' => true]);
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->get('/clerk/announcements/'.$announcement->id.'/attendance')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Clerk/Announcements/Attendance'));
    }

    public function test_pwd_announcements_index_renders(): void
    {
        $pwd = $this->createStaff($this->barangay, 'pwd_staff');

        $this->actingAs($pwd)
            ->get('/pwd/announcements')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Pwd/Announcements/Index'));
    }

    public function test_pwd_attendance_sheet_renders(): void
    {
        $announcement = $this->createAnnouncement($this->barangay, ['target_audience' => 'pwd', 'requires_attendance' => true]);
        $pwd = $this->createStaff($this->barangay, 'pwd_staff');

        $this->actingAs($pwd)
            ->get('/pwd/announcements/'.$announcement->id.'/attendance')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Pwd/Announcements/Attendance'));
    }

    public function test_resident_chatbot_page_renders(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170000051']);

        $this->withSession(['resident_id' => $resident->id])
            ->get('/resident/chatbot')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Resident/Chatbot'));
    }

    public function test_resident_citizen_charter_page_renders(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170000052']);

        $this->withSession(['resident_id' => $resident->id])
            ->get('/resident/citizen-charter')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Resident/CitizenCharter'));
    }
}
