<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Barangay;
use App\Models\Purok;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

/**
 * Batch B audit coverage: unified announcement lifecycle events.
 *
 * These tests exercise the AuditLogger call sites added for
 * announcement.created / resubmitted / approved / rejected / deleted while
 * confirming the announcement-scoped approval log keeps its existing
 * behaviour.
 */
class AnnouncementAuditEventsTest extends TestCase
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

    /**
     * Read audit rows through the query builder so the AuditLog model's
     * barangay global scope cannot hide rows from these assertions.
     */
    private function rows(): Collection
    {
        return DB::table('audit_logs')->orderBy('id')->get();
    }

    /*
    |--------------------------------------------------------------------------
    | announcement.created (all three staff desks)
    |--------------------------------------------------------------------------
    */

    public function test_clerk_creation_records_created_event_and_approval_log(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)->post('/clerk/announcements', [
            'title' => 'Secret Titular Heading',
            'content' => 'Internal draft body',
            'category' => 'General',
            'target_audience' => 'all',
        ])->assertRedirect();

        $announcement = Announcement::query()->firstOrFail();

        $rows = $this->rows();

        $this->assertCount(1, $rows);

        $row = $rows->first();

        $this->assertSame('announcement.created', $row->event);
        $this->assertSame($clerk->id, (int) $row->actor_id);
        $this->assertSame($this->barangay->id, (int) $row->barangay_id);
        $this->assertSame('announcement', $row->entity_type);
        $this->assertSame($announcement->id, (int) $row->entity_id);
        $this->assertSame(
            ['new_status' => 'pending'],
            json_decode($row->context, true)
        );
        $this->assertSame('127.0.0.1', $row->ip_address);

        $serialized = json_encode((array) $row);

        foreach (['Secret Titular Heading', 'Internal draft body'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }

        $this->assertDatabaseHas('announcement_approval_logs', [
            'announcement_id' => $announcement->id,
            'actor_id' => $clerk->id,
            'action' => 'created',
            'previous_status' => null,
            'new_status' => 'pending',
        ]);
    }

    public function test_senior_desk_creation_records_created_event(): void
    {
        $senior = $this->createStaff($this->barangay, 'senior');

        $this->actingAs($senior)->post('/senior/announcements', [
            'title' => 'Senior Desk Notice',
            'content' => 'Content',
            'category' => 'Event',
            'event_start_at' => '2026-11-01T09:00:00',
            'location' => 'Barangay Covered Court',
        ])->assertRedirect();

        $announcement = Announcement::query()->firstOrFail();

        $row = $this->rows()->first();

        $this->assertSame('program.published', $row->event);
        $this->assertSame('senior_citizens', $announcement->target_audience);
        $this->assertSame($senior->id, (int) $row->actor_id);
        $this->assertSame($announcement->id, (int) $row->entity_id);
    }

    public function test_pwd_desk_creation_records_created_event(): void
    {
        $pwd = $this->createStaff($this->barangay, 'pwd');

        $this->actingAs($pwd)->post('/pwd/announcements', [
            'title' => 'PWD Desk Notice',
            'content' => 'Content',
            'category' => 'Event',
            'event_start_at' => '2026-11-01T09:00:00',
            'location' => 'Barangay Covered Court',
        ])->assertRedirect();

        $announcement = Announcement::query()->firstOrFail();

        $row = $this->rows()->first();

        $this->assertSame('program.published', $row->event);
        $this->assertSame('pwd', $announcement->target_audience);
        $this->assertSame($pwd->id, (int) $row->actor_id);
    }

    /*
    |--------------------------------------------------------------------------
    | announcement.resubmitted
    |--------------------------------------------------------------------------
    */

    public function test_update_records_resubmitted_event_with_actual_transition(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $announcement = $this->createAnnouncement($this->barangay, [
            'approval_status' => 'approved',
            'is_published' => true,
        ]);

        $this->actingAs($clerk)->put('/clerk/announcements/'.$announcement->id, [
            'title' => 'Resubmitted',
            'content' => 'Content',
            'category' => 'General',
            'target_audience' => 'all',
        ])->assertRedirect();

        $rows = $this->rows();

        $this->assertCount(1, $rows);

        $row = $rows->first();

        $this->assertSame('announcement.resubmitted', $row->event);
        $this->assertSame($clerk->id, (int) $row->actor_id);
        $this->assertSame($announcement->id, (int) $row->entity_id);
        $this->assertSame(
            ['previous_status' => 'approved', 'new_status' => 'pending'],
            json_decode($row->context, true)
        );

        $this->assertDatabaseHas('announcement_approval_logs', [
            'announcement_id' => $announcement->id,
            'action' => 'resubmitted',
            'previous_status' => 'approved',
            'new_status' => 'pending',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | announcement.approved / rejected (admin review)
    |--------------------------------------------------------------------------
    */

    public function test_admin_approval_records_approved_event_and_approval_log(): void
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

        $row = $this->rows()->first();

        $this->assertSame('announcement.approved', $row->event);
        $this->assertSame($admin->id, (int) $row->actor_id);
        $this->assertSame($this->barangay->id, (int) $row->barangay_id);
        $this->assertSame($announcement->id, (int) $row->entity_id);
        $this->assertSame(
            ['previous_status' => 'pending', 'new_status' => 'approved'],
            json_decode($row->context, true)
        );

        $this->assertDatabaseHas('announcement_approval_logs', [
            'announcement_id' => $announcement->id,
            'actor_id' => $admin->id,
            'action' => 'approved',
            'previous_status' => 'pending',
            'new_status' => 'approved',
        ]);
    }

    public function test_admin_rejection_records_rejected_event_without_free_text(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $announcement = $this->createAnnouncement($this->barangay, [
            'title' => 'Secret Titular Heading',
            'content' => 'Internal draft body',
            'approval_status' => 'pending',
            'is_published' => false,
        ]);

        $this->actingAs($admin)
            ->post('/admin/announcements/'.$announcement->id.'/reject', [
                'revision_token' => $this->revisionToken($announcement),
                'rejection_reason' => 'Please clarify the venue details.',
            ])
            ->assertRedirect();

        $row = $this->rows()->first();

        $this->assertSame('announcement.rejected', $row->event);
        $this->assertSame($admin->id, (int) $row->actor_id);
        $this->assertSame(
            ['previous_status' => 'pending', 'new_status' => 'rejected'],
            json_decode($row->context, true)
        );

        $serialized = json_encode((array) $row);

        foreach ([
            'Please clarify the venue details.',
            'Secret Titular Heading',
            'Internal draft body',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }

        // The rejection reason is still retained by the announcement-scoped
        // approval log; only the unified audit context excludes free text.
        $this->assertDatabaseHas('announcement_approval_logs', [
            'announcement_id' => $announcement->id,
            'action' => 'rejected',
            'notes' => 'Please clarify the venue details.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | announcement.deleted
    |--------------------------------------------------------------------------
    */

    public function test_deletion_records_deleted_event_that_survives_the_announcement(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)->post('/clerk/announcements', [
            'title' => 'To be deleted',
            'content' => 'Content',
            'category' => 'General',
            'target_audience' => 'all',
        ])->assertRedirect();

        $announcement = Announcement::query()->firstOrFail();

        $this->actingAs($clerk)
            ->delete('/clerk/announcements/'.$announcement->id)
            ->assertRedirect();

        $this->assertDatabaseMissing('announcements', ['id' => $announcement->id]);

        // Approval history is cascade-deleted with the announcement...
        $this->assertDatabaseMissing('announcement_approval_logs', [
            'announcement_id' => $announcement->id,
        ]);

        // ...but the unified audit rows survive (no foreign key on entity_id).
        $rows = $this->rows()
            ->where('entity_id', $announcement->id)
            ->values();

        $this->assertCount(2, $rows); // created + deleted
        $this->assertSame('announcement.created', $rows[0]->event);

        $deleted = $rows[1];

        $this->assertSame('announcement.deleted', $deleted->event);
        $this->assertSame($clerk->id, (int) $deleted->actor_id);
        $this->assertSame($this->barangay->id, (int) $deleted->barangay_id);
        $this->assertSame('announcement', $deleted->entity_type);
        $this->assertSame(
            ['previous_status' => 'pending'],
            json_decode($deleted->context, true)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | No audit event for failed / unauthorized operations
    |--------------------------------------------------------------------------
    */

    public function test_failed_validation_records_nothing(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)->post('/clerk/announcements', [
            'content' => 'Missing title',
            'category' => 'General',
            'target_audience' => 'all',
        ])->assertSessionHasErrors('title');

        $this->assertSame(0, DB::table('audit_logs')->count());
        $this->assertSame(0, DB::table('announcement_approval_logs')->count());
    }

    public function test_invalid_approval_transition_records_nothing(): void
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

        $this->assertSame(0, DB::table('audit_logs')->count());
        $this->assertSame(0, DB::table('announcement_approval_logs')->count());

        $this->assertDatabaseHas('announcements', [
            'id' => $announcement->id,
            'approval_status' => 'approved',
            'is_published' => true,
        ]);
    }

    public function test_stale_approval_records_nothing(): void
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
            ->post('/admin/announcements/'.$announcement->id.'/approve', [
                'revision_token' => $token,
            ])
            ->assertSessionHasErrors('approval');

        $this->assertSame(0, DB::table('audit_logs')->count());
        $this->assertSame(0, DB::table('announcement_approval_logs')->count());

        $this->assertDatabaseHas('announcements', [
            'id' => $announcement->id,
            'title' => 'Changed after the admin loaded it',
            'approval_status' => 'pending',
            'is_published' => false,
        ]);
    }

    public function test_cross_barangay_delete_records_nothing(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $foreign = $this->createAnnouncement($this->other, [
            'title' => 'Foreign Announcement',
        ]);

        $this->actingAs($clerk)
            ->delete('/clerk/announcements/'.$foreign->id)
            ->assertStatus(404);

        $this->assertSame(0, DB::table('audit_logs')->count());

        $this->assertDatabaseHas('announcements', [
            'id' => $foreign->id,
            'barangay_id' => $this->other->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Seniors' desk lifecycle coverage (Batch B1)
    |--------------------------------------------------------------------------
    */

    public function test_senior_update_records_resubmitted_event(): void
    {
        $senior = $this->createStaff($this->barangay, 'senior');

        $announcement = $this->createAnnouncement($this->barangay, [
            'target_audience' => 'senior_citizens',
            'approval_status' => 'approved',
            'is_published' => true,
        ]);

        $this->actingAs($senior)->put('/senior/announcements/'.$announcement->id, [
            'title' => 'Resubmitted Senior Notice',
            'content' => 'Content',
            'category' => 'Event',
            'event_start_at' => '2026-11-01T09:00:00',
            'location' => 'Barangay Covered Court',
        ])->assertRedirect();

        $rows = $this->rows();

        $this->assertCount(1, $rows);

        $row = $rows->first();

        $this->assertSame('program.published', $row->event);
        $this->assertSame($senior->id, (int) $row->actor_id);
        $this->assertSame($this->barangay->id, (int) $row->barangay_id);
        $this->assertSame('announcement', $row->entity_type);
        $this->assertSame($announcement->id, (int) $row->entity_id);
        $this->assertSame(
            ['new_status' => 'ongoing'],
            json_decode($row->context, true)
        );
        $this->assertDatabaseMissing('announcement_approval_logs', ['announcement_id' => $announcement->id, 'action' => 'resubmitted']);
    }

    public function test_senior_deletion_records_deleted_event(): void
    {
        $senior = $this->createStaff($this->barangay, 'senior');

        $announcement = $this->createAnnouncement($this->barangay, [
            'target_audience' => 'senior_citizens',
            'approval_status' => 'approved',
            'is_published' => true,
        ]);

        $this->actingAs($senior)
            ->delete('/senior/announcements/'.$announcement->id)
            ->assertRedirect();

        $this->assertDatabaseMissing('announcements', ['id' => $announcement->id]);

        $rows = $this->rows()
            ->where('entity_id', $announcement->id)
            ->values();

        $this->assertCount(1, $rows);

        $deleted = $rows[0];

        $this->assertSame('announcement.deleted', $deleted->event);
        $this->assertSame($senior->id, (int) $deleted->actor_id);
        $this->assertSame($this->barangay->id, (int) $deleted->barangay_id);
        $this->assertSame('announcement', $deleted->entity_type);
        $this->assertSame($announcement->id, (int) $deleted->entity_id);
        $this->assertSame(
            ['previous_status' => 'approved'],
            json_decode($deleted->context, true)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PWD desk lifecycle coverage (Batch B1)
    |--------------------------------------------------------------------------
    */

    public function test_pwd_update_records_resubmitted_event(): void
    {
        $pwd = $this->createStaff($this->barangay, 'pwd');

        $announcement = $this->createAnnouncement($this->barangay, [
            'target_audience' => 'pwd',
            'approval_status' => 'approved',
            'is_published' => true,
        ]);

        $this->actingAs($pwd)->put('/pwd/announcements/'.$announcement->id, [
            'title' => 'Resubmitted PWD Notice',
            'content' => 'Content',
            'category' => 'Event',
            'event_start_at' => '2026-11-01T09:00:00',
            'location' => 'Barangay Covered Court',
        ])->assertRedirect();

        $rows = $this->rows();

        $this->assertCount(1, $rows);

        $row = $rows->first();

        $this->assertSame('program.published', $row->event);
        $this->assertSame($pwd->id, (int) $row->actor_id);
        $this->assertSame($this->barangay->id, (int) $row->barangay_id);
        $this->assertSame($announcement->id, (int) $row->entity_id);
        $this->assertSame(
            ['new_status' => 'ongoing'],
            json_decode($row->context, true)
        );
        $this->assertDatabaseMissing('announcement_approval_logs', ['announcement_id' => $announcement->id, 'action' => 'resubmitted']);
    }

    public function test_pwd_deletion_records_deleted_event(): void
    {
        $pwd = $this->createStaff($this->barangay, 'pwd');

        $announcement = $this->createAnnouncement($this->barangay, [
            'target_audience' => 'pwd',
            'approval_status' => 'approved',
            'is_published' => true,
        ]);

        $this->actingAs($pwd)
            ->delete('/pwd/announcements/'.$announcement->id)
            ->assertRedirect();

        $this->assertDatabaseMissing('announcements', ['id' => $announcement->id]);

        $rows = $this->rows()
            ->where('entity_id', $announcement->id)
            ->values();

        $this->assertCount(1, $rows);

        $deleted = $rows[0];

        $this->assertSame('announcement.deleted', $deleted->event);
        $this->assertSame($pwd->id, (int) $deleted->actor_id);
        $this->assertSame($this->barangay->id, (int) $deleted->barangay_id);
        $this->assertSame($announcement->id, (int) $deleted->entity_id);
        $this->assertSame(
            ['previous_status' => 'approved'],
            json_decode($deleted->context, true)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Cross-barangay update denial (Batch B1)
    |--------------------------------------------------------------------------
    */

    public function test_cross_barangay_update_records_nothing_and_leaves_foreign_record_untouched(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $foreign = $this->createAnnouncement($this->other, [
            'title' => 'Foreign Announcement',
        ]);

        $this->actingAs($clerk)->put('/clerk/announcements/'.$foreign->id, [
            'title' => 'Hijacked',
            'content' => 'Content',
            'category' => 'General',
            'target_audience' => 'all',
        ])->assertStatus(404);

        $this->assertSame(0, DB::table('audit_logs')->count());
        $this->assertSame(0, DB::table('announcement_approval_logs')->count());

        $this->assertDatabaseHas('announcements', [
            'id' => $foreign->id,
            'barangay_id' => $this->other->id,
            'title' => 'Foreign Announcement',
            'approval_status' => 'approved',
            'is_published' => true,
        ]);
    }
}
