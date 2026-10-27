<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Barangay;
use App\Models\CitizenCharterService;
use App\Models\CitizenCharterVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class CitizenCharterManagementTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Barangay $otherBarangay;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');

        $this->barangay = $this->createBarangay('Barangay Central', 'BRGY-CTR');
        $this->otherBarangay = $this->createBarangay('Barangay North', 'BRGY-NTH');
        $this->clerk = $this->createStaff($this->barangay, 'clerk');
    }

    private function validPdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    private function fakePdf(string $name = 'charter.pdf', int $kilobytes = 100): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            $this->validPdfBytes() . str_repeat('A', $kilobytes * 1024)
        );
    }

    private function createServiceWithVersion(
        Barangay $barangay,
        array $serviceAttributes = [],
        array $versionAttributes = []
    ): CitizenCharterService {
        $path = 'citizen-charter/' . $barangay->id . '/' . Str::uuid() . '.pdf';
        Storage::disk('local')->put($path, $this->validPdfBytes());

        $service = CitizenCharterService::create([
            'barangay_id' => $barangay->id,
            'title' => 'Barangay Clearance Issuance',
            'category' => 'Certificates',
            'description' => 'Standards and steps for barangay clearance.',
            'display_order' => 1,
            'status' => 'draft',
            'is_published' => false,
            'published_at' => null,
            'archived_at' => null,
            'created_by' => $this->clerk->id,
            ...$serviceAttributes,
        ]);

        $version = CitizenCharterVersion::create([
            'service_id' => $service->id,
            'barangay_id' => $barangay->id,
            'version_number' => 1,
            'disk' => 'local',
            'path' => $path,
            'original_name' => 'clearance_charter.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'sha256' => hash('sha256', $this->validPdfBytes()),
            'uploaded_by' => $this->clerk->id,
            'status' => 'draft',
            ...$versionAttributes,
        ]);

        $service->update(['current_version_id' => $version->id]);

        return $service->fresh(['currentVersion', 'versions']);
    }

    public function test_clerk_can_view_citizen_charter_index_with_stats(): void
    {
        $this->createServiceWithVersion($this->barangay, [
            'title' => 'Service 1',
            'is_published' => true,
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->createServiceWithVersion($this->barangay, [
            'title' => 'Service 2',
            'is_published' => false,
            'status' => 'draft',
        ]);

        // Service from another barangay must not count
        $this->createServiceWithVersion($this->otherBarangay, [
            'title' => 'Other Service',
            'is_published' => true,
            'status' => 'published',
        ]);

        $response = $this->actingAs($this->clerk)
            ->get('/clerk/citizen-charter');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Clerk/CitizenCharter/Index')
            ->has('services.data', 2)
            ->where('stats.total', 2)
            ->where('stats.published', 1)
            ->where('stats.draft', 1)
        );
    }

    public function test_clerk_can_create_service_with_pdf_document(): void
    {
        $file = $this->fakePdf('barangay_indigency_standard.pdf', 50);

        $response = $this->actingAs($this->clerk)
            ->post('/clerk/citizen-charter', [
                'title' => 'Certificate of Indigency',
                'category' => 'Social Services',
                'description' => 'Procedure for financial assistance qualification.',
                'display_order' => 2,
                'document' => $file,
            ]);

        $service = CitizenCharterService::query()
            ->where('title', 'Certificate of Indigency')
            ->firstOrFail();

        $response->assertRedirect('/clerk/citizen-charter/' . $service->id);

        $this->assertSame((int) $this->barangay->id, (int) $service->barangay_id);
        $this->assertSame('draft', $service->status);
        $this->assertFalse($service->is_published);
        $this->assertNotNull($service->current_version_id);

        $version = $service->currentVersion;
        $this->assertNotNull($version);
        $this->assertSame(1, $version->version_number);
        $this->assertSame('draft', $version->status);
        $this->assertSame('barangay_indigency_standard.pdf', $version->original_name);
        Storage::disk('local')->assertExists($version->path);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'citizen_charter.service_created',
            'barangay_id' => $this->barangay->id,
            'actor_id' => $this->clerk->id,
            'entity_id' => $service->id,
        ]);
    }

    public function test_service_creation_rejects_non_pdf_file(): void
    {
        $file = UploadedFile::fake()->create('document.docx', 100);

        $response = $this->actingAs($this->clerk)
            ->from('/clerk/citizen-charter')
            ->post('/clerk/citizen-charter', [
                'title' => 'Invalid Service',
                'document' => $file,
            ]);

        $response->assertRedirect('/clerk/citizen-charter');
        $response->assertSessionHasErrors('document');
        $this->assertDatabaseCount('citizen_charter_services', 0);
    }

    public function test_service_creation_rejects_fake_extension_without_pdf_signature(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'spoofed.pdf',
            "NOT A REAL PDF FILE HEADER"
        );

        $response = $this->actingAs($this->clerk)
            ->from('/clerk/citizen-charter')
            ->post('/clerk/citizen-charter', [
                'title' => 'Spoofed File',
                'document' => $file,
            ]);

        $response->assertSessionHasErrors('document');
        $this->assertDatabaseCount('citizen_charter_services', 0);
    }

    public function test_service_creation_rejects_file_exceeding_upload_limit(): void
    {
        // 3 MB exceeds the 2 MB runtime limit
        $file = $this->fakePdf('too_large.pdf', 3072);

        $response = $this->actingAs($this->clerk)
            ->from('/clerk/citizen-charter')
            ->post('/clerk/citizen-charter', [
                'title' => 'Oversized PDF',
                'document' => $file,
            ]);

        $response->assertSessionHasErrors('document');
        $this->assertDatabaseCount('citizen_charter_services', 0);
    }

    public function test_clerk_can_update_service_metadata(): void
    {
        $service = $this->createServiceWithVersion($this->barangay);

        $response = $this->actingAs($this->clerk)
            ->put('/clerk/citizen-charter/' . $service->id, [
                'title' => 'Updated Clearance Title',
                'category' => 'Legal & Clearances',
                'description' => 'Updated service description.',
                'display_order' => 5,
            ]);

        $response->assertRedirect();
        $service->refresh();

        $this->assertSame('Updated Clearance Title', $service->title);
        $this->assertSame('Legal & Clearances', $service->category);
        $this->assertSame(5, $service->display_order);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'citizen_charter.service_updated',
            'entity_id' => $service->id,
        ]);
    }

    public function test_clerk_can_upload_replacement_version_which_remains_draft(): void
    {
        $service = $this->createServiceWithVersion($this->barangay, [
            'is_published' => true,
            'status' => 'published',
            'published_at' => now(),
        ], [
            'status' => 'published',
            'published_at' => now(),
        ]);

        $initialVersionId = $service->current_version_id;

        $newFile = $this->fakePdf('clearance_v2.pdf', 80);

        $response = $this->actingAs($this->clerk)
            ->post('/clerk/citizen-charter/' . $service->id . '/versions', [
                'document' => $newFile,
            ]);

        $response->assertRedirect();
        $service->refresh();

        // Safe versioning guarantee: Service current_version_id must NOT change yet!
        $this->assertSame((int) $initialVersionId, (int) $service->current_version_id);
        $this->assertTrue($service->is_published);

        // Version 2 exists in draft status
        $this->assertCount(2, $service->versions);
        $v2 = $service->versions()->where('version_number', 2)->firstOrFail();
        $this->assertSame('draft', $v2->status);
        $this->assertSame('clearance_v2.pdf', $v2->original_name);
        Storage::disk('local')->assertExists($v2->path);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'citizen_charter.version_uploaded',
            'entity_id' => $service->id,
        ]);
    }

    public function test_clerk_can_publish_service_and_promote_draft_version(): void
    {
        $service = $this->createServiceWithVersion($this->barangay, [
            'is_published' => false,
            'status' => 'draft',
        ]);

        $this->assertFalse($service->is_published);

        $response = $this->actingAs($this->clerk)
            ->post('/clerk/citizen-charter/' . $service->id . '/publish');

        $response->assertRedirect();
        $service->refresh();

        $this->assertTrue($service->is_published);
        $this->assertSame('published', $service->status);
        $this->assertNotNull($service->published_at);
        $this->assertSame('published', $service->currentVersion->status);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'citizen_charter.published',
            'entity_id' => $service->id,
        ]);
    }

    public function test_publishing_replacement_promotes_it_and_supersedes_previous_version(): void
    {
        $service = $this->createServiceWithVersion($this->barangay, [
            'is_published' => true,
            'status' => 'published',
            'published_at' => now(),
        ], [
            'version_number' => 1,
            'status' => 'published',
            'published_at' => now(),
        ]);

        $v1 = $service->currentVersion;

        // Upload revision v2
        $newFile = $this->fakePdf('clearance_v2.pdf', 60);
        $this->actingAs($this->clerk)
            ->post('/clerk/citizen-charter/' . $service->id . '/versions', [
                'document' => $newFile,
            ]);

        $v2 = $service->versions()->where('version_number', 2)->firstOrFail();
        $this->assertSame('draft', $v2->status);

        // Publish
        $this->actingAs($this->clerk)
            ->post('/clerk/citizen-charter/' . $service->id . '/publish');

        $service->refresh();
        $v1->refresh();
        $v2->refresh();

        // Current version is now v2
        $this->assertSame((int) $v2->id, (int) $service->current_version_id);
        $this->assertSame('published', $v2->status);
        $this->assertNotNull($v2->published_at);

        // Previous version v1 is retained as superseded
        $this->assertSame('superseded', $v1->status);
        Storage::disk('local')->assertExists($v1->path);
        Storage::disk('local')->assertExists($v2->path);
    }

    public function test_clerk_can_archive_service(): void
    {
        $service = $this->createServiceWithVersion($this->barangay, [
            'is_published' => true,
            'status' => 'published',
            'published_at' => now(),
        ], [
            'status' => 'published',
            'published_at' => now(),
        ]);

        $response = $this->actingAs($this->clerk)
            ->post('/clerk/citizen-charter/' . $service->id . '/archive');

        $response->assertRedirect();
        $service->refresh();

        $this->assertFalse($service->is_published);
        $this->assertSame('archived', $service->status);
        $this->assertNotNull($service->archived_at);
        $this->assertSame('archived', $service->currentVersion->status);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'citizen_charter.archived',
            'entity_id' => $service->id,
        ]);
    }

    public function test_clerk_cannot_access_or_modify_other_barangay_service(): void
    {
        $foreignService = $this->createServiceWithVersion($this->otherBarangay);

        // View foreign service -> 404
        $this->actingAs($this->clerk)
            ->get('/clerk/citizen-charter/' . $foreignService->id)
            ->assertNotFound();

        // Update foreign service -> 404
        $this->actingAs($this->clerk)
            ->put('/clerk/citizen-charter/' . $foreignService->id, [
                'title' => 'Hacked Title',
            ])
            ->assertNotFound();

        // Publish foreign service -> 404
        $this->actingAs($this->clerk)
            ->post('/clerk/citizen-charter/' . $foreignService->id . '/publish')
            ->assertNotFound();

        // Archive foreign service -> 404
        $this->actingAs($this->clerk)
            ->post('/clerk/citizen-charter/' . $foreignService->id . '/archive')
            ->assertNotFound();

        // Stream foreign file -> 404
        $this->actingAs($this->clerk)
            ->get('/clerk/citizen-charter/' . $foreignService->id . '/versions/' . $foreignService->current_version_id . '/file')
            ->assertNotFound();
    }
}
