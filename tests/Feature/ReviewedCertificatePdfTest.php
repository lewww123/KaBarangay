<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\CertificateIssuance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class ReviewedCertificatePdfTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private function payload(): array
    {
        return [
            'certificate_type' => 'Barangay Clearance', 'resident_name' => 'Juan Cruz',
            'purok' => 'Purok 1', 'purpose' => 'School requirement',
            'control_no' => now('Asia/Manila')->format('Y-m').'-0001', 'fee' => '50',
            'output_format' => 'pdf',
            'rendered_pdf' => base64_encode("%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF"),
        ];
    }

    public function test_catalog_certificate_can_issue_and_reprint_the_exact_reviewed_pdf_without_a_word_template(): void
    {
        $barangay = $this->createBarangay('One', 'ONE');
        $clerk = $this->createStaff($barangay, 'clerk');
        Certificate::create(['barangay_id' => $barangay->id, 'name' => 'Barangay Clearance', 'code' => 'barangay-clearance', 'fee' => 50, 'is_active' => true]);
        $response = $this->actingAs($clerk)->post('/clerk/generate-certificate', $this->payload())->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Certificate-Issuance-Status', 'issued');
        $issuance = CertificateIssuance::sole();
        $path = $response->getFile()->getPathname();
        try {
            $this->assertSame(base64_decode($this->payload()['rendered_pdf']), file_get_contents($path));
            $this->assertArrayNotHasKey('rendered_pdf', $issuance->details['validated']);
            $again = $this->get('/clerk/certificates/issuances/'.$issuance->id.'/reprint')->assertOk();
            $again->assertHeader('Content-Type', 'application/pdf');
            $this->assertSame($path, $again->getFile()->getPathname());
            $this->assertSame(1, CertificateIssuance::count());
            $other = $this->createStaff($this->createBarangay('Other', 'OTHER'), 'clerk');
            $this->actingAs($other)->get('/clerk/certificates/issuances/'.$issuance->id.'/reprint')->assertNotFound();
        } finally {
            @unlink($path);
        }
    }

    public function test_sari_sari_and_marriage_options_and_pdf_issuance_do_not_require_docx_files(): void
    {
        $barangay = $this->createBarangay('One', 'ONE');
        $clerk = $this->createStaff($barangay, 'clerk');
        $resident = $this->createResident($barangay, $this->createPurok($barangay, 'Purok 1'), ['mobile' => '09178889990']);
        foreach (['Sari-sari Store', 'Marriage Cohabitation'] as $index => $type) {
            config(['certificates.template_paths.'.$type => storage_path('app/no-such-template.docx')]);
            $this->assertFalse(\App\Support\CertificateTemplates::isAvailable($type));
            $option = collect(\App\Support\CertificateTemplates::options())->firstWhere('value', $type);
            $this->assertTrue($option['available']);
            $payload = [...$this->payload(), 'certificate_type' => $type, 'control_no' => now('Asia/Manila')->format('Y-m').'-0001',
                'resident_id' => $resident->id, 'business_type' => 'Retailer - Fruits & Vegetables', 'or_number' => '3067093', 'amount_paid' => '1000', 'receipt_date' => '2026-11-02', 'business_name' => 'Test Store', 'business_address' => 'Purok 1',
                'partner_full_name' => 'Maria Santos', 'partner_age' => '29', 'years_living_together' => '5'];
            $response = $this->actingAs($clerk)->post('/clerk/generate-certificate', $payload)->assertOk();
            $response->assertHeader('Content-Type', 'application/pdf');
            if ($type === 'Sari-sari Store') {
                $saved = CertificateIssuance::where('certificate_type', $type)->sole()->details['validated'];
                $this->assertSame('Retailer - Fruits & Vegetables', $saved['business_type']);
                $this->assertSame('3067093', $saved['or_number']);
                $this->assertSame('1000', $saved['amount_paid']);
                $this->assertSame('2026-11-02', $saved['receipt_date']);
            }
            @unlink($response->getFile()->getPathname());
        }
        $this->assertSame(2, CertificateIssuance::count());
    }

    public function test_marriage_pdf_still_requires_a_registered_resident_and_partner_details(): void
    {
        $clerk = $this->createStaff($this->createBarangay('One', 'ONE'), 'clerk');
        $this->actingAs($clerk)->postJson('/clerk/generate-certificate', [...$this->payload(), 'certificate_type' => 'Marriage Cohabitation'])
            ->assertUnprocessable()->assertJsonValidationErrors(['partner_full_name', 'partner_age', 'years_living_together']);
        $this->postJson('/clerk/generate-certificate', [...$this->payload(), 'certificate_type' => 'Marriage Cohabitation',
            'partner_full_name' => 'Maria Santos', 'partner_age' => '29', 'years_living_together' => '5'])
            ->assertUnprocessable()->assertJsonValidationErrors('resident_id');
        $this->assertSame(0, CertificateIssuance::count());
    }

    public function test_store_pdf_requires_business_details_and_validates_receipt_date(): void
    {
        $clerk = $this->createStaff($this->createBarangay('One', 'ONE'), 'clerk');
        $this->actingAs($clerk)->postJson('/clerk/generate-certificate', [...$this->payload(), 'certificate_type' => 'Sari-sari Store'])
            ->assertUnprocessable()->assertJsonValidationErrors(['business_type', 'business_name', 'business_address']);
        $this->postJson('/clerk/generate-certificate', [...$this->payload(), 'certificate_type' => 'Sari-sari Store',
            'business_type' => 'Retailer', 'business_name' => 'Store', 'business_address' => 'Purok 1', 'receipt_date' => 'bad date'])
            ->assertUnprocessable()->assertJsonValidationErrors('receipt_date');
        $this->assertSame(0, CertificateIssuance::count());
    }

    public function test_unknown_types_and_invalid_pdf_payloads_are_rejected_without_issuance(): void
    {
        $clerk = $this->createStaff($this->createBarangay('One', 'ONE'), 'clerk');
        $this->actingAs($clerk)->postJson('/clerk/generate-certificate', [...$this->payload(), 'certificate_type' => 'Unknown'])
            ->assertUnprocessable()->assertJsonValidationErrors('certificate_type');
        $this->postJson('/clerk/generate-certificate', [...$this->payload(), 'certificate_type' => 'Indigency', 'rendered_pdf' => base64_encode('not a PDF')])
            ->assertUnprocessable()->assertJsonValidationErrors('rendered_pdf');
        $this->assertSame(0, CertificateIssuance::count());
    }
}
