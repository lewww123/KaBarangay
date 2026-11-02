<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\OrgChartPosition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class OrgChartManagementTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private Barangay $barangay;

    private Barangay $otherBarangay;

    private User $clerk;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');

        $this->barangay = $this->createBarangay('Barangay Central', 'BRGY-CTR');
        $this->otherBarangay = $this->createBarangay('Barangay North', 'BRGY-NTH');
        $this->clerk = $this->createStaff($this->barangay, 'clerk');
        $this->admin = $this->createStaff($this->barangay, 'admin');
    }

    private function createPosition(
        Barangay $barangay,
        array $attributes = []
    ): OrgChartPosition {
        return OrgChartPosition::create([
            'barangay_id' => $barangay->id,
            'title' => 'Punong Barangay',
            'parent_id' => null,
            'status' => 'draft',
            'photo_path' => null,
            'created_by' => $this->clerk->id,
            ...$attributes,
        ]);
    }

    private function photoUpload(string $name = 'official.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            base64_decode(self::PNG_1X1, true)
        );
    }

    public function test_management_index_is_scoped_to_the_authenticated_barangay_and_reports_stats(): void
    {
        $root = $this->createPosition($this->barangay, [
            'title' => 'Punong Barangay',
            'status' => 'published',
        ]);
        $child = $this->createPosition($this->barangay, [
            'title' => 'Barangay Secretary',
            'parent_id' => $root->id,
            'status' => 'draft',
        ]);
        $this->createPosition($this->barangay, [
            'title' => 'Z Archived Role',
            'status' => 'archived',
        ]);
        $this->createPosition($this->otherBarangay, [
            'title' => 'Other Barangay Role',
            'status' => 'published',
        ]);

        $this->actingAs($this->clerk)
            ->get('/clerk/org-chart')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('OrgChart/Management')
                ->where('portal', 'clerk')
                ->where('stats.total', 3)
                ->where('stats.draft', 1)
                ->where('stats.published', 1)
                ->where('stats.archived', 1)
                ->has('positions', 3)
                ->has('tree', 2)
                ->has('tree.0.children', 1)
                ->where('tree.0.children.0.id', $child->id)
                ->missing('positions.0.photo_path')
                ->missing('positions.0.created_by'));

        $this->actingAs($this->admin)
            ->get('/admin/org-chart')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('OrgChart/Management')
                ->where('portal', 'admin'));
    }

    public function test_org_chart_routes_enforce_staff_roles(): void
    {
        $lupon = $this->createStaff($this->barangay, 'lupon');

        $this->actingAs($this->clerk)
            ->get('/admin/org-chart')
            ->assertForbidden();

        $this->actingAs($lupon)
            ->get('/clerk/org-chart')
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->get('/clerk/org-chart')
            ->assertForbidden();
    }

    public function test_creating_a_position_forces_draft_stores_a_private_photo_and_audits(): void
    {
        $response = $this->actingAs($this->clerk)
            ->from('/clerk/org-chart')
            ->post('/clerk/org-chart', [
                'title' => 'Barangay Treasurer',
                'parent_id' => '',
                'photo' => $this->photoUpload(),
            ]);

        $response->assertRedirect('/clerk/org-chart');
        $response->assertSessionHasNoErrors();

        $position = OrgChartPosition::query()->firstOrFail();

        $this->assertSame($this->barangay->id, $position->barangay_id);
        $this->assertSame($this->clerk->id, $position->created_by);
        $this->assertSame('draft', $position->status);
        $this->assertNull($position->parent_id);
        $this->assertNotNull($position->photo_path);
        $this->assertStringStartsWith(
            'org-chart/'.$this->barangay->id.'/',
            $position->photo_path
        );
        Storage::disk('local')->assertExists($position->photo_path);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'org_chart_position_created',
            'entity_type' => 'org_chart_position',
            'entity_id' => $position->id,
        ]);
    }

    public function test_position_cannot_be_parented_to_itself_or_a_descendant(): void
    {
        $root = $this->createPosition($this->barangay, [
            'title' => 'Punong Barangay',
        ]);
        $child = $this->createPosition($this->barangay, [
            'title' => 'Barangay Secretary',
            'parent_id' => $root->id,
        ]);
        $grandchild = $this->createPosition($this->barangay, [
            'title' => 'Records Officer',
            'parent_id' => $child->id,
        ]);

        $this->actingAs($this->clerk)
            ->from('/clerk/org-chart')
            ->put("/clerk/org-chart/{$root->id}", [
                'title' => 'Punong Barangay',
                'parent_id' => (string) $root->id,
            ])
            ->assertSessionHasErrors('parent_id');

        $this->actingAs($this->clerk)
            ->from('/clerk/org-chart')
            ->put("/clerk/org-chart/{$root->id}", [
                'title' => 'Punong Barangay',
                'parent_id' => (string) $grandchild->id,
            ])
            ->assertSessionHasErrors('parent_id');

        $this->assertNull($root->fresh()->parent_id);
    }

    public function test_parent_must_belong_to_the_same_barangay(): void
    {
        $other = $this->createPosition($this->otherBarangay);

        $this->actingAs($this->clerk)
            ->from('/clerk/org-chart')
            ->post('/clerk/org-chart', [
                'title' => 'Cross Tenant Child',
                'parent_id' => (string) $other->id,
            ])
            ->assertSessionHasErrors('parent_id');

        $this->assertDatabaseMissing('org_chart_positions', [
            'title' => 'Cross Tenant Child',
        ]);
    }

    public function test_updating_a_published_position_returns_it_to_draft_and_replaces_the_photo(): void
    {
        $oldPath = 'org-chart/'.$this->barangay->id.'/old.png';
        Storage::disk('local')->put($oldPath, base64_decode(self::PNG_1X1, true));

        $position = $this->createPosition($this->barangay, [
            'title' => 'Barangay Treasurer',
            'status' => 'published',
            'photo_path' => $oldPath,
        ]);

        $this->actingAs($this->clerk)
            ->from('/clerk/org-chart')
            ->put("/clerk/org-chart/{$position->id}", [
                'title' => 'Barangay Treasurer Updated',
                'parent_id' => '',
                'photo' => $this->photoUpload('replacement.png'),
            ])
            ->assertRedirect('/clerk/org-chart')
            ->assertSessionHasNoErrors();

        $position->refresh();

        $this->assertSame('Barangay Treasurer Updated', $position->title);
        $this->assertSame('draft', $position->status);
        $this->assertSame($this->clerk->id, $position->updated_by);
        $this->assertNotSame($oldPath, $position->photo_path);
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($position->photo_path);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'org_chart_position_updated',
            'entity_type' => 'org_chart_position',
            'entity_id' => $position->id,
        ]);
    }

    public function test_publish_and_archive_transitions_are_audited(): void
    {
        $position = $this->createPosition($this->barangay);

        $this->actingAs($this->clerk)
            ->from('/clerk/org-chart')
            ->post("/clerk/org-chart/{$position->id}/publish")
            ->assertRedirect('/clerk/org-chart');

        $this->assertSame('published', $position->fresh()->status);

        $this->actingAs($this->clerk)
            ->from('/clerk/org-chart')
            ->post("/clerk/org-chart/{$position->id}/archive")
            ->assertRedirect('/clerk/org-chart');

        $this->assertSame('archived', $position->fresh()->status);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'org_chart_position_published',
            'entity_id' => $position->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'org_chart_position_archived',
            'entity_id' => $position->id,
        ]);
    }

    public function test_position_with_children_cannot_be_deleted_and_leaf_deletion_removes_the_photo(): void
    {
        $photoPath = 'org-chart/'.$this->barangay->id.'/parent.png';
        Storage::disk('local')->put($photoPath, base64_decode(self::PNG_1X1, true));

        $root = $this->createPosition($this->barangay, [
            'title' => 'Punong Barangay',
            'photo_path' => $photoPath,
        ]);
        $child = $this->createPosition($this->barangay, [
            'title' => 'Barangay Secretary',
            'parent_id' => $root->id,
        ]);

        $this->actingAs($this->clerk)
            ->from('/clerk/org-chart')
            ->delete("/clerk/org-chart/{$root->id}")
            ->assertSessionHas('error');

        $this->assertDatabaseHas('org_chart_positions', ['id' => $root->id]);
        Storage::disk('local')->assertExists($photoPath);

        $this->actingAs($this->clerk)
            ->delete("/clerk/org-chart/{$child->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('org_chart_positions', ['id' => $child->id]);

        $this->actingAs($this->clerk)
            ->delete("/clerk/org-chart/{$root->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('org_chart_positions', ['id' => $root->id]);
        Storage::disk('local')->assertMissing($photoPath);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'org_chart_position_deleted',
            'entity_id' => $root->id,
        ]);
    }

    public function test_other_barangay_positions_cannot_be_modified_or_viewed(): void
    {
        $photoPath = 'org-chart/'.$this->otherBarangay->id.'/other.png';
        Storage::disk('local')->put($photoPath, base64_decode(self::PNG_1X1, true));

        $position = $this->createPosition($this->otherBarangay, [
            'title' => 'Other Barangay Role',
            'photo_path' => $photoPath,
        ]);

        $this->actingAs($this->clerk)
            ->put("/clerk/org-chart/{$position->id}", ['title' => 'Hijacked'])
            ->assertForbidden();

        $this->actingAs($this->clerk)
            ->post("/clerk/org-chart/{$position->id}/publish")
            ->assertForbidden();

        $this->actingAs($this->clerk)
            ->post("/clerk/org-chart/{$position->id}/archive")
            ->assertForbidden();

        $this->actingAs($this->clerk)
            ->delete("/clerk/org-chart/{$position->id}")
            ->assertForbidden();

        $this->actingAs($this->clerk)
            ->get("/clerk/org-chart/{$position->id}/photo")
            ->assertForbidden();

        $this->assertSame('Other Barangay Role', $position->fresh()->title);
        $this->assertSame('draft', $position->fresh()->status);
    }

    public function test_can_create_and_update_position_with_assigned_person(): void
    {
        $response = $this->actingAs($this->clerk)
            ->from('/clerk/org-chart')
            ->post('/clerk/org-chart', [
                'title' => 'Kagawad on Peace & Order',
                'assigned_person' => 'Juan Dela Cruz',
                'parent_id' => '',
            ]);

        $response->assertRedirect('/clerk/org-chart');
        $response->assertSessionHasNoErrors();

        $position = OrgChartPosition::query()->where('title', 'Kagawad on Peace & Order')->firstOrFail();
        $this->assertSame('Juan Dela Cruz', $position->assigned_person);

        // Update assigned person
        $updateResponse = $this->actingAs($this->clerk)
            ->from('/clerk/org-chart')
            ->put("/clerk/org-chart/{$position->id}", [
                'title' => 'Kagawad on Peace & Order',
                'assigned_person' => 'Maria Clara',
            ]);

        $updateResponse->assertRedirect('/clerk/org-chart');
        $updateResponse->assertSessionHasNoErrors();
        $this->assertSame('Maria Clara', $position->fresh()->assigned_person);

        // Vacate position (empty string converts to null)
        $vacateResponse = $this->actingAs($this->clerk)
            ->from('/clerk/org-chart')
            ->put("/clerk/org-chart/{$position->id}", [
                'title' => 'Kagawad on Peace & Order',
                'assigned_person' => '',
            ]);

        $vacateResponse->assertRedirect('/clerk/org-chart');
        $vacateResponse->assertSessionHasNoErrors();
        $this->assertNull($position->fresh()->assigned_person);
    }

    public function test_assigned_person_validation_rejects_exceeding_255_chars(): void
    {
        $this->actingAs($this->clerk)
            ->from('/clerk/org-chart')
            ->post('/clerk/org-chart', [
                'title' => 'Kagawad on Health',
                'assigned_person' => str_repeat('A', 256),
            ])
            ->assertSessionHasErrors('assigned_person');
    }

    public function test_payload_serializes_assigned_person(): void
    {
        $this->createPosition($this->barangay, [
            'title' => 'Barangay Captain',
            'assigned_person' => 'Captain Barbell',
            'status' => 'draft',
        ]);

        $this->actingAs($this->clerk)
            ->get('/clerk/org-chart')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('OrgChart/Management')
                ->where('positions.0.assigned_person', 'Captain Barbell')
                ->where('tree.0.assigned_person', 'Captain Barbell'));
    }

    public function test_admin_and_clerk_can_save_browser_form_edits_with_and_without_a_photo(): void
    {
        foreach (['admin' => $this->admin, 'clerk' => $this->clerk] as $portal => $user) {
            foreach ([false, true] as $replacePhoto) {
                $parent = $this->createPosition($this->barangay, ['title' => 'Chair']);
                $position = $this->createPosition($this->barangay, [
                    'title' => 'Old Title', 'assigned_person' => 'Old Name',
                    'parent_id' => $parent->id, 'status' => 'published',
                ]);
                $oldPath = 'org-chart/'.$this->barangay->id.'/old-'.$position->id.'.png';
                Storage::disk('local')->put($oldPath, base64_decode(self::PNG_1X1, true));
                $position->update(['photo_path' => $oldPath]);
                $oldPhotoUrl = route($portal.'.org-chart.photo', ['position' => $position->id, 'v' => substr(hash('sha256', $oldPath), 0, 12)]);
                $payload = [
                    '_method' => 'put', 'title' => 'Kagawad on Health',
                    'assigned_person' => 'Updated Name', 'parent_id' => '',
                ];
                if ($replacePhoto) {
                    $payload['photo'] = $this->photoUpload('new-photo.png');
                }
                $base = '/'.$portal.'/org-chart';
                $this->actingAs($user)->from($base)->post($base.'/'.$position->id, $payload)
                    ->assertRedirect($base)->assertSessionHasNoErrors();
                $position->refresh();
                $this->assertSame('Kagawad on Health', $position->title);
                $this->assertSame('Updated Name', $position->assigned_person);
                $this->assertNull($position->parent_id);
                $this->assertSame('draft', $position->status);
                $this->assertSame($user->id, $position->updated_by);
                Storage::disk('local')->assertExists($position->photo_path);
                $view = $this->get($base)->assertOk();
                $saved = collect($view->inertiaProps('positions'))->firstWhere('id', $position->id);
                $this->assertSame('Updated Name', $saved['assigned_person']);
                if ($replacePhoto) {
                    Storage::disk('local')->assertMissing($oldPath);
                    $this->assertNotSame($oldPhotoUrl, $saved['photo_url']);
                } else {
                    $this->assertSame($oldPhotoUrl, $saved['photo_url']);
                }
            }
        }
    }

    public function test_empty_edits_do_not_silently_succeed_or_unpublish_a_position(): void
    {
        $position = $this->createPosition($this->barangay, ['title' => 'Current Title', 'assigned_person' => 'Current Name', 'status' => 'published']);
        $this->actingAs($this->clerk)->putJson('/clerk/org-chart/'.$position->id, [])
            ->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->assertSame('published', $position->fresh()->status);
        $this->assertSame('Current Name', $position->fresh()->assigned_person);
    }
}
