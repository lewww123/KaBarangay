<?php

namespace Tests\Feature;

use App\Models\Announcement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class ProgramPublicationWorkflowTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    public static function portals(): array
    {
        return [['clerk', 'clerk', 'all'], ['pwd', 'pwd_staff', 'pwd'], ['senior', 'senior_staff', 'senior_citizens']];
    }

    #[DataProvider('portals')]
    public function test_program_can_move_from_draft_to_direct_publication_to_done_without_admin_review(string $portal, string $role, string $audience): void
    {
        $this->withoutVite();
        $barangay = $this->createBarangay('Program Workflow', 'PROGRAMS');
        $staff = $this->createStaff($barangay, $role);
        $payload = [
            'title' => 'Community Program', 'content' => 'Program details', 'category' => 'Event',
            'target_audience' => $audience, 'requires_attendance' => true,
            'event_start_at' => now()->addDay()->toDateTimeString(), 'location' => 'Barangay Hall',
            'program_status' => 'draft',
        ];
        $this->actingAs($staff)->post('/'.$portal.'/programs', $payload)->assertSessionHasNoErrors();
        $program = Announcement::firstOrFail();
        $this->assertSame('draft', $program->program_status);
        $this->assertFalse($program->is_published);
        $this->assertFalse($program->attendanceIsAvailable());
        $this->assertSame($audience, $program->target_audience);

        $this->put('/'.$portal.'/programs/'.$program->id, [...$payload, 'program_status' => 'ongoing'])->assertSessionHasNoErrors();
        $program->refresh();
        $this->assertSame('ongoing', $program->program_status);
        $this->assertTrue($program->is_published);
        $this->assertTrue($program->attendanceIsAvailable());
        $this->assertNull($program->reviewed_by);
        $this->assertDatabaseMissing('announcement_approval_logs', ['announcement_id' => $program->id, 'action' => 'created']);

        $this->post('/'.$portal.'/announcements/'.$program->id.'/complete')->assertSessionHasNoErrors();
        $this->assertSame('done', $program->fresh()->program_status);
        $this->assertNotNull($program->fresh()->completed_at);
        $this->put('/'.$portal.'/programs/'.$program->id, $payload)->assertSessionHasErrors('announcement');
    }

    #[DataProvider('portals')]
    public function test_programs_publish_immediately_by_default_and_reject_approval_statuses(string $portal, string $role, string $audience): void
    {
        $this->withoutVite();
        $barangay = $this->createBarangay('Program Workflow', 'PROGRAMS');
        $staff = $this->createStaff($barangay, $role);
        $payload = [
            'title' => 'Direct Program', 'content' => 'Program details', 'category' => 'Event',
            'target_audience' => $audience, 'event_start_at' => now()->addDay()->toDateTimeString(), 'location' => 'Barangay Hall',
        ];
        $this->actingAs($staff)->post('/'.$portal.'/programs', [...$payload, 'program_status' => 'pending'])
            ->assertSessionHasErrors('program_status');
        $this->assertDatabaseCount('announcements', 0);
        $this->post('/'.$portal.'/programs', $payload)->assertSessionHasNoErrors();
        $program = Announcement::firstOrFail();
        $this->assertSame('ongoing', $program->program_status);
        $this->assertTrue($program->is_published);

        $admin = $this->createStaff($barangay, 'admin');
        $this->actingAs($admin)->post('/admin/announcements/'.$program->id.'/reject', [
            'rejection_reason' => 'Not an approval workflow', 'revision_token' => $program->revisionToken(),
        ])->assertSessionHasErrors('approval');
        $this->assertSame('ongoing', $program->fresh()->program_status);
        $this->assertTrue($program->fresh()->is_published);
    }
}
