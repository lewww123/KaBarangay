<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\CertificateIssuance;
use App\Support\CertificateRequestDetails;
use App\Support\CertificateTenantAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class SicsicanIndigencyTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    public function test_catalog_and_artwork_are_scoped_to_sicsican_and_keep_san_jose_mapping(): void
    {
        $sicsican = $this->createBarangay('Sicsican', 'SIC');
        $sanJose = $this->createBarangay('San Jose', 'SJ');
        $other = $this->createBarangay('Other', 'OTH');
        $migration = require database_path('migrations/2026_11_05_000002_add_sicsican_indigency.php');
        $migration->up();
        $migration->up();
        $certificate = Certificate::where('code', 'sicsican-indigency')->sole();
        $this->assertSame($sicsican->id, $certificate->barangay_id);
        $this->assertSame('Sicsican Indigency', CertificateRequestDetails::typeForCertificate($certificate));
        $this->assertSame('Indigency', CertificateRequestDetails::typeForCertificate(new Certificate(['code' => 'indigency', 'name' => 'Certificate of Indigency'])));
        foreach ([$sanJose, $other] as $barangay) {
            $this->assertFalse(CertificateTenantAccess::allows($barangay->id, 'Sicsican Indigency'));
            $this->actingAs($this->createStaff($barangay, 'clerk'));
            $this->get('/clerk/certificates/sicsican-indigency-artwork')->assertForbidden();
            $this->postJson('/clerk/generate-certificate', ['certificate_type' => 'Sicsican Indigency'])->assertForbidden();
        }
        $this->actingAs($this->createStaff($sicsican, 'clerk'));
        $this->get('/clerk/certificates/sicsican-indigency-artwork')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->postJson('/clerk/generate-certificate', ['certificate_type' => 'Sicsican Indigency', 'output_format' => 'pdf'])
            ->assertUnprocessable()->assertJsonValidationErrors(['purok', 'purpose', 'resident_name']);
    }

    public function test_sicsican_indigency_issues_and_reprints_without_a_docx(): void
    {
        $sicsican = $this->createBarangay('Sicsican', 'SIC');
        (require database_path('migrations/2026_11_05_000002_add_sicsican_indigency.php'))->up();
        $this->actingAs($this->createStaff($sicsican, 'clerk'));
        $response = $this->post('/clerk/generate-certificate', [
            'certificate_type' => 'Sicsican Indigency', 'resident_name' => 'Juan Dela Cruz',
            'purok' => 'Banaba', 'purpose' => 'Medical assistance', 'fee' => 0,
            'control_no' => now('Asia/Manila')->format('Y-m').'-0001', 'output_format' => 'pdf',
            'rendered_pdf' => base64_encode("%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF"),
        ])->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $path = $response->getFile()->getPathname();
        try {
            $issuance = CertificateIssuance::sole();
            $this->assertSame('Sicsican Indigency', $issuance->certificate_type);
            $this->get('/clerk/certificates/issuances/'.$issuance->id.'/reprint')->assertOk();
        } finally {
            @unlink($path);
        }
    }
}
