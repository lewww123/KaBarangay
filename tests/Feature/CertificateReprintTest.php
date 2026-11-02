<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Certificate;
use App\Models\CertificateIssuance;
use App\Models\OrgChartPosition;
use App\Models\Purok;
use App\Support\CertificateTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class CertificateReprintTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Purok $purok;

    private Certificate $certificate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $this->purok = $this->createPurok($this->barangay, 'Purok 1');

        $this->certificate = Certificate::create([
            'barangay_id' => $this->barangay->id,
            'code' => 'TRICYCLE',
            'name' => 'Tricycle',
            'fee' => 300,
            'is_active' => true,
        ]);

        $creator = $this->createStaff($this->barangay, 'clerk');
        OrgChartPosition::create([
            'barangay_id' => $this->barangay->id,
            'title' => 'Punong Barangay',
            'assigned_person' => 'HON. TEST',
            'status' => 'published',
            'created_by' => $creator->id,
        ]);
    }

    public function test_generation_records_a_reprint_snapshot(): void
    {
        $this->requireTricycleTemplate();

        $clerk = $this->createStaff($this->barangay, 'clerk');
        $this->actingAs($clerk)
            ->post('/clerk/generate-certificate', $this->validPayload())
            ->assertOk();

        $issuance = CertificateIssuance::query()->firstOrFail();

        $this->assertIsArray($issuance->details);
        $this->assertSame('Employment', $issuance->details['validated']['purpose'] ?? null);
        $this->assertSame('FR-123', $issuance->details['validated']['franchise_no'] ?? null);
        $this->assertSame('HON. TEST', $issuance->details['officials']['punong_barangay']['name'] ?? null);
    }

    public function test_issued_details_are_read_only_and_scoped_to_the_barangay(): void
    {
        $issuance = $this->createIssuance();

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->getJson('/clerk/certificates/issuances/'.$issuance->id)
            ->assertOk()
            ->assertJsonPath('reference', $issuance->reference)
            ->assertJsonPath('resident.name', 'Juan Dela Cruz')
            ->assertJsonPath('status', 'issued')
            ->assertJsonPath('template_available', CertificateTemplates::isAvailable('Tricycle'));

        $foreignClerk = $this->createStaff(
            $this->createBarangay('Barangay Two', 'BRGY-TWO'),
            'clerk',
        );

        $this->actingAs($foreignClerk)
            ->getJson('/clerk/certificates/issuances/'.$issuance->id)
            ->assertNotFound();

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->getJson('/clerk/certificates/issuances/'.$issuance->id)
            ->assertForbidden();
    }

    public function test_reprint_rebuilds_the_document_without_creating_a_new_issuance(): void
    {
        $this->requireTricycleTemplate();

        $clerk = $this->createStaff($this->barangay, 'clerk');
        $this->actingAs($clerk)
            ->post('/clerk/generate-certificate', $this->validPayload())
            ->assertOk();

        $issuance = CertificateIssuance::query()->firstOrFail();
        $originalIssuedAt = $issuance->issued_at->toDateTimeString();
        $originalDetails = $issuance->details;

        $response = $this->actingAs($clerk)
            ->get('/clerk/certificates/issuances/'.$issuance->id.'/reprint');

        $response->assertOk();
        $response->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        );
        $response->assertHeader('X-Certificate-Reprint', $issuance->reference);

        $disposition = (string) $response->headers->get('content-disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString(
            'Reprint_'.$issuance->reference.'.docx',
            $disposition,
        );

        $this->assertSame(1, CertificateIssuance::query()->count());

        $issuance->refresh();
        $this->assertSame($originalIssuedAt, $issuance->issued_at->toDateTimeString());
        $this->assertSame($originalDetails, $issuance->details);
        $this->assertSame('issued', $issuance->status);
    }

    public function test_reprint_rejects_a_foreign_barangay_issuance(): void
    {
        $issuance = $this->createIssuance();

        $foreignClerk = $this->createStaff(
            $this->createBarangay('Barangay Two', 'BRGY-TWO'),
            'clerk',
        );

        $this->actingAs($foreignClerk)
            ->get('/clerk/certificates/issuances/'.$issuance->id.'/reprint')
            ->assertNotFound();
    }

    public function test_reprint_reports_missing_historical_data_without_touching_the_record(): void
    {
        $this->requireTricycleTemplate();

        $issuance = $this->createIssuance(['details' => null]);

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->getJson('/clerk/certificates/issuances/'.$issuance->id.'/reprint')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('issuance');

        $this->assertSame(1, CertificateIssuance::query()->count());
        $this->assertNull($issuance->fresh()->details);
    }

    private function createIssuance(array $overrides = []): CertificateIssuance
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        return CertificateIssuance::create(array_merge([
            'barangay_id' => $this->barangay->id,
            'reference' => 'ISSUE-2026-TEST0001',
            'control_no' => 'CTRL-2026-0001',
            'certificate_type' => 'Tricycle',
            'status' => 'issued',
            'resident_name' => 'Juan Dela Cruz',
            'resident_number' => 'TEST-RES-0001',
            'purok' => 'Purok 1',
            'fee' => 300,
            'issued_by' => $clerk->id,
            'issued_at' => now(),
            'details' => [
                'version' => 1,
                'validated' => [
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
                ],
                'delayed_psa_details' => null,
                'marriage_details' => null,
                'residency_details' => null,
                'officials' => [
                    'punong_barangay' => [
                        'id' => 1,
                        'name' => 'HON. TEST',
                        'title' => 'Punong Barangay',
                    ],
                    'default_punong_barangay' => [
                        'id' => 1,
                        'name' => 'HON. TEST',
                        'title' => 'Punong Barangay',
                    ],
                    'active_kagawads' => [],
                    'kagawads' => [],
                ],
                'purok' => 'Purok 1',
            ],
        ], $overrides));
    }

    private function requireTricycleTemplate(): void
    {
        if (! CertificateTemplates::isAvailable('Tricycle')) {
            $this->markTestSkipped('The Tricycle template is not present in this environment.');
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
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
        ], $overrides);
    }
}
