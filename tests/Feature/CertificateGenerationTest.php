<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\OrgChartPosition;
use App\Support\CertificateTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class CertificateGenerationTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $creator = $this->createStaff($this->barangay, 'clerk');
        OrgChartPosition::create([
            'barangay_id' => $this->barangay->id,
            'title' => 'Punong Barangay',
            'assigned_person' => 'HON. TEST',
            'status' => 'published',
            'created_by' => $creator->id,
        ]);
    }

    public function test_availability_flags_stay_in_sync_with_template_inventory(): void
    {
        $available = CertificateTemplates::available();

        foreach (CertificateTemplates::types() as $type) {
            $this->assertSame(
                is_file(CertificateTemplates::path($type)),
                in_array($type, $available, true),
                "Availability flag for [{$type}] does not match its template file."
            );
        }

        $this->assertSame(
            array_values(array_intersect(CertificateTemplates::types(), $available)),
            $available,
            'Available types must be a subset of the known certificate types.'
        );
    }

    public function test_generic_templates_match_their_approved_merge_field_contracts(): void
    {
        $types = ['Indigency', 'Scholarship Certificate', 'Tricycle'];
        $checked = 0;

        foreach ($types as $type) {
            if (! CertificateTemplates::isAvailable($type)) {
                continue;
            }

            $this->assertNotNull(
                CertificateTemplates::genericTemplateKeys($type),
                "The [{$type}] template has no approved merge-field contract."
            );

            CertificateTemplates::assertTemplateContract($type, CertificateTemplates::path($type));
            $checked++;
        }

        if ($checked === 0) {
            $this->markTestSkipped('No generic certificate templates are present in this environment.');
        }

        $this->assertGreaterThan(0, $checked);
    }

    public function test_clerk_certificates_page_exposes_template_availability(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->get('/clerk/certificates')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Clerk/Certificates')
                ->has('certificateTypes', count(CertificateTemplates::types()))
            );
    }

    public function test_available_template_generates_a_certificate_download(): void
    {
        if (! CertificateTemplates::isAvailable('Tricycle')) {
            $this->markTestSkipped('The Tricycle template is not present in this environment.');
        }

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)->post('/clerk/generate-certificate', [
            'certificate_type' => 'Tricycle',
            'resident_name' => 'Juan Dela Cruz',
            'purok' => 'Purok 1',
            'purpose' => 'Employment',
            'control_no' => 'CTRL-2026-0001',
            'fee' => '300',
            'franchise_no' => 'FR-123',
            'business_name' => 'JDC Trading',
            'business_address' => 'Purok 1',
            'signatory_name' => 'HON. TEST',
            'signatory_title' => 'Punong Barangay',
        ]);

        $response->assertOk();
        $this->assertStringContainsString(
            'attachment',
            (string) $response->headers->get('content-disposition')
        );
    }

    public function test_requesting_a_type_without_a_template_is_handled_safely(): void
    {
        $unavailable = array_values(
            array_diff(CertificateTemplates::types(), CertificateTemplates::available())
        );

        if ($unavailable === []) {
            $this->markTestSkipped('Every certificate template is present in this environment.');
        }

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)
            ->from('/clerk/certificates')
            ->post('/clerk/generate-certificate', [
                'certificate_type' => $unavailable[0],
                'resident_name' => 'Juan Dela Cruz',
                'purok' => 'Purok 1',
                'purpose' => 'Employment',
                'control_no' => 'CTRL-2026-0002',
                'fee' => '50',
            ]);

        $response->assertRedirect('/clerk/certificates');
        $response->assertSessionHasErrors('certificate_type');
        $this->assertNotSame(404, $response->getStatusCode());
    }
}
