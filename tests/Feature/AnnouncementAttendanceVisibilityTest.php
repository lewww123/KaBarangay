<?php

namespace Tests\Feature;

use App\Models\Barangay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class AnnouncementAttendanceVisibilityTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
    }

    public function test_staff_announcement_payload_reports_attendance_availability_consistently(): void
    {
        $portals = [
            'clerk' => [
                'role' => 'clerk',
                'audience' => 'all',
                'index' => 'Clerk/Announcements/Index',
                'show' => 'Clerk/Announcements/Show',
            ],
            'pwd' => [
                'role' => 'pwd_staff',
                'audience' => 'pwd',
                'index' => 'Pwd/Announcements/Index',
                'show' => 'Pwd/Announcements/Show',
            ],
            'senior' => [
                'role' => 'senior_staff',
                'audience' => 'senior_citizens',
                'index' => 'Senior/Announcements/Index',
                'show' => 'Senior/Announcements/Show',
            ],
        ];

        $cases = [
            ['approval_status' => 'approved', 'category' => 'Event', 'requires_attendance' => true, 'available' => true],
            ['approval_status' => 'approved', 'category' => 'Event', 'requires_attendance' => false, 'available' => false],
            ['approval_status' => 'pending', 'category' => 'Event', 'requires_attendance' => true, 'available' => false],
            ['approval_status' => 'rejected', 'category' => 'Event', 'requires_attendance' => true, 'available' => false],
            ['approval_status' => 'approved', 'category' => 'General', 'requires_attendance' => false, 'available' => false],
        ];

        foreach ($portals as $portal => $config) {
            $staff = $this->createStaff($this->barangay, $config['role']);

            foreach ($cases as $case) {
                $announcement = $this->createAnnouncement($this->barangay, [
                    'title' => 'Visibility '.$portal.' '.$case['approval_status'].' '.($case['requires_attendance'] ? 'on' : 'off'),
                    'category' => $case['category'],
                    'target_audience' => $config['audience'],
                    'approval_status' => $case['approval_status'],
                    'is_published' => $case['approval_status'] === 'approved',
                    'requires_attendance' => $case['requires_attendance'],
                ]);

                $this->actingAs($staff)
                    ->get('/'.$portal.'/announcements')
                    ->assertOk()
                    ->assertInertia(fn ($page) => $page
                        ->component($config['index'])
                        ->where('announcements', function ($announcements) use ($announcement, $case) {
                            $item = collect($announcements)->firstWhere('id', $announcement->id);

                            return $item !== null
                                && $item['approval_status'] === $case['approval_status']
                                && $item['requires_attendance'] === $case['requires_attendance']
                                && $item['attendance_available'] === $case['available'];
                        }));

                $this->actingAs($staff)
                    ->get('/'.$portal.'/announcements/'.$announcement->id)
                    ->assertOk()
                    ->assertInertia(fn ($page) => $page
                        ->component($config['show'])
                        ->where('announcement.approval_status', $case['approval_status'])
                        ->where('announcement.requires_attendance', $case['requires_attendance'])
                        ->where('announcement.attendance_available', $case['available']));
            }
        }
    }
}
