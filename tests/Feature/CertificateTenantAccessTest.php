<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Support\CertificateTemplates;
use App\Support\CertificateTenantAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class CertificateTenantAccessTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    public function test_catalog_migration_preserves_existing_certificate_settings(): void
    {
        $barangay = $this->createBarangay('Sicsican', 'SIC');
        $certificate = Certificate::withoutGlobalScopes()->create([
            'barangay_id' => $barangay->id, 'code' => 'business-clearance',
            'name' => 'Existing clearance', 'fee' => 150, 'is_active' => false,
        ]);
        $migration = require database_path('migrations/2026_11_04_000004_add_sicsican_business_clearance.php');
        $migration->up();
        $migration->up();

        $this->assertDatabaseHas('certificates', [
            'id' => $certificate->id, 'name' => 'Existing clearance',
            'fee' => 150, 'is_active' => false,
        ]);
        $this->assertSame(1, Certificate::withoutGlobalScopes()->where('barangay_id', $barangay->id)->count());
    }

    public function test_layouts_and_catalogs_stay_with_their_barangay(): void
    {
        $sicsican = $this->createBarangay('Sicsican', 'SIC');
        $sanJose = $this->createBarangay('San Jose', 'SJ');
        $other = $this->createBarangay('Other', 'OTH');
        foreach ([$sicsican, $sanJose, $other] as $barangay) {
            foreach (['business-clearance', 'barangay-certification', 'residency'] as $code) {
                Certificate::withoutGlobalScopes()->create(['barangay_id' => $barangay->id, 'code' => $code, 'name' => $code, 'fee' => 0, 'is_active' => true]);
            }
        }
        $this->assertSame(['Business Clearance', 'Barangay Certification', 'Sicsican Indigency', 'Sicsican Clearance'], array_column(CertificateTemplates::options($sicsican->id), 'value'));
        $this->assertFalse(CertificateTenantAccess::allows($sanJose->id, 'Business Clearance'));
        $this->assertFalse(CertificateTenantAccess::allows($sicsican->id, 'Residency'));
        $this->assertEqualsCanonicalizing(['business-clearance', 'barangay-certification'], Certificate::where('barangay_id', $sicsican->id)->pluck('code')->all());
        $this->assertSame(['residency'], Certificate::where('barangay_id', $sanJose->id)->pluck('code')->all());
        $this->assertSame(3, Certificate::where('barangay_id', $other->id)->count());
        $this->assertNotEmpty(CertificateTemplates::options($other->id));
        $this->actingAs($this->createStaff($sanJose, 'clerk'));
        $this->get('/clerk/certificates/sicsican-artwork')->assertForbidden();
        $this->get('/clerk/certificates/sicsican-certification-artwork')->assertForbidden();
        $this->postJson('/clerk/generate-certificate', ['certificate_type' => 'Barangay Certification'])->assertForbidden();
        $this->postJson('/clerk/generate-certificate', ['certificate_type' => 'Business Clearance'])->assertForbidden();
        $this->actingAs($this->createStaff($sicsican, 'clerk'));
        $this->get('/clerk/certificates/sicsican-artwork')->assertOk();
        $this->get('/clerk/certificates/sicsican-certification-artwork')->assertOk();
        $this->postJson('/clerk/generate-certificate', ['certificate_type' => 'Barangay Certification', 'output_format' => 'pdf'])->assertUnprocessable()->assertJsonValidationErrors(['years_of_residency', 'purok', 'purpose']);
        $this->postJson('/clerk/generate-certificate', ['certificate_type' => 'Residency'])->assertForbidden();
    }
}
