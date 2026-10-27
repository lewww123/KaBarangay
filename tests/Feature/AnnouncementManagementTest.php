<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\AnnouncementApprovalLog;
use App\Models\Barangay;
use App\Models\Purok;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class AnnouncementManagementTest extends TestCase
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

    /*
    |--------------------------------------------------------------------------
    | Resident visibility targeting (senior / pwd / general)
    |--------------------------------------------------------------------------
    */

    public function test_senior_resident_feed_targets_all_and_senior_announcements_only(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09180000001']);
        $this->assignSector($resident, 'senior');

        $this->createAnnouncement($this->barangay, ['target_audience' => 'all']);
        $this->createAnnouncement($this->barangay, ['target_audience' => 'senior_citizens']);
        $this->createAnnouncement($this->barangay, ['target_audience' => 'pwd']);

        $this->withSession(['resident_id' => $resident->id])
            ->get('/resident/announcements')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Resident/Announcements')
                ->has('announcements', 2));
    }

    public function test_pwd_resident_feed_targets_all_and_pwd_announcements_only(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09180000002']);
        $this->assignSector($resident, 'pwd');

        $this->createAnnouncement($this->barangay, ['target_audience' => 'all']);
        $this->createAnnouncement($this->barangay, ['target_audience' => 'senior_citizens']);
        $this->createAnnouncement($this->barangay, ['target_audience' => 'pwd']);

        $this->withSession(['resident_id' => $resident->id])
            ->get('/resident/announcements')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Resident/Announcements')
                ->has('announcements', 2));
    }

    public function test_regular_resident_feed_targets_all_announcements_only(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09180000003']);

        $this->createAnnouncement($this->barangay, ['target_audience' => 'all']);
        $this->createAnnouncement($this->barangay, ['target_audience' => 'senior_citizens']);
        $this->createAnnouncement($this->barangay, ['target_audience' => 'pwd']);

        $this->withSession(['resident_id' => $resident->id])
            ->get('/resident/announcements')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Resident/Announcements')
                ->has('announcements', 1));
    }

    public function test_resident_feed_hides_unapproved_or_unpublished_announcements(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09180000004']);

        $this->createAnnouncement($this->barangay, [
            'approval_status' => 'approved',
            'is_published' => true,
        ]);
        $this->createAnnouncement($this->barangay, [
            'approval_status' => 'pending',
            'is_published' => false,
        ]);
        $this->createAnnouncement($this->barangay, [
            'approval_status' => 'approved',
            'is_published' => false,
        ]);
        $this->createAnnouncement($this->barangay, [
            'approval_status' => 'rejected',
            'is_published' => false,
        ]);

        $this->withSession(['resident_id' => $resident->id])
            ->get('/resident/announcements')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Resident/Announcements')
                ->has('announcements', 1));
    }

    /*
    |--------------------------------------------------------------------------
    | Cross-barangay denial without mutation
    |--------------------------------------------------------------------------
    */

    public function test_clerk_cannot_update_another_barangays_announcement(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $foreign = $this->createAnnouncement($this->other, [
            'title' => 'Foreign Announcement',
            'target_audience' => 'all',
        ]);

        $this->actingAs($clerk)
            ->put('/clerk/announcements/'.$foreign->id, [
                'title' => 'Hijacked',
                'content' => 'Hijacked content',
                'category' => 'General',
                'target_audience' => 'all',
            ])
            ->assertStatus(404);

        $this->assertDatabaseHas('announcements', [
            'id' => $foreign->id,
            'title' => 'Foreign Announcement',
            'barangay_id' => $this->other->id,
        ]);
    }

    public function test_clerk_cannot_delete_another_barangays_announcement(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $foreign = $this->createAnnouncement($this->other, ['title' => 'Foreign Announcement']);

        $this->actingAs($clerk)
            ->delete('/clerk/announcements/'.$foreign->id)
            ->assertStatus(404);

        $this->assertDatabaseHas('announcements', [
            'id' => $foreign->id,
            'title' => 'Foreign Announcement',
            'barangay_id' => $this->other->id,
        ]);
    }

    public function test_clerk_cannot_view_another_barangays_attendance_sheet(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $foreign = $this->createAnnouncement($this->other, ['requires_attendance' => true]);

        $this->actingAs($clerk)
            ->get('/clerk/announcements/'.$foreign->id.'/attendance')
            ->assertStatus(404);
    }

    /*
    |--------------------------------------------------------------------------
    | Allowed same-barangay clerk management
    |--------------------------------------------------------------------------
    */

    public function test_clerk_can_create_announcements_for_every_allowed_audience(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        foreach (['all', 'senior_citizens', 'pwd'] as $audience) {
            $this->actingAs($clerk)->post('/clerk/announcements', [
                'title' => 'Announcement for '.$audience,
                'content' => 'Content',
                'category' => 'General',
                'target_audience' => $audience,
            ])->assertRedirect();

            $this->assertDatabaseHas('announcements', [
                'barangay_id' => $this->barangay->id,
                'target_audience' => $audience,
                'approval_status' => 'pending',
                'is_published' => false,
            ]);
        }
    }

    public function test_clerk_can_manage_announcement_created_by_another_clerk(): void
    {
        $creator = $this->createStaff($this->barangay, 'clerk');
        $manager = $this->createStaff($this->barangay, 'clerk');

        $announcement = $this->createAnnouncement($this->barangay, [
            'created_by' => $creator->id,
            'target_audience' => 'senior_citizens',
            'approval_status' => 'approved',
            'is_published' => true,
        ]);

        $this->actingAs($manager)->put('/clerk/announcements/'.$announcement->id, [
            'title' => 'Updated by another clerk',
            'content' => 'Updated content',
            'category' => 'General',
            'target_audience' => 'pwd',
        ])->assertRedirect();

        $this->assertDatabaseHas('announcements', [
            'id' => $announcement->id,
            'title' => 'Updated by another clerk',
            'target_audience' => 'pwd',
            'approval_status' => 'pending',
            'is_published' => false,
            'created_by' => $creator->id,
        ]);

        $this->actingAs($manager)
            ->delete('/clerk/announcements/'.$announcement->id)
            ->assertRedirect();

        $this->assertDatabaseMissing('announcements', ['id' => $announcement->id]);
    }

    public function test_clerk_cannot_create_announcement_for_unapproved_audience(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->from('/clerk/announcements')
            ->post('/clerk/announcements', [
                'title' => 'Invalid audience',
                'content' => 'Content',
                'category' => 'General',
                'target_audience' => 'solo_parents',
            ])
            ->assertSessionHasErrors('target_audience');

        $this->assertDatabaseCount('announcements', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | Sector staff scope
    |--------------------------------------------------------------------------
    */

    public function test_senior_staff_announcement_is_forced_to_senior_audience(): void
    {
        $staff = $this->createStaff($this->barangay, 'senior_staff');

        $this->actingAs($staff)->post('/senior/announcements', [
            'title' => 'Senior Notice',
            'content' => 'Content',
            'category' => 'General',
            'target_audience' => 'pwd',
        ])->assertRedirect();

        $this->assertDatabaseHas('announcements', [
            'barangay_id' => $this->barangay->id,
            'target_audience' => 'senior_citizens',
            'approval_status' => 'pending',
            'is_published' => false,
        ]);
    }

    public function test_pwd_staff_announcement_is_forced_to_pwd_audience(): void
    {
        $staff = $this->createStaff($this->barangay, 'pwd_staff');

        $this->actingAs($staff)->post('/pwd/announcements', [
            'title' => 'PWD Notice',
            'content' => 'Content',
            'category' => 'General',
            'target_audience' => 'senior_citizens',
        ])->assertRedirect();

        $this->assertDatabaseHas('announcements', [
            'barangay_id' => $this->barangay->id,
            'target_audience' => 'pwd',
            'approval_status' => 'pending',
            'is_published' => false,
        ]);
    }

    public function test_senior_staff_cannot_manage_other_audience_announcement(): void
    {
        $staff = $this->createStaff($this->barangay, 'senior_staff');
        $announcement = $this->createAnnouncement($this->barangay, ['target_audience' => 'all']);

        $this->actingAs($staff)->put('/senior/announcements/'.$announcement->id, [
            'title' => 'Nope',
            'content' => 'Content',
            'category' => 'General',
        ])->assertStatus(403);
    }

    /*
    |--------------------------------------------------------------------------
    | Approval / publication lifecycle
    |--------------------------------------------------------------------------
    */

    public function test_announcement_stays_hidden_until_admin_approval(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09180000005']);
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($clerk)->post('/clerk/announcements', [
            'title' => 'Lifecycle',
            'content' => 'Content',
            'category' => 'General',
            'target_audience' => 'all',
        ])->assertRedirect();

        $announcement = Announcement::query()->firstOrFail();

        $this->assertSame('pending', $announcement->approval_status);
        $this->assertFalse($announcement->is_published);

        $this->withSession(['resident_id' => $resident->id])
            ->get('/resident/announcements')
            ->assertInertia(fn ($page) => $page->has('announcements', 0));

        $this->actingAs($admin)
            ->post('/admin/announcements/'.$announcement->id.'/approve', [
                'revision_token' => $this->revisionToken($announcement),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('announcements', [
            'id' => $announcement->id,
            'approval_status' => 'approved',
            'is_published' => true,
        ]);

        $this->withSession(['resident_id' => $resident->id])
            ->get('/resident/announcements')
            ->assertInertia(fn ($page) => $page->has('announcements', 1));
    }

    public function test_editing_approved_announcement_unpublishes_and_requires_reapproval(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09180000006']);
        $this->assignSector($resident, 'senior');
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $admin = $this->createStaff($this->barangay, 'admin');

        $announcement = $this->createAnnouncement($this->barangay, [
            'target_audience' => 'senior_citizens',
            'approval_status' => 'approved',
            'is_published' => true,
        ]);

        $this->actingAs($clerk)->put('/clerk/announcements/'.$announcement->id, [
            'title' => 'Edited',
            'content' => 'Edited content',
            'category' => 'General',
            'target_audience' => 'senior_citizens',
        ])->assertRedirect();

        $this->assertDatabaseHas('announcements', [
            'id' => $announcement->id,
            'approval_status' => 'pending',
            'is_published' => false,
        ]);

        $this->withSession(['resident_id' => $resident->id])
            ->get('/resident/announcements')
            ->assertInertia(fn ($page) => $page->has('announcements', 0));

        $this->actingAs($admin)
            ->post('/admin/announcements/'.$announcement->id.'/approve', [
                'revision_token' => $this->revisionToken($announcement),
            ])
            ->assertRedirect();

        $this->withSession(['resident_id' => $resident->id])
            ->get('/resident/announcements')
            ->assertInertia(fn ($page) => $page->has('announcements', 1));
    }

    public function test_admin_rejection_records_reason_for_pending_announcement(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        // Rejection is only valid from the pending state.
        $announcement = $this->createAnnouncement($this->barangay, [
            'target_audience' => 'all',
            'approval_status' => 'pending',
            'is_published' => false,
        ]);

        $this->actingAs($admin)
            ->post('/admin/announcements/'.$announcement->id.'/reject', [
                'revision_token' => $this->revisionToken($announcement),
                'rejection_reason' => 'Please clarify the venue details.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('announcements', [
            'id' => $announcement->id,
            'approval_status' => 'rejected',
            'is_published' => false,
            'rejection_reason' => 'Please clarify the venue details.',
        ]);
    }

    public function test_admin_approval_clears_rejection_reason(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $admin = $this->createStaff($this->barangay, 'admin');

        $announcement = $this->createAnnouncement($this->barangay, [
            'approval_status' => 'rejected',
            'is_published' => false,
            'rejection_reason' => 'Needs work',
        ]);

        // A rejected announcement must be resubmitted by staff (which moves it
        // back to pending) before the admin can approve it again.
        $this->actingAs($clerk)->put('/clerk/announcements/'.$announcement->id, [
            'title' => 'Fixed',
            'content' => 'Fixed content',
            'category' => 'General',
            'target_audience' => 'all',
        ])->assertRedirect();

        $this->actingAs($admin)
            ->post('/admin/announcements/'.$announcement->id.'/approve', [
                'revision_token' => $this->revisionToken($announcement),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('announcements', [
            'id' => $announcement->id,
            'approval_status' => 'approved',
            'is_published' => true,
            'rejection_reason' => null,
        ]);

        $this->assertDatabaseHas('announcement_approval_logs', [
            'announcement_id' => $announcement->id,
            'action' => 'approved',
            'previous_status' => 'pending',
            'new_status' => 'approved',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Rejection feedback visibility
    |--------------------------------------------------------------------------
    */

    public function test_staff_announcement_feed_exposes_rejection_reason(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->createAnnouncement($this->barangay, [
            'approval_status' => 'rejected',
            'is_published' => false,
            'rejection_reason' => 'Please clarify the venue details.',
        ]);

        $this->actingAs($clerk)
            ->get('/clerk/announcements')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Clerk/Announcements/Index')
                ->has('announcements', 1)
                ->where('announcements.0.rejection_reason', 'Please clarify the venue details.'));
    }

    public function test_pending_announcement_after_resubmission_retains_and_exposes_rejection_reason(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $announcement = $this->createAnnouncement($this->barangay, [
            'approval_status' => 'rejected',
            'is_published' => false,
            'rejection_reason' => 'Please clarify the venue details.',
        ]);

        $this->actingAs($clerk)->put('/clerk/announcements/'.$announcement->id, [
            'title' => 'Resubmitted',
            'content' => 'Resubmitted content',
            'category' => 'General',
            'target_audience' => 'all',
        ])->assertRedirect();

        $this->assertDatabaseHas('announcements', [
            'id' => $announcement->id,
            'approval_status' => 'pending',
            'is_published' => false,
            'rejection_reason' => 'Please clarify the venue details.',
        ]);

        $this->actingAs($clerk)
            ->get('/clerk/announcements')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Clerk/Announcements/Index')
                ->has('announcements', 1)
                ->where('announcements.0.approval_status', 'pending')
                ->where('announcements.0.rejection_reason', 'Please clarify the venue details.'));
    }

    /*
    |--------------------------------------------------------------------------
    | Audit log coverage for staff actions
    |--------------------------------------------------------------------------
    */

    public function test_staff_submission_and_resubmission_are_written_to_the_approval_log(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)->post('/clerk/announcements', [
            'title' => 'Logged',
            'content' => 'Content',
            'category' => 'General',
            'target_audience' => 'all',
        ])->assertRedirect();

        $announcement = Announcement::query()->firstOrFail();

        $this->assertDatabaseHas('announcement_approval_logs', [
            'announcement_id' => $announcement->id,
            'actor_id' => $clerk->id,
            'action' => 'created',
            'previous_status' => null,
            'new_status' => 'pending',
            'barangay_id' => $this->barangay->id,
        ]);

        $this->actingAs($clerk)->put('/clerk/announcements/'.$announcement->id, [
            'title' => 'Logged again',
            'content' => 'Content',
            'category' => 'General',
            'target_audience' => 'all',
        ])->assertRedirect();

        $this->assertDatabaseHas('announcement_approval_logs', [
            'announcement_id' => $announcement->id,
            'actor_id' => $clerk->id,
            'action' => 'resubmitted',
            'previous_status' => 'pending',
            'new_status' => 'pending',
            'barangay_id' => $this->barangay->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Admin approval authorization and state transitions
    |--------------------------------------------------------------------------
    */

    public function test_admin_cannot_approve_another_barangays_announcement(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $foreign = $this->createAnnouncement($this->other, [
            'title' => 'Foreign Pending',
            'approval_status' => 'pending',
            'is_published' => false,
        ]);

        $this->actingAs($admin)
            ->post('/admin/announcements/'.$foreign->id.'/approve')
            ->assertStatus(404);

        $this->assertDatabaseHas('announcements', [
            'id' => $foreign->id,
            'approval_status' => 'pending',
            'is_published' => false,
            'barangay_id' => $this->other->id,
        ]);
    }

    public function test_admin_cannot_reject_another_barangays_announcement(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $foreign = $this->createAnnouncement($this->other, [
            'title' => 'Foreign Pending',
            'approval_status' => 'pending',
            'is_published' => false,
        ]);

        $this->actingAs($admin)
            ->post('/admin/announcements/'.$foreign->id.'/reject', [
                'rejection_reason' => 'Should not apply cross-barangay.',
            ])
            ->assertStatus(404);

        $this->assertDatabaseHas('announcements', [
            'id' => $foreign->id,
            'approval_status' => 'pending',
            'is_published' => false,
            'rejection_reason' => null,
            'barangay_id' => $this->other->id,
        ]);
    }

    public function test_non_admin_cannot_reject_announcements(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $announcement = $this->createAnnouncement($this->barangay, [
            'approval_status' => 'pending',
            'is_published' => false,
        ]);

        $this->actingAs($clerk)
            ->post('/admin/announcements/'.$announcement->id.'/reject', [
                'rejection_reason' => 'Trying to bypass the admin only route.',
            ])
            ->assertStatus(403);

        $this->assertDatabaseHas('announcements', [
            'id' => $announcement->id,
            'approval_status' => 'pending',
        ]);
    }

    public function test_repeated_approval_is_rejected_without_duplicate_logs(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $announcement = $this->createAnnouncement($this->barangay, [
            'approval_status' => 'pending',
            'is_published' => false,
        ]);

        $this->actingAs($admin)
            ->post('/admin/announcements/'.$announcement->id.'/approve', [
                'revision_token' => $this->revisionToken($announcement),
            ])
            ->assertRedirect();

        $this->assertSame(1, AnnouncementApprovalLog::query()
            ->where('announcement_id', $announcement->id)
            ->where('action', 'approved')
            ->count());

        $this->actingAs($admin)
            ->post('/admin/announcements/'.$announcement->id.'/approve', [
                'revision_token' => $this->revisionToken($announcement),
            ])
            ->assertSessionHasErrors('approval');

        $this->assertSame(1, AnnouncementApprovalLog::query()
            ->where('announcement_id', $announcement->id)
            ->where('action', 'approved')
            ->count());
        $this->assertDatabaseHas('announcements', [
            'id' => $announcement->id,
            'approval_status' => 'approved',
            'is_published' => true,
        ]);
    }

    public function test_approving_a_rejected_announcement_is_rejected(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $announcement = $this->createAnnouncement($this->barangay, [
            'approval_status' => 'rejected',
            'is_published' => false,
            'rejection_reason' => 'Needs work',
        ]);

        $this->actingAs($admin)
            ->post('/admin/announcements/'.$announcement->id.'/approve')
            ->assertSessionHasErrors('approval');

        $this->assertDatabaseHas('announcements', [
            'id' => $announcement->id,
            'approval_status' => 'rejected',
            'is_published' => false,
            'rejection_reason' => 'Needs work',
        ]);

        $this->assertSame(0, AnnouncementApprovalLog::query()
            ->where('announcement_id', $announcement->id)
            ->count());
    }

    public function test_rejecting_an_approved_announcement_is_rejected(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $announcement = $this->createAnnouncement($this->barangay, [
            'approval_status' => 'approved',
            'is_published' => true,
        ]);

        $this->actingAs($admin)
            ->post('/admin/announcements/'.$announcement->id.'/reject', [
                'rejection_reason' => 'Should not unpublish an approved notice.',
            ])
            ->assertSessionHasErrors('approval');

        $this->assertDatabaseHas('announcements', [
            'id' => $announcement->id,
            'approval_status' => 'approved',
            'is_published' => true,
            'rejection_reason' => null,
        ]);

        $this->assertSame(0, AnnouncementApprovalLog::query()
            ->where('announcement_id', $announcement->id)
            ->count());
    }

    public function test_admin_approval_index_lists_only_own_barangay(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->createAnnouncement($this->barangay, [
            'title' => 'Mine',
            'approval_status' => 'pending',
        ]);
        $this->createAnnouncement($this->other, [
            'title' => 'Foreign',
            'approval_status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->get('/admin/announcements/approvals')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/AnnouncementApprovals')
                ->has('announcements', 1)
                ->where('announcements.0.title', 'Mine')
                ->where('announcements.0.revision_token', fn ($token) => is_string($token) && strlen($token) === 64));
    }

    public function test_admin_approval_index_exposes_current_publication_actor_and_timestamp(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $firstPublisher = $this->createStaff($this->barangay, 'admin');
        $currentPublisher = $this->createStaff($this->barangay, 'admin');

        $announcement = $this->createAnnouncement($this->barangay, [
            'title' => 'Republished Notice',
            'created_by' => $clerk->id,
            'approval_status' => 'pending',
            'is_published' => false,
        ]);

        $this->actingAs($firstPublisher)
            ->post('/admin/announcements/'.$announcement->id.'/approve', [
                'revision_token' => $this->revisionToken($announcement),
            ])
            ->assertRedirect();

        $this->actingAs($clerk)->put('/clerk/announcements/'.$announcement->id, [
            'title' => 'Republished Notice',
            'content' => 'Updated before republication.',
            'category' => 'General',
            'target_audience' => 'all',
        ])->assertRedirect();

        $this->actingAs($currentPublisher)
            ->post('/admin/announcements/'.$announcement->id.'/approve', [
                'revision_token' => $this->revisionToken($announcement),
            ])
            ->assertRedirect();

        $publicationLog = AnnouncementApprovalLog::query()
            ->where('announcement_id', $announcement->id)
            ->where('action', 'approved')
            ->orderByDesc('id')
            ->firstOrFail();

        $this->actingAs($firstPublisher)
            ->get('/admin/announcements/approvals')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/AnnouncementApprovals')
                ->where('announcements.0.publication.published_by', $currentPublisher->name)
                ->where(
                    'announcements.0.publication.published_at',
                    $publicationLog->created_at->toIso8601String()
                ));
    }

    public function test_admin_approval_index_keeps_publication_after_event_completion(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $admin = $this->createStaff($this->barangay, 'admin');

        $announcement = $this->createAnnouncement($this->barangay, [
            'title' => 'Completed Event',
            'category' => 'Event',
            'approval_status' => 'pending',
            'is_published' => false,
        ]);

        $this->actingAs($admin)
            ->post('/admin/announcements/'.$announcement->id.'/approve', [
                'revision_token' => $this->revisionToken($announcement),
            ])
            ->assertRedirect();

        $this->actingAs($clerk)
            ->post('/clerk/announcements/'.$announcement->id.'/complete')
            ->assertRedirect();

        $publicationLog = AnnouncementApprovalLog::query()
            ->where('announcement_id', $announcement->id)
            ->where('action', 'approved')
            ->firstOrFail();

        $this->actingAs($admin)
            ->get('/admin/announcements/approvals')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/AnnouncementApprovals')
                ->where('announcements.0.publication.published_by', $admin->name)
                ->where(
                    'announcements.0.publication.published_at',
                    $publicationLog->created_at->toIso8601String()
                ));
    }

    public function test_admin_approval_index_hides_publication_after_resubmission(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $admin = $this->createStaff($this->barangay, 'admin');

        $announcement = $this->createAnnouncement($this->barangay, [
            'approval_status' => 'pending',
            'is_published' => false,
        ]);

        $this->actingAs($admin)
            ->post('/admin/announcements/'.$announcement->id.'/approve', [
                'revision_token' => $this->revisionToken($announcement),
            ])
            ->assertRedirect();

        $this->actingAs($clerk)->put('/clerk/announcements/'.$announcement->id, [
            'title' => 'Needs Another Review',
            'content' => 'Edited after publication.',
            'category' => 'General',
            'target_audience' => 'all',
        ])->assertRedirect();

        $this->actingAs($admin)
            ->get('/admin/announcements/approvals')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/AnnouncementApprovals')
                ->where('announcements.0.approval_status', 'pending')
                ->where('announcements.0.is_published', false)
                ->where('announcements.0.publication', null));
    }

    public function test_admin_approval_index_falls_back_to_recorded_review_for_legacy_publication(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $reviewedAt = now()->subDay();

        $this->createAnnouncement($this->barangay, [
            'approval_status' => 'approved',
            'is_published' => true,
            'reviewed_by' => $admin->id,
            'reviewed_at' => $reviewedAt,
        ]);

        $this->actingAs($admin)
            ->get('/admin/announcements/approvals')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/AnnouncementApprovals')
                ->where('announcements.0.publication.published_by', $admin->name)
                ->where(
                    'announcements.0.publication.published_at',
                    $reviewedAt->toIso8601String()
                ));
    }

    public function test_approval_requires_a_revision_token(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $announcement = $this->createAnnouncement($this->barangay, [
            'approval_status' => 'pending',
            'is_published' => false,
        ]);

        $this->actingAs($admin)
            ->post('/admin/announcements/'.$announcement->id.'/approve')
            ->assertSessionHasErrors('revision_token');

        $this->assertDatabaseHas('announcements', [
            'id' => $announcement->id,
            'approval_status' => 'pending',
            'is_published' => false,
        ]);
        $this->assertDatabaseCount('announcement_approval_logs', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_approval_rejects_a_stale_revision_even_when_updated_at_is_unchanged(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $announcement = $this->createAnnouncement($this->barangay, [
            'approval_status' => 'pending',
            'is_published' => false,
        ]);

        $token = $this->revisionToken($announcement);
        $originalUpdatedAt = $announcement->updated_at?->format('Y-m-d H:i:s');

        DB::table('announcements')
            ->where('id', $announcement->id)
            ->update(['title' => 'Changed after the admin loaded it']);

        $refreshed = $announcement->fresh();

        $this->assertSame($originalUpdatedAt, $refreshed->updated_at?->format('Y-m-d H:i:s'));
        $this->assertNotSame($token, $refreshed->revisionToken());

        $this->actingAs($admin)
            ->post('/admin/announcements/'.$announcement->id.'/approve', [
                'revision_token' => $token,
            ])
            ->assertSessionHasErrors('approval');

        $this->assertDatabaseHas('announcements', [
            'id' => $announcement->id,
            'title' => 'Changed after the admin loaded it',
            'approval_status' => 'pending',
            'is_published' => false,
        ]);
        $this->assertDatabaseCount('announcement_approval_logs', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_rejection_rejects_a_stale_revision_without_recording_a_decision(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $announcement = $this->createAnnouncement($this->barangay, [
            'approval_status' => 'pending',
            'is_published' => false,
        ]);

        $token = $this->revisionToken($announcement);

        DB::table('announcements')
            ->where('id', $announcement->id)
            ->update(['title' => 'Changed after the admin loaded it']);

        $this->actingAs($admin)
            ->post('/admin/announcements/'.$announcement->id.'/reject', [
                'revision_token' => $token,
                'rejection_reason' => 'This decision is based on the old content.',
            ])
            ->assertSessionHasErrors('approval');

        $this->assertDatabaseHas('announcements', [
            'id' => $announcement->id,
            'title' => 'Changed after the admin loaded it',
            'approval_status' => 'pending',
            'is_published' => false,
            'rejection_reason' => null,
        ]);
        $this->assertDatabaseCount('announcement_approval_logs', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | Conditional event detail validation (B6)
    |--------------------------------------------------------------------------
    */

    public function test_clerk_event_requires_event_start_at(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->from('/clerk/announcements')
            ->post('/clerk/announcements', [
                'title' => 'Fiesta',
                'content' => 'Barangay fiesta',
                'category' => 'Event',
                'target_audience' => 'all',
                'location' => 'Barangay Covered Court',
            ])
            ->assertSessionHasErrors('event_start_at');

        $this->assertDatabaseCount('announcements', 0);
    }

    public function test_clerk_event_requires_location(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->from('/clerk/announcements')
            ->post('/clerk/announcements', [
                'title' => 'Fiesta',
                'content' => 'Barangay fiesta',
                'category' => 'Event',
                'target_audience' => 'all',
                'event_start_at' => '2026-11-01T09:00:00',
            ])
            ->assertSessionHasErrors('location');

        $this->assertDatabaseCount('announcements', 0);
    }

    public function test_attendance_required_announcement_requires_event_start_at(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->from('/clerk/announcements')
            ->post('/clerk/announcements', [
                'title' => 'Relief Distribution',
                'content' => 'Relief goods for residents',
                'category' => 'Relief Goods',
                'target_audience' => 'all',
                'requires_attendance' => true,
                'location' => 'Barangay Covered Court',
            ])
            ->assertSessionHasErrors('event_start_at');

        $this->assertDatabaseCount('announcements', 0);
    }

    public function test_attendance_required_announcement_requires_location(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->from('/clerk/announcements')
            ->post('/clerk/announcements', [
                'title' => 'Relief Distribution',
                'content' => 'Relief goods for residents',
                'category' => 'Relief Goods',
                'target_audience' => 'all',
                'requires_attendance' => true,
                'event_start_at' => '2026-11-01T09:00:00',
            ])
            ->assertSessionHasErrors('location');

        $this->assertDatabaseCount('announcements', 0);
    }

    public function test_ordinary_notice_without_event_fields_is_accepted(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->post('/clerk/announcements', [
                'title' => 'General Notice',
                'content' => 'A purely informational notice.',
                'category' => 'General',
                'target_audience' => 'all',
                'requires_attendance' => false,
                'event_start_at' => '',
                'location' => '',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('announcements', [
            'barangay_id' => $this->barangay->id,
            'title' => 'General Notice',
            'category' => 'General',
            'approval_status' => 'pending',
            'is_published' => false,
            'event_start_at' => null,
            'location' => null,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Senior / PWD event date submission (B5)
    |--------------------------------------------------------------------------
    */

    public function test_senior_event_date_and_location_are_saved(): void
    {
        $staff = $this->createStaff($this->barangay, 'senior_staff');

        $this->actingAs($staff)
            ->post('/senior/announcements', [
                'title' => 'Senior Assembly',
                'content' => 'Quarterly senior assembly.',
                'category' => 'Event',
                'event_start_at' => '2026-11-15T08:30:00',
                'location' => 'Barangay Hall',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $announcement = Announcement::query()->where('title', 'Senior Assembly')->firstOrFail();

        $this->assertSame('senior_citizens', $announcement->target_audience);
        $this->assertSame('pending', $announcement->approval_status);
        $this->assertFalse($announcement->is_published);
        $this->assertSame('Barangay Hall', $announcement->location);
        $this->assertNotNull($announcement->event_start_at);
        $this->assertSame('2026-11-15 08:30:00', $announcement->event_start_at->format('Y-m-d H:i:s'));
    }

    public function test_pwd_event_date_and_location_are_saved(): void
    {
        $staff = $this->createStaff($this->barangay, 'pwd_staff');

        $this->actingAs($staff)
            ->post('/pwd/announcements', [
                'title' => 'PWD Assembly',
                'content' => 'Quarterly PWD assembly.',
                'category' => 'Event',
                'event_start_at' => '2026-12-05T13:00:00',
                'location' => 'Barangay Gym',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $announcement = Announcement::query()->where('title', 'PWD Assembly')->firstOrFail();

        $this->assertSame('pwd', $announcement->target_audience);
        $this->assertSame('pending', $announcement->approval_status);
        $this->assertFalse($announcement->is_published);
        $this->assertSame('Barangay Gym', $announcement->location);
        $this->assertNotNull($announcement->event_start_at);
        $this->assertSame('2026-12-05 13:00:00', $announcement->event_start_at->format('Y-m-d H:i:s'));
    }

    public function test_senior_event_without_event_start_at_is_rejected(): void
    {
        $staff = $this->createStaff($this->barangay, 'senior_staff');

        $this->actingAs($staff)
            ->from('/senior/announcements')
            ->post('/senior/announcements', [
                'title' => 'Senior Assembly',
                'content' => 'Quarterly senior assembly.',
                'category' => 'Event',
                'location' => 'Barangay Hall',
            ])
            ->assertSessionHasErrors('event_start_at');

        $this->assertDatabaseCount('announcements', 0);
    }

    public function test_pwd_event_without_location_is_rejected(): void
    {
        $staff = $this->createStaff($this->barangay, 'pwd_staff');

        $this->actingAs($staff)
            ->from('/pwd/announcements')
            ->post('/pwd/announcements', [
                'title' => 'PWD Assembly',
                'content' => 'Quarterly PWD assembly.',
                'category' => 'Event',
                'event_start_at' => '2026-12-05T13:00:00',
            ])
            ->assertSessionHasErrors('location');

        $this->assertDatabaseCount('announcements', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | Conditional event detail validation on update
    |--------------------------------------------------------------------------
    */

    public function test_clerk_update_requires_event_details_when_categorised_as_event(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $announcement = $this->createAnnouncement($this->barangay, [
            'category' => 'General',
            'target_audience' => 'all',
            'approval_status' => 'approved',
            'is_published' => true,
        ]);

        $this->actingAs($clerk)
            ->from('/clerk/announcements')
            ->put('/clerk/announcements/'.$announcement->id, [
                'title' => 'Now an Event',
                'content' => 'Changed to an event.',
                'category' => 'Event',
                'target_audience' => 'all',
            ])
            ->assertSessionHasErrors(['event_start_at', 'location']);

        $this->assertDatabaseHas('announcements', [
            'id' => $announcement->id,
            'category' => 'General',
            'approval_status' => 'approved',
            'is_published' => true,
        ]);
    }

    public function test_senior_update_requires_event_details_when_attendance_is_required(): void
    {
        $staff = $this->createStaff($this->barangay, 'senior_staff');
        $announcement = $this->createAnnouncement($this->barangay, [
            'category' => 'General',
            'target_audience' => 'senior_citizens',
        ]);

        $this->actingAs($staff)
            ->from('/senior/announcements')
            ->put('/senior/announcements/'.$announcement->id, [
                'title' => 'Attendance required',
                'content' => 'Now needs attendance.',
                'category' => 'General',
                'requires_attendance' => true,
            ])
            ->assertSessionHasErrors(['event_start_at', 'location']);

        $this->assertDatabaseHas('announcements', [
            'id' => $announcement->id,
            'requires_attendance' => false,
        ]);
    }

    public function test_pwd_update_with_event_fields_is_accepted_and_resubmitted(): void
    {
        $staff = $this->createStaff($this->barangay, 'pwd_staff');
        $announcement = $this->createAnnouncement($this->barangay, [
            'category' => 'General',
            'target_audience' => 'pwd',
            'approval_status' => 'approved',
            'is_published' => true,
        ]);

        $this->actingAs($staff)
            ->put('/pwd/announcements/'.$announcement->id, [
                'title' => 'PWD Event',
                'content' => 'Now an event with a venue.',
                'category' => 'Event',
                'event_start_at' => '2026-12-05T13:00:00',
                'location' => 'Barangay Gym',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('announcements', [
            'id' => $announcement->id,
            'category' => 'Event',
            'location' => 'Barangay Gym',
            'approval_status' => 'pending',
            'is_published' => false,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Edit-modal date prefill contract (B5)
    |--------------------------------------------------------------------------
    */

    public function test_event_start_at_is_serialized_for_datetime_local_prefill(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->createAnnouncement($this->barangay, [
            'title' => 'With Event Date',
            'event_start_at' => '2026-11-15 08:30:00',
        ]);
        $this->createAnnouncement($this->barangay, [
            'title' => 'Without Event Date',
            'event_start_at' => null,
        ]);

        $this->actingAs($clerk)
            ->get('/clerk/announcements')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Clerk/Announcements/Index')
                ->where('announcements', function ($announcements) {
                    $announcements = collect($announcements);
                    $withDate = $announcements->firstWhere('title', 'With Event Date');
                    $withoutDate = $announcements->firstWhere('title', 'Without Event Date');

                    // The edit modal slices the leading wall-clock components
                    // into the datetime-local value; a null date stays null so
                    // the input renders empty.
                    return $withDate !== null
                        && str_starts_with((string) $withDate['event_start_at'], '2026-11-15T08:30')
                        && $withoutDate !== null
                        && $withoutDate['event_start_at'] === null;
                }));
    }

    public function test_new_events_default_to_attendance_disabled_across_staff_portals(): void
    {
        $portals = [
            ['role' => 'clerk', 'url' => '/clerk/announcements', 'audience' => 'all', 'extra' => ['target_audience' => 'all']],
            ['role' => 'pwd_staff', 'url' => '/pwd/announcements', 'audience' => 'pwd', 'extra' => []],
            ['role' => 'senior_staff', 'url' => '/senior/announcements', 'audience' => 'senior_citizens', 'extra' => []],
        ];

        foreach ($portals as $portal) {
            $staff = $this->createStaff($this->barangay, $portal['role']);

            $this->actingAs($staff)
                ->post($portal['url'], [
                    'title' => 'Default Attendance '.$portal['role'],
                    'content' => 'Event without attendance tracking.',
                    'category' => 'Event',
                    'event_start_at' => '2026-11-15T08:30:00',
                    'location' => 'Barangay Hall',
                    ...$portal['extra'],
                ])
                ->assertSessionHasNoErrors()
                ->assertRedirect();

            $announcement = Announcement::query()
                ->where('title', 'Default Attendance '.$portal['role'])
                ->firstOrFail();

            $this->assertFalse($announcement->requires_attendance);
            $this->assertSame($portal['audience'], $announcement->target_audience);
            $this->assertSame($this->barangay->id, $announcement->barangay_id);
        }
    }

    public function test_explicit_attendance_setting_is_persisted_across_staff_portals(): void
    {
        $portals = [
            ['role' => 'clerk', 'url' => '/clerk/announcements', 'audience' => 'all', 'extra' => ['target_audience' => 'all']],
            ['role' => 'pwd_staff', 'url' => '/pwd/announcements', 'audience' => 'pwd', 'extra' => []],
            ['role' => 'senior_staff', 'url' => '/senior/announcements', 'audience' => 'senior_citizens', 'extra' => []],
        ];

        foreach ($portals as $portal) {
            $staff = $this->createStaff($this->barangay, $portal['role']);

            $this->actingAs($staff)
                ->post($portal['url'], [
                    'title' => 'Enabled Attendance '.$portal['role'],
                    'content' => 'Event with attendance tracking.',
                    'category' => 'Event',
                    'requires_attendance' => true,
                    'event_start_at' => '2026-11-15T08:30:00',
                    'location' => 'Barangay Hall',
                    ...$portal['extra'],
                ])
                ->assertSessionHasNoErrors()
                ->assertRedirect();

            $announcement = Announcement::query()
                ->where('title', 'Enabled Attendance '.$portal['role'])
                ->firstOrFail();

            $this->assertTrue($announcement->requires_attendance);
            $this->assertSame($portal['audience'], $announcement->target_audience);
        }
    }

    public function test_editing_unrelated_fields_preserves_attendance_setting(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $announcement = $this->createAnnouncement($this->barangay, [
            'title' => 'Attendance Event',
            'category' => 'Event',
            'target_audience' => 'all',
            'requires_attendance' => true,
            'event_start_at' => '2026-11-15 08:30:00',
            'location' => 'Barangay Hall',
        ]);

        $this->actingAs($clerk)
            ->put('/clerk/announcements/'.$announcement->id, [
                'title' => 'Attendance Event Updated',
                'content' => 'Updated title and content only.',
                'category' => 'Event',
                'target_audience' => 'all',
                'event_start_at' => '2026-11-15T08:30:00',
                'location' => 'Barangay Hall',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $announcement->refresh();

        $this->assertSame('Attendance Event Updated', $announcement->title);
        $this->assertTrue($announcement->requires_attendance);
        $this->assertSame('pending', $announcement->approval_status);
    }

    public function test_editing_event_to_informational_does_not_require_event_fields(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $announcement = $this->createAnnouncement($this->barangay, [
            'title' => 'Attendance Event',
            'category' => 'Event',
            'target_audience' => 'all',
            'approval_status' => 'approved',
            'is_published' => true,
            'requires_attendance' => true,
            'event_start_at' => '2026-11-15 08:30:00',
            'location' => 'Barangay Hall',
        ]);

        $this->actingAs($clerk)
            ->put('/clerk/announcements/'.$announcement->id, [
                'title' => 'General Update',
                'content' => 'No longer an event.',
                'category' => 'General',
                'target_audience' => 'all',
                'requires_attendance' => false,
                'event_start_at' => '',
                'location' => '',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $announcement->refresh();

        $this->assertSame('General', $announcement->category);
        $this->assertFalse($announcement->requires_attendance);
        $this->assertNull($announcement->event_start_at);
        $this->assertNull($announcement->location);
        $this->assertSame('pending', $announcement->approval_status);
        $this->assertFalse($announcement->is_published);
    }

    public function test_sector_staff_update_cannot_switch_audience_or_barangay(): void
    {
        $portals = [
            ['role' => 'pwd_staff', 'url' => '/pwd/announcements', 'audience' => 'pwd'],
            ['role' => 'senior_staff', 'url' => '/senior/announcements', 'audience' => 'senior_citizens'],
        ];

        foreach ($portals as $portal) {
            $staff = $this->createStaff($this->barangay, $portal['role']);
            $announcement = $this->createAnnouncement($this->barangay, [
                'title' => 'Scoped '.$portal['role'],
                'content' => 'Original content.',
                'category' => 'General',
                'target_audience' => $portal['audience'],
                'approval_status' => 'approved',
                'is_published' => true,
            ]);

            $this->actingAs($staff)
                ->put($portal['url'].'/'.$announcement->id, [
                    'title' => 'Scoped '.$portal['role'].' Updated',
                    'content' => 'Updated content.',
                    'category' => 'General',
                    'target_audience' => $portal['audience'] === 'pwd' ? 'senior_citizens' : 'pwd',
                    'barangay_id' => $this->other->id,
                ])
                ->assertSessionHasNoErrors()
                ->assertRedirect();

            $announcement->refresh();

            $this->assertSame($portal['audience'], $announcement->target_audience);
            $this->assertSame($this->barangay->id, $announcement->barangay_id);
            $this->assertSame('pending', $announcement->approval_status);
            $this->assertFalse($announcement->is_published);
        }
    }

    public function test_clerk_update_route_publishes_immediately(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)->post('/clerk/updates', [
            'title' => 'Road Closure Notice',
            'content' => 'Purok 1 road is closed for repairs.',
            'category' => 'General',
            'target_audience' => 'all',
            'requires_attendance' => false,
        ])->assertRedirect();

        $announcement = Announcement::query()->latest('id')->firstOrFail();

        $this->assertSame('approved', $announcement->approval_status);
        $this->assertTrue($announcement->is_published);
        $this->assertSame('General', $announcement->category);
        $this->assertSame($clerk->id, $announcement->reviewed_by);

        $this->assertDatabaseHas('announcement_approval_logs', [
            'announcement_id' => $announcement->id,
            'action' => 'approved',
            'new_status' => 'approved',
        ]);
    }

    public function test_pwd_and_senior_update_routes_preserve_direct_publishing_behavior(): void
    {
        foreach ([
            ['role' => 'pwd_staff', 'path' => '/pwd/updates', 'audience' => 'pwd'],
            ['role' => 'senior_staff', 'path' => '/senior/updates', 'audience' => 'senior_citizens'],
        ] as $portal) {
            $staff = $this->createStaff($this->barangay, $portal['role']);

            $this->actingAs($staff)->post($portal['path'], [
                'title' => 'Sector update',
                'content' => 'Informational post content.',
                'category' => 'General',
                'requires_attendance' => false,
            ])->assertRedirect();

            $announcement = Announcement::query()->latest('id')->firstOrFail();

            $this->assertSame($portal['audience'], $announcement->target_audience);
            $this->assertSame('approved', $announcement->approval_status);
            $this->assertTrue($announcement->is_published);
            $this->assertSame($staff->id, $announcement->reviewed_by);
        }
    }

    public function test_clerk_program_route_forces_event_details_and_awaits_approval(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)->post('/clerk/programs', [
            'title' => 'Senior Vaccine Drive',
            'content' => 'Free vaccination for senior citizens.',
            'category' => 'General',
            'target_audience' => 'all',
            'requires_attendance' => true,
            'event_start_at' => now()->addWeek()->toDateTimeString(),
            'location' => 'Barangay Covered Court',
        ])->assertRedirect();

        $announcement = Announcement::query()->latest('id')->firstOrFail();

        $this->assertSame('Event', $announcement->category);
        $this->assertSame('pending', $announcement->approval_status);
        $this->assertFalse($announcement->is_published);
        $this->assertTrue($announcement->requires_attendance);
    }

    public function test_update_show_route_preserves_update_context(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $announcement = $this->createAnnouncement($this->barangay, [
            'title' => 'Update Detail',
            'category' => 'General',
            'requires_attendance' => false,
            'approval_status' => 'approved',
            'is_published' => true,
        ]);

        $this->actingAs($clerk)
            ->get('/clerk/updates/'.$announcement->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Clerk/Announcements/Show')
                ->where('kind', 'update')
                ->where('announcement.id', $announcement->id));
    }

    public function test_update_and_program_feeds_are_separated(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->createAnnouncement($this->barangay, [
            'title' => 'Informational Update',
            'category' => 'General',
            'requires_attendance' => false,
        ]);
        $this->createAnnouncement($this->barangay, [
            'title' => 'Community Program',
            'category' => 'Event',
            'requires_attendance' => true,
        ]);

        $this->actingAs($clerk)
            ->get('/clerk/updates')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Clerk/Announcements/Index')
                ->has('announcements', 1)
                ->where('announcements.0.title', 'Informational Update'));

        $this->actingAs($clerk)
            ->get('/clerk/programs')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Clerk/Announcements/Index')
                ->has('announcements', 1)
                ->where('announcements.0.title', 'Community Program'));
    }
}
