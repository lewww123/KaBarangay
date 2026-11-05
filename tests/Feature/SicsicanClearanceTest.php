<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Support\CertificateRequestDetails;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class SicsicanClearanceTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    public function test_clearance_catalog_and_reference_assets_are_scoped(): void
    {
        $sicsican = $this->createBarangay('Sicsican', 'SIC');
        $sanJose = $this->createBarangay('San Jose', 'SJ');
        $migration = require database_path('migrations/2026_11_06_000001_add_sicsican_clearance.php');
        $migration->up();
        $migration->up();
        $certificate = Certificate::where('code', 'sicsican-clearance')->sole();
        $this->assertSame($sicsican->id, $certificate->barangay_id);
        $this->assertSame('Sicsican Clearance', CertificateRequestDetails::typeForCertificate($certificate));
        $url = '/clerk/certificates/sicsican-reference-artwork?variant=clearance&asset=image1.png';
        $this->actingAs($this->createStaff($sanJose, 'clerk'))->get($url)->assertForbidden();
        $this->actingAs($this->createStaff($sicsican, 'clerk'))->get($url)->assertOk();
        $this->get('/clerk/certificates/sicsican-reference-artwork?variant=../../&asset=image1.png')->assertRedirect();
        $this->postJson('/clerk/generate-certificate', ['certificate_type' => 'Sicsican Clearance', 'output_format' => 'pdf'])
            ->assertUnprocessable()->assertJsonValidationErrors(['resident_name', 'purok', 'purpose']);
    }
}
