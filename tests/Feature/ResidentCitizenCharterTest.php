<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\CitizenCharterService;
use App\Models\CitizenCharterVersion;
use App\Models\Purok;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class ResidentCitizenCharterTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Barangay $otherBarangay;

    private Purok $purok;

    private Resident $resident;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');

        $this->barangay = $this->createBarangay('Barangay Central', 'BRGY-CTR');
        $this->otherBarangay = $this->createBarangay('Barangay North', 'BRGY-NTH');
        $this->purok = $this->createPurok($this->barangay, 'Purok 1');
        $this->resident = $this->createResident($this->barangay, $this->purok);
        $this->clerk = $this->createStaff($this->barangay, 'clerk');
    }

    private function validPdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    private function createServiceWithVersion(
        Barangay $barangay,
        array $serviceAttributes = [],
        array $versionAttributes = []
    ): CitizenCharterService {
        $path = 'citizen-charter/'.$barangay->id.'/'.Str::uuid().'.pdf';
        Storage::disk('local')->put($path, $this->validPdfBytes());

        $service = CitizenCharterService::create([
            'barangay_id' => $barangay->id,
            'title' => 'Barangay Clearance Issuance',
            'category' => 'Certificates',
            'description' => 'Standards and steps for barangay clearance.',
            'display_order' => 1,
            'status' => 'published',
            'is_published' => true,
            'published_at' => now(),
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
            'status' => 'published',
            'published_at' => now(),
            ...$versionAttributes,
        ]);

        $service->update(['current_version_id' => $version->id]);

        return $service->fresh(['currentVersion', 'versions']);
    }

    public function test_published_services_appear_in_resident_directory(): void
    {
        $published = $this->createServiceWithVersion($this->barangay, [
            'title' => 'Certificate of Residency',
        ]);

        $response = $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/citizen-charter');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Resident/CitizenCharter')
            ->has('services.data', 1)
            ->where('services.data.0.id', $published->id)
            ->where('services.data.0.title', 'Certificate of Residency')
            ->missing('services.data.0.current_version.path')
            ->missing('services.data.0.current_version.disk')
            ->missing('services.data.0.current_version.sha256')
        );
    }

    public function test_draft_services_do_not_appear_in_resident_directory(): void
    {
        $this->createServiceWithVersion($this->barangay, [
            'title' => 'Draft Unfinished Service',
            'is_published' => false,
            'status' => 'draft',
            'published_at' => null,
        ]);

        $response = $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/citizen-charter');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Resident/CitizenCharter')
            ->has('services.data', 0)
        );
    }

    public function test_archived_services_do_not_appear_in_resident_directory(): void
    {
        $this->createServiceWithVersion($this->barangay, [
            'title' => 'Old Abolished Service',
            'is_published' => false,
            'status' => 'archived',
            'archived_at' => now(),
        ]);

        $response = $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/citizen-charter');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Resident/CitizenCharter')
            ->has('services.data', 0)
        );
    }

    public function test_resident_only_sees_services_from_own_barangay(): void
    {
        $this->createServiceWithVersion($this->otherBarangay, [
            'title' => 'Other Barangay Service',
        ]);

        $response = $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/citizen-charter');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Resident/CitizenCharter')
            ->has('services.data', 0)
        );
    }

    public function test_resident_can_view_published_service_details(): void
    {
        $service = $this->createServiceWithVersion($this->barangay, [
            'title' => 'Barangay Business Clearance',
        ]);

        $response = $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/citizen-charter/'.$service->id);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Resident/CitizenCharterShow')
            ->where('service.id', $service->id)
            ->where('service.title', 'Barangay Business Clearance')
            ->where('service.current_version.version_number', 1)
        );
    }

    public function test_resident_cannot_access_draft_or_archived_service_details(): void
    {
        $draft = $this->createServiceWithVersion($this->barangay, [
            'is_published' => false,
            'status' => 'draft',
            'published_at' => null,
        ]);

        $archived = $this->createServiceWithVersion($this->barangay, [
            'is_published' => false,
            'status' => 'archived',
            'archived_at' => now(),
        ]);

        $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/citizen-charter/'.$draft->id)
            ->assertNotFound();

        $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/citizen-charter/'.$archived->id)
            ->assertNotFound();
    }

    public function test_resident_cannot_access_other_barangay_service_details(): void
    {
        $foreignService = $this->createServiceWithVersion($this->otherBarangay);

        $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/citizen-charter/'.$foreignService->id)
            ->assertNotFound();
    }

    public function test_resident_can_stream_current_published_pdf_with_security_headers(): void
    {
        $service = $this->createServiceWithVersion($this->barangay);

        $response = $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/citizen-charter/'.$service->id.'/file');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    }

    public function test_resident_can_download_pdf_attachment(): void
    {
        $service = $this->createServiceWithVersion($this->barangay);

        $response = $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/citizen-charter/'.$service->id.'/download');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('Content-Disposition', 'attachment; filename=clearance_charter.pdf');
    }

    public function test_resident_cannot_download_other_barangay_file(): void
    {
        $foreignService = $this->createServiceWithVersion($this->otherBarangay);

        $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/citizen-charter/'.$foreignService->id.'/file')
            ->assertNotFound();

        $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/citizen-charter/'.$foreignService->id.'/download')
            ->assertNotFound();
    }

    public function test_publishing_replacement_promotes_resident_visible_document(): void
    {
        $service = $this->createServiceWithVersion($this->barangay, [], [
            'version_number' => 1,
            'original_name' => 'v1_charter.pdf',
        ]);

        $v1Path = $service->currentVersion->path;

        // Create replacement revision v2 (stored as draft)
        $v2Path = 'citizen-charter/'.$this->barangay->id.'/'.Str::uuid().'.pdf';
        Storage::disk('local')->put($v2Path, "%PDF-1.4\n%REVISED VERSION 2\n%%EOF\n");

        $v2 = CitizenCharterVersion::create([
            'service_id' => $service->id,
            'barangay_id' => $this->barangay->id,
            'version_number' => 2,
            'disk' => 'local',
            'path' => $v2Path,
            'original_name' => 'v2_charter.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 2048,
            'sha256' => hash('sha256', 'v2'),
            'uploaded_by' => $this->clerk->id,
            'status' => 'draft',
        ]);

        // Prior to Clerk publishing v2, resident STILL downloads v1
        $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/citizen-charter/'.$service->id.'/download')
            ->assertHeader('Content-Disposition', 'attachment; filename=v1_charter.pdf');

        // Clerk publishes v2
        $this->actingAs($this->clerk)
            ->post('/clerk/citizen-charter/'.$service->id.'/publish');

        // Resident now downloads v2
        $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/citizen-charter/'.$service->id.'/download')
            ->assertHeader('Content-Disposition', 'attachment; filename=v2_charter.pdf');

        // Historical v1 is STILL preserved on disk
        Storage::disk('local')->assertExists($v1Path);
    }
}
