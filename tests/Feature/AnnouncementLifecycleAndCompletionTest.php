<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Barangay;
use App\Models\Purok;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class AnnouncementLifecycleAndCompletionTest extends TestCase
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

    private function revisionToken(Announcement $announcement): string
    {
        return $announcement->fresh()->revisionToken();
    }

    private function completedEvent(array $attributes = []): Announcement
    {
        return $this->createAnnouncement($this->barangay, [
            'title' => 'Completed Event',
            'category' => 'Event',
            'event_start_at' => now()->subHour(),
            'location' => 'Covered Court',
            'requires_attendance' => false,
            'approval_status' => 'approved',
            'is_published' => true,
            'completed_at' => now(),
            ...$attributes,
        ]);
    }

    public function test_lifecycle_tabs_use_server_side_classification_and_counts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-23 10:00:00'));

        try {
            $clerk = $this->createStaff($this->barangay, 'clerk');

            $this->createAnnouncement($this->barangay, [
                'title' => 'Ongoing Attendance Event',
                'category' => 'General',
                'requires_attendance' => true,
                'event_start_at' => now(),
            ]);
            $this->createAnnouncement($this->barangay, [
                'title' => 'Upcoming Event',
                'category' => 'Event',
                'event_start_at' => now()->addHour(),
            ]);
            $this->createAnnouncement($this->barangay, [
                'title' => 'Past Informational',
                'category' => 'General',
                'event_start_at' => now()->subHour(),
            ]);
            $this->createAnnouncement($this->barangay, [
                'title' => 'Approved Without Start',
                'category' => 'General',
            ]);
            $this->createAnnouncement($this->barangay, [
                'title' => 'Pending Event',
                'category' => 'Event',
                'event_start_at' => now()->subHour(),
                'approval_status' => 'pending',
                'is_published' => false,
            ]);
            $this->createAnnouncement($this->barangay, [
                'title' => 'Rejected Event',
                'category' => 'Event',
                'event_start_at' => now()->subHour(),
                'approval_status' => 'rejected',
                'is_published' => false,
            ]);
            $this->completedEvent(['title' => 'Done Event']);

            $this->actingAs($clerk)
                ->get('/clerk/announcements?tab=ongoing')
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component('Clerk/Announcements/Index')
                    ->has('announcements', 1)
                    ->where('announcements.0.title', 'Ongoing Attendance Event')
                    ->where('activeTab', 'ongoing')
                    ->where('tabCounts.all', 7)
                    ->where('tabCounts.ongoing', 1)
                    ->where('tabCounts.pending', 1)
                    ->where('tabCounts.approved', 3)
                    ->where('tabCounts.done', 1));

            $this->actingAs($clerk)
                ->get('/clerk/announcements?tab=approved')
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->has('announcements', 3)
                    ->where('activeTab', 'approved'));

            $this->actingAs($clerk)
                ->get('/clerk/announcements?tab=not-a-tab')
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->has('announcements', 7)
                    ->where('activeTab', 'all'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_pwd_and_senior_tabs_are_scoped_to_audience_and_barangay(): void
    {
        $pwdStaff = $this->createStaff($this->barangay, 'pwd_staff');
        $seniorStaff = $this->createStaff($this->barangay, 'senior_staff');

        $this->createAnnouncement($this->barangay, [
            'title' => 'Pwd Own',
            'target_audience' => 'pwd',
        ]);
        $this->createAnnouncement($this->other, [
            'title' => 'Pwd Other Barangay',
            'target_audience' => 'pwd',
        ]);
        $this->createAnnouncement($this->barangay, [
            'title' => 'Senior Own',
            'target_audience' => 'senior_citizens',
        ]);
        $this->createAnnouncement($this->barangay, [
            'title' => 'All Audience',
            'target_audience' => 'all',
        ]);

        $this->actingAs($pwdStaff)
            ->get('/pwd/announcements')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('announcements', 1)
                ->where('announcements.0.title', 'Pwd Own')
                ->where('activeTab', 'all')
                ->where('tabCounts.all', 1));

        $this->actingAs($seniorStaff)
            ->get('/senior/announcements')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('announcements', 1)
                ->where('announcements.0.title', 'Senior Own')
                ->where('activeTab', 'all')
                ->where('tabCounts.all', 1));
    }

    public function test_importance_defaults_to_normal_and_accepts_supported_levels(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)->post('/clerk/announcements', [
            'title' => 'Default Importance',
            'content' => 'Content',
            'category' => 'General',
            'target_audience' => 'all',
            'requires_attendance' => false,
        ])->assertRedirect();

        $this->assertSame(
            'normal',
            Announcement::query()->where('title', 'Default Importance')->value('importance'),
        );

        $this->actingAs($clerk)->post('/clerk/announcements', [
            'title' => 'Urgent Importance',
            'content' => 'Content',
            'category' => 'General',
            'importance' => 'urgent',
            'target_audience' => 'all',
            'requires_attendance' => false,
        ])->assertRedirect();

        $this->assertSame(
            'urgent',
            Announcement::query()->where('title', 'Urgent Importance')->value('importance'),
        );

        $this->actingAs($clerk)->post('/clerk/announcements', [
            'title' => 'Invalid Importance',
            'content' => 'Content',
            'category' => 'General',
            'importance' => 'critical',
            'target_audience' => 'all',
            'requires_attendance' => false,
        ])->assertSessionHasErrors('importance');

        $this->assertSame(2, Announcement::query()->count());
    }

    public function test_importance_is_preserved_on_update_when_omitted_and_can_be_changed(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $announcement = $this->createAnnouncement($this->barangay, [
            'title' => 'Important Notice',
            'importance' => 'urgent',
        ]);

        $payload = [
            'title' => 'Important Notice',
            'content' => 'Updated content',
            'category' => 'General',
            'target_audience' => 'all',
            'requires_attendance' => false,
        ];

        $this->actingAs($clerk)
            ->put('/clerk/announcements/'.$announcement->id, $payload)
            ->assertRedirect();

        $this->assertSame('urgent', $announcement->fresh()->importance);

        $this->actingAs($clerk)
            ->put('/clerk/announcements/'.$announcement->id, [
                ...$payload,
                'importance' => 'high',
            ])
            ->assertRedirect();

        $this->assertSame('high', $announcement->fresh()->importance);
    }

    public function test_mark_done_completes_approved_event_across_all_staff_desks(): void
    {
        $desks = [
            ['role' => 'clerk', 'portal' => 'clerk', 'audience' => 'all', 'category' => 'Event'],
            ['role' => 'pwd_staff', 'portal' => 'pwd', 'audience' => 'pwd', 'category' => 'General'],
            ['role' => 'senior_staff', 'portal' => 'senior', 'audience' => 'senior_citizens', 'category' => 'Event'],
        ];

        foreach ($desks as $desk) {
            $staff = $this->createStaff($this->barangay, $desk['role']);
            $announcement = $this->createAnnouncement($this->barangay, [
                'title' => 'Event for '.$desk['portal'],
                'category' => $desk['category'],
                'target_audience' => $desk['audience'],
                'requires_attendance' => true,
                'event_start_at' => now()->subHour(),
                'location' => 'Covered Court',
                'approval_status' => 'approved',
                'is_published' => true,
            ]);

            $this->actingAs($staff)
                ->post('/'.$desk['portal'].'/announcements/'.$announcement->id.'/complete')
                ->assertSessionHas('success', 'Announcement marked as done.');

            $fresh = $announcement->fresh();

            $this->assertNotNull($fresh->completed_at);
            $this->assertSame($staff->id, $fresh->completed_by);
            $this->assertSame('approved', $fresh->approval_status);
            $this->assertTrue($fresh->is_published);

            $this->assertDatabaseHas('announcement_approval_logs', [
                'announcement_id' => $announcement->id,
                'actor_id' => $staff->id,
                'action' => 'completed',
                'previous_status' => 'approved',
                'new_status' => 'approved',
            ]);

            $this->assertDatabaseHas('audit_logs', [
                'event' => 'announcement.completed',
                'actor_id' => $staff->id,
                'entity_type' => 'announcement',
                'entity_id' => $announcement->id,
            ]);
        }
    }

    public function test_update_portals_show_all_statuses_without_changing_program_tabs(): void
    {
        $portals = [
            ['base' => '/clerk', 'role' => 'clerk', 'audience' => 'all', 'component' => 'Clerk/Announcements/Index'],
            ['base' => '/pwd', 'role' => 'pwd_staff', 'audience' => 'pwd', 'component' => 'Pwd/Announcements/Index'],
            ['base' => '/senior', 'role' => 'senior_staff', 'audience' => 'senior_citizens', 'component' => 'Senior/Announcements/Index'],
        ];

        foreach ($portals as $portal) {
            $staff = $this->createStaff($this->barangay, $portal['role']);

            foreach ([
                ['approval_status' => 'pending', 'is_published' => false],
                ['approval_status' => 'approved', 'is_published' => true],
                ['approval_status' => 'rejected', 'is_published' => false],
                ['approval_status' => 'approved', 'is_published' => true, 'completed_at' => now()],
            ] as $status) {
                $this->createAnnouncement($this->barangay, [
                    'category' => 'General',
                    'target_audience' => $portal['audience'],
                    'requires_attendance' => false,
                    ...$status,
                ]);
            }

            $this->actingAs($staff)
                ->get($portal['base'].'/updates?tab=done')
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component($portal['component'])
                    ->has('announcements', 4)
                    ->where('activeTab', 'all')
                    ->where('tabCounts.all', 4));

            $this->createAnnouncement($this->barangay, [
                'title' => 'Program remains pending',
                'category' => 'Event',
                'target_audience' => $portal['audience'],
                'approval_status' => 'pending',
                'is_published' => false,
            ]);

            $this->actingAs($staff)
                ->get($portal['base'].'/programs?tab=pending')
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('activeTab', 'pending')
                    ->where('tabCounts.pending', 1));
        }
    }

    public function test_staff_can_complete_only_published_approved_informational_updates(): void
    {
        $portals = [
            ['base' => '/clerk', 'role' => 'clerk', 'audience' => 'all'],
            ['base' => '/pwd', 'role' => 'pwd_staff', 'audience' => 'pwd'],
            ['base' => '/senior', 'role' => 'senior_staff', 'audience' => 'senior_citizens'],
        ];

        foreach ($portals as $portal) {
            $staff = $this->createStaff($this->barangay, $portal['role']);
            $update = $this->createAnnouncement($this->barangay, [
                'category' => 'General',
                'target_audience' => $portal['audience'],
                'approval_status' => 'approved',
                'is_published' => true,
                'requires_attendance' => false,
            ]);

            $this->actingAs($staff)
                ->post($portal['base'].'/updates/'.$update->id.'/complete')
                ->assertRedirect();

            $this->assertDatabaseHas('announcements', [
                'id' => $update->id,
                'approval_status' => 'approved',
                'is_published' => true,
                'completed_by' => $staff->id,
            ]);
            $this->assertNotNull($update->fresh()->completed_at);
            $this->assertDatabaseHas('announcement_approval_logs', [
                'announcement_id' => $update->id,
                'action' => 'update_completed',
                'previous_status' => 'approved',
                'new_status' => 'approved',
            ]);
        }

        $pwdStaff = $this->createStaff($this->barangay, 'pwd_staff');
        $foreignUpdate = $this->createAnnouncement($this->other, [
            'category' => 'General',
            'target_audience' => 'pwd',
            'approval_status' => 'approved',
            'is_published' => true,
        ]);

        $this->actingAs($pwdStaff)
            ->post('/pwd/updates/'.$foreignUpdate->id.'/complete')
            ->assertNotFound();

        $sameBarangayUpdate = $this->createAnnouncement($this->barangay, [
            'category' => 'General',
            'approval_status' => 'approved',
            'is_published' => true,
        ]);
        $this->actingAs($pwdStaff)
            ->post('/clerk/updates/'.$sameBarangayUpdate->id.'/complete')
            ->assertForbidden();

        $this->assertDatabaseHas('announcements', [
            'id' => $foreignUpdate->id,
            'completed_at' => null,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('announcements', [
            'id' => $sameBarangayUpdate->id,
            'completed_at' => null,
        ]);

        $staff = $this->createStaff($this->barangay, 'clerk');
        $pending = $this->createAnnouncement($this->barangay, [
            'category' => 'General',
            'approval_status' => 'pending',
            'is_published' => false,
        ]);

        $this->actingAs($staff)
            ->post('/clerk/updates/'.$pending->id.'/complete')
            ->assertSessionHasErrors('completion');

        $this->assertDatabaseHas('announcements', [
            'id' => $pending->id,
            'approval_status' => 'pending',
            'is_published' => false,
            'completed_at' => null,
        ]);
    }

    public function test_residents_get_current_and_past_updates_with_existing_visibility_rules(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09180000112']);
        $currentUpdate = $this->createAnnouncement($this->barangay, ['title' => 'Current information']);
        $pastUpdate = $this->createAnnouncement($this->barangay, [
            'title' => 'Past information',
            'completed_at' => now(),
        ]);
        $completedProgram = $this->createAnnouncement($this->barangay, [
            'title' => 'Completed program',
            'category' => 'Event',
            'completed_at' => now(),
        ]);
        $this->createAnnouncement($this->barangay, [
            'title' => 'Pending information',
            'approval_status' => 'pending',
            'is_published' => false,
        ]);
        $this->createAnnouncement($this->barangay, [
            'title' => 'Rejected information',
            'approval_status' => 'rejected',
            'is_published' => false,
        ]);
        $this->createAnnouncement($this->barangay, [
            'title' => 'Unpublished information',
            'approval_status' => 'approved',
            'is_published' => false,
        ]);
        $this->createAnnouncement($this->other, ['title' => 'Other barangay information']);
        $this->createAnnouncement($this->barangay, [
            'title' => 'PWD-only update',
            'target_audience' => 'pwd',
        ]);

        $this->withSession(['resident_id' => $resident->id])
            ->get('/resident/updates')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('kind', 'updates')
                ->where('period', 'current')
                ->has('announcements', 1)
                ->where('announcements.0.id', $currentUpdate->id));

        $this->withSession(['resident_id' => $resident->id])
            ->get('/resident/updates?period=past')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('period', 'past')
                ->has('announcements', 1)
                ->where('announcements.0.id', $pastUpdate->id));

        $this->withSession(['resident_id' => $resident->id])
            ->get('/resident/announcements')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('announcements', 2)
                ->where('announcements.0.id', $completedProgram->id)
                ->where('announcements.1.id', $currentUpdate->id));

        $this->withSession(['resident_id' => $resident->id])
            ->get('/resident/updates/'.$pastUpdate->id.'?period=past')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Resident/AnnouncementDetails')
                ->where('kind', 'updates')
                ->where('announcement.id', $pastUpdate->id));

        $this->withSession(['resident_id' => $resident->id])
            ->get('/resident/updates/'.$pastUpdate->id.'?period=current')
            ->assertNotFound();

        $this->assertNotNull($completedProgram->completed_at);
    }

    public function test_updates_routes_reject_program_records_and_keep_completed_updates(): void
    {
        $portals = [
            ['base' => '/clerk', 'role' => 'clerk', 'audience' => 'all'],
            ['base' => '/pwd', 'role' => 'pwd_staff', 'audience' => 'pwd'],
            ['base' => '/senior', 'role' => 'senior_staff', 'audience' => 'senior_citizens'],
        ];

        foreach ($portals as $portal) {
            $staff = $this->createStaff($this->barangay, $portal['role']);
            $program = $this->createAnnouncement($this->barangay, [
                'category' => 'Event',
                'target_audience' => $portal['audience'],
                'requires_attendance' => false,
            ]);
            $update = $this->createAnnouncement($this->barangay, [
                'category' => 'General',
                'target_audience' => $portal['audience'],
                'completed_at' => now(),
            ]);

            $this->actingAs($staff)
                ->get($portal['base'].'/updates/'.$program->id)
                ->assertNotFound();
            $this->actingAs($staff)
                ->put($portal['base'].'/updates/'.$program->id, [
                    'title' => 'Converted',
                    'content' => 'Must not convert a program.',
                    'category' => 'General',
                    'target_audience' => $portal['audience'],
                ])
                ->assertNotFound();
            $this->actingAs($staff)
                ->delete($portal['base'].'/updates/'.$program->id)
                ->assertNotFound();
            $this->actingAs($staff)
                ->get($portal['base'].'/programs/'.$update->id)
                ->assertNotFound();

            $this->assertDatabaseHas('announcements', ['id' => $program->id, 'category' => 'Event']);
            $this->assertDatabaseHas('announcements', ['id' => $update->id]);
        }

        $clerk = $this->createStaff($this->barangay, 'clerk');
        $completedUpdate = $this->createAnnouncement($this->barangay, [
            'category' => 'General',
            'completed_at' => now(),
        ]);
        $this->actingAs($clerk)
            ->delete('/clerk/announcements/'.$completedUpdate->id)
            ->assertStatus(409);
        $this->assertDatabaseHas('announcements', ['id' => $completedUpdate->id]);
    }

    public function test_repeated_completion_is_idempotent_and_preserves_first_actor_and_timestamp(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-23 10:00:00'));

        try {
            $firstClerk = $this->createStaff($this->barangay, 'clerk');
            $secondClerk = $this->createStaff($this->barangay, 'clerk');
            $announcement = $this->completedEvent([
                'title' => 'Idempotent Event',
                'completed_at' => null,
            ]);

            $this->actingAs($firstClerk)
                ->post('/clerk/announcements/'.$announcement->id.'/complete')
                ->assertSessionHas('success', 'Announcement marked as done.');

            $first = DB::table('announcements')->where('id', $announcement->id)->first();

            Carbon::setTestNow(now()->addHour());

            $this->actingAs($secondClerk)
                ->post('/clerk/announcements/'.$announcement->id.'/complete')
                ->assertSessionHas('warning', 'This announcement has already been marked as done.');

            $second = DB::table('announcements')->where('id', $announcement->id)->first();

            $this->assertSame($first->completed_at, $second->completed_at);
            $this->assertSame($first->completed_by, $second->completed_by);
            $this->assertSame(1, DB::table('announcement_approval_logs')
                ->where('announcement_id', $announcement->id)
                ->where('action', 'completed')
                ->count());
            $this->assertSame(1, DB::table('audit_logs')
                ->where('event', 'announcement.completed')
                ->where('entity_id', $announcement->id)
                ->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_completion_rejects_non_event_pending_and_rejected_announcements(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $informational = $this->createAnnouncement($this->barangay, [
            'title' => 'Informational',
            'category' => 'General',
            'event_start_at' => now()->subHour(),
            'requires_attendance' => false,
        ]);
        $pending = $this->createAnnouncement($this->barangay, [
            'title' => 'Pending Event',
            'category' => 'Event',
            'event_start_at' => now()->subHour(),
            'approval_status' => 'pending',
            'is_published' => false,
        ]);
        $rejected = $this->createAnnouncement($this->barangay, [
            'title' => 'Rejected Event',
            'category' => 'Event',
            'event_start_at' => now()->subHour(),
            'approval_status' => 'rejected',
            'is_published' => false,
        ]);

        foreach ([$informational, $pending, $rejected] as $announcement) {
            $this->actingAs($clerk)
                ->post('/clerk/announcements/'.$announcement->id.'/complete')
                ->assertSessionHasErrors('completion');

            $this->assertNull(
                DB::table('announcements')->where('id', $announcement->id)->value('completed_at'),
            );
        }

        $this->assertSame(0, DB::table('announcement_approval_logs')
            ->where('action', 'completed')
            ->count());
        $this->assertSame(0, DB::table('audit_logs')
            ->where('event', 'announcement.completed')
            ->count());
    }

    public function test_completion_respects_desk_audience_and_barangay_scope(): void
    {
        $pwdStaff = $this->createStaff($this->barangay, 'pwd_staff');

        $own = $this->createAnnouncement($this->barangay, [
            'title' => 'Pwd Own Event',
            'category' => 'Event',
            'target_audience' => 'pwd',
            'event_start_at' => now()->subHour(),
            'location' => 'Covered Court',
        ]);
        $allAudience = $this->createAnnouncement($this->barangay, [
            'title' => 'All Audience Event',
            'category' => 'Event',
            'target_audience' => 'all',
            'event_start_at' => now()->subHour(),
            'location' => 'Covered Court',
        ]);
        $foreign = $this->createAnnouncement($this->other, [
            'title' => 'Pwd Foreign Event',
            'category' => 'Event',
            'target_audience' => 'pwd',
            'event_start_at' => now()->subHour(),
            'location' => 'Covered Court',
        ]);

        $this->actingAs($pwdStaff)
            ->post('/pwd/announcements/'.$allAudience->id.'/complete')
            ->assertStatus(403);

        $this->actingAs($pwdStaff)
            ->post('/pwd/announcements/'.$foreign->id.'/complete')
            ->assertStatus(404);

        $this->assertNull(DB::table('announcements')->where('id', $allAudience->id)->value('completed_at'));
        $this->assertNull(DB::table('announcements')->where('id', $foreign->id)->value('completed_at'));

        $this->actingAs($pwdStaff)
            ->post('/pwd/announcements/'.$own->id.'/complete')
            ->assertSessionHas('success', 'Announcement marked as done.');

        $this->assertSame($pwdStaff->id, (int) DB::table('announcements')
            ->where('id', $own->id)
            ->value('completed_by'));
    }

    public function test_completed_announcements_cannot_be_edited_or_resubmitted_across_staff_desks(): void
    {
        $desks = [
            ['role' => 'clerk', 'portal' => 'clerk', 'audience' => 'all'],
            ['role' => 'pwd_staff', 'portal' => 'pwd', 'audience' => 'pwd'],
            ['role' => 'senior_staff', 'portal' => 'senior', 'audience' => 'senior_citizens'],
        ];

        foreach ($desks as $desk) {
            $staff = $this->createStaff($this->barangay, $desk['role']);
            $announcement = $this->completedEvent([
                'title' => 'Completed '.$desk['portal'],
                'target_audience' => $desk['audience'],
            ]);

            $payload = [
                'title' => 'Changed Title',
                'content' => 'Changed content',
                'category' => 'Event',
                'importance' => 'high',
                'requires_attendance' => false,
                'event_start_at' => now()->addDay()->toDateTimeString(),
                'location' => 'New Venue',
            ];

            if ($desk['role'] === 'clerk') {
                $payload['target_audience'] = 'all';
            }

            $this->actingAs($staff)
                ->put('/'.$desk['portal'].'/announcements/'.$announcement->id, $payload)
                ->assertSessionHasErrors('announcement');

            $fresh = $announcement->fresh();

            $this->assertSame('Completed '.$desk['portal'], $fresh->title);
            $this->assertSame('approved', $fresh->approval_status);
            $this->assertTrue($fresh->is_published);
            $this->assertSame('normal', $fresh->importance);
            $this->assertNotNull($fresh->completed_at);
            $this->assertSame(0, DB::table('announcement_approval_logs')
                ->where('announcement_id', $announcement->id)
                ->where('action', 'resubmitted')
                ->count());
        }
    }

    public function test_admin_cannot_review_completed_announcement(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $announcement = $this->createAnnouncement($this->barangay, [
            'title' => 'Completed Pending',
            'approval_status' => 'pending',
            'is_published' => false,
            'completed_at' => now(),
        ]);
        $token = $this->revisionToken($announcement);

        $this->actingAs($admin)
            ->post('/admin/announcements/'.$announcement->id.'/approve', [
                'revision_token' => $token,
            ])
            ->assertSessionHasErrors('approval');

        $this->assertSame('pending', $announcement->fresh()->approval_status);
        $this->assertNull($announcement->fresh()->reviewed_at);

        $this->actingAs($admin)
            ->post('/admin/announcements/'.$announcement->id.'/reject', [
                'revision_token' => $token,
                'rejection_reason' => 'This should not be applied.',
            ])
            ->assertSessionHasErrors('approval');

        $fresh = $announcement->fresh();

        $this->assertSame('pending', $fresh->approval_status);
        $this->assertNull($fresh->reviewed_at);
        $this->assertSame(0, DB::table('announcement_approval_logs')
            ->where('announcement_id', $announcement->id)
            ->count());
    }

    public function test_completed_event_remains_visible_to_residents_and_attendance_stays_available(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09180000123',
        ]);
        $announcement = $this->completedEvent([
            'title' => 'Completed Attendance Event',
            'target_audience' => 'all',
            'requires_attendance' => true,
        ]);

        $this->withSession(['resident_id' => $resident->id])
            ->get('/resident/announcements')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Resident/Announcements')
                ->has('announcements', 1));

        $this->actingAs($clerk)
            ->get('/clerk/announcements/'.$announcement->id.'/attendance')
            ->assertOk();
    }

    public function test_announcement_export_includes_importance_and_completion_fields(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $announcement = $this->completedEvent([
            'title' => 'Exported Event',
            'importance' => 'urgent',
            'completed_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)
            ->get('/admin/settings/export/backup')
            ->assertOk();

        $table = $response->json('tables.announcements');

        $this->assertContains('importance', $table['columns']);
        $this->assertContains('completed_at', $table['columns']);
        $this->assertContains('completed_by', $table['columns']);

        $row = collect($table['rows'])
            ->first(fn (array $row) => (int) $row['id'] === $announcement->id);

        $this->assertNotNull($row);
        $this->assertSame('urgent', $row['importance']);
        $this->assertSame($admin->id, (int) $row['completed_by']);
        $this->assertNotNull($row['completed_at']);
    }
}
