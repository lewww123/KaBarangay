<?php

namespace Tests\Feature;

use App\Models\CertificateIssuance;
use App\Support\CertificateControlNumbers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class CertificateControlNumberTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    public function test_each_type_and_barangay_has_an_independent_monthly_sequence_in_philippine_time(): void
    {
        $this->travelTo(Carbon::parse('2026-10-31 16:00:00', 'UTC'));
        $barangay = $this->createBarangay('One', 'ONE');
        $other = $this->createBarangay('Other', 'OTHER');
        $clerk = $this->createStaff($barangay, 'clerk');
        foreach (['Indigency', 'Residency'] as $type) {
            $this->assertSame('2026-11-0001', CertificateControlNumbers::next($barangay->id, $type));
            CertificateIssuance::create([
                'barangay_id' => $barangay->id, 'certificate_type' => $type, 'control_no' => '2026-11-0001',
                'reference' => (string) Str::uuid(), 'resident_name' => 'Juan Cruz', 'fee' => 0,
                'issued_by' => $clerk->id, 'issued_at' => now(),
            ]);
            $this->assertSame('2026-11-0002', CertificateControlNumbers::next($barangay->id, $type));
            $this->assertSame('2026-11-0001', CertificateControlNumbers::next($other->id, $type));
        }
        $this->travelTo(Carbon::parse('2026-11-30 16:00:00', 'UTC'));
        $this->assertSame('2026-12-0001', CertificateControlNumbers::next($barangay->id, 'Indigency'));
        $this->travelTo(Carbon::parse('2026-12-31 16:00:00', 'UTC'));
        $this->assertSame('2027-01-0001', CertificateControlNumbers::next($barangay->id, 'Indigency'));
    }

    public function test_preview_does_not_consume_numbers_and_stale_issuance_is_rejected_without_a_duplicate(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02', 'Asia/Manila'));
        $barangay = $this->createBarangay('One', 'ONE');
        $clerk = $this->createStaff($barangay, 'clerk');
        $this->actingAs($clerk);
        for ($i = 0; $i < 2; $i++) {
            $this->getJson('/clerk/certificates/control-number?certificate_type=Indigency')
                ->assertOk()->assertJsonPath('control_no', '2026-11-0001');
        }
        $this->assertSame(0, CertificateIssuance::count());
        $payload = ['certificate_type' => 'Indigency', 'resident_name' => 'Juan Cruz', 'purpose' => 'School',
            'fee' => 50, 'control_no' => '2026-11-0001', 'output_format' => 'pdf',
            'rendered_pdf' => base64_encode("%PDF-1.4\n%%EOF")];
        $response = $this->post('/clerk/generate-certificate', $payload)->assertOk();
        try {
            $this->postJson('/clerk/generate-certificate', $payload)->assertUnprocessable()
                ->assertJsonValidationErrors('control_no')->assertJsonPath('errors.next_control_no.0', '2026-11-0002');
            $this->assertSame(1, CertificateIssuance::count());
            $this->getJson('/clerk/certificates/control-number?certificate_type=Indigency')
                ->assertOk()->assertJsonPath('control_no', '2026-11-0002');
            $retry = $this->post('/clerk/generate-certificate', [...$payload, 'control_no' => '2026-11-0002'])->assertOk();
            @unlink($retry->getFile()->getPathname());
            $this->assertSame(2, CertificateIssuance::count());
            $this->getJson('/clerk/certificates/control-number?certificate_type=Unknown')->assertUnprocessable();
        } finally {
            @unlink($response->getFile()->getPathname());
        }
    }
}
