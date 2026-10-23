<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\CertificateIssuance;
use App\Models\Purok;
use App\Support\CertificateTemplates;
use App\Support\DelayedPsaCertificate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\TemplateProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;
use ZipArchive;

class DelayedPsaCertificateIntegrationTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Purok $purok;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $this->purok = $this->createPurok($this->barangay, 'Purok 1');
    }

    public function test_delayed_psa_template_is_available_and_downloads(): void
    {
        $this->requireTemplate();

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)
            ->post('/clerk/generate-certificate', $this->validPayload());

        $response->assertOk();
        $this->assertStringContainsString(
            'attachment',
            (string) $response->headers->get('content-disposition')
        );
    }

    public function test_delayed_psa_template_exposes_exactly_the_approved_merge_fields(): void
    {
        $this->requireTemplate();

        $variables = (new TemplateProcessor($this->templatePath()))->getVariables();
        sort($variables);

        $expected = DelayedPsaCertificate::TEMPLATE_KEYS;
        sort($expected);

        $this->assertSame($expected, $variables);

        // The template states its own purpose and carries its own signature
        // blocks, so it must not expose the generic merge fields.
        $this->assertNotContains('purpose', $variables);
        $this->assertNotContains('barangay_address', $variables);
        $this->assertNotContains('signatory_name', $variables);
        $this->assertNotContains('signatory_title', $variables);
    }

    public function test_delayed_psa_template_contract_assertion_accepts_the_provisioned_template(): void
    {
        $this->requireTemplate();

        DelayedPsaCertificate::assertTemplateContract($this->templatePath());

        $this->assertTrue(true);
    }

    public function test_delayed_psa_template_contract_assertion_rejects_a_missing_merge_field(): void
    {
        $path = $this->writeTemplateWithKeys(['control_number', 'purok']);

        try {
            DelayedPsaCertificate::assertTemplateContract($path);

            $this->fail('A template with a missing merge field must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('contract', $exception->getMessage());
        } finally {
            @unlink($path);
        }
    }

    public function test_delayed_psa_template_contract_assertion_rejects_an_extra_merge_field(): void
    {
        $keys = DelayedPsaCertificate::TEMPLATE_KEYS;
        $keys[] = 'purpose';

        $path = $this->writeTemplateWithKeys($keys);

        try {
            DelayedPsaCertificate::assertTemplateContract($path);

            $this->fail('A template with an extra merge field must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('contract', $exception->getMessage());
        } finally {
            @unlink($path);
        }
    }

    public function test_delayed_psa_misprovisioned_template_is_rejected_before_a_document_is_issued(): void
    {
        $this->requireTemplate();

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $original = (string) file_get_contents($this->templatePath());
        $broken = $this->writeTemplateWithKeys(['control_number', 'purok']);

        try {
            // Swap in a template that does not honour the merge-field contract.
            copy($broken, $this->templatePath());

            $response = $this->actingAs($clerk)
                ->from('/clerk/certificates')
                ->post('/clerk/generate-certificate', $this->validPayload());

            $response->assertSessionHasErrors('certificate_type');
            $this->assertSame(0, CertificateIssuance::query()->count());
        } finally {
            file_put_contents($this->templatePath(), $original);
            @unlink($broken);
        }
    }

    public function test_delayed_psa_generation_resolves_every_placeholder(): void
    {
        $this->requireTemplate();

        $values = $this->resolvedValues();

        $processor = new TemplateProcessor($this->templatePath());

        foreach (DelayedPsaCertificate::templateValues($values) as $key => $value) {
            $processor->setValue($key, $value);
        }

        $output = tempnam(sys_get_temp_dir(), 'delayed_psa_render_').'.docx';

        try {
            $processor->saveAs($output);

            $xml = $this->archiveDocumentXml($output);

            $this->assertNotFalse($xml, 'The rendered Delayed PSA document is not a readable DOCX archive.');

            foreach (DelayedPsaCertificate::TEMPLATE_KEYS as $key) {
                $this->assertStringNotContainsString(
                    '${'.$key.'}',
                    $xml,
                    "Unresolved merge field [{$key}] remains in the generated document."
                );
            }

            // The printed values must be present in the document.
            $this->assertStringContainsString('CTRL-2026-0042', $xml);
            $this->assertStringContainsString('JUAN DELA CRUZ', $xml);
            $this->assertStringContainsString('PUROK 1', $xml);
            $this->assertStringContainsString('1st day of January 1990', $xml);
            $this->assertStringContainsString('MALE', $xml);
            $this->assertStringContainsString('FILIPINO', $xml);
            $this->assertStringContainsString('PUERTO PRINCESA CITY', $xml);
            $this->assertStringContainsString('PEDRO DELA CRUZ', $xml);
            $this->assertStringContainsString('MARIA DELA CRUZ', $xml);
            $this->assertStringContainsString('12 JUNE 1985, PUERTO PRINCESA CITY', $xml);
            $this->assertStringContainsString('1995 up to present', $xml);
        } finally {
            @unlink($output);
        }
    }

    public function test_delayed_psa_does_not_require_a_purpose(): void
    {
        $this->requireTemplate();

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)
            ->from('/clerk/certificates')
            ->post('/clerk/generate-certificate', $this->validPayload([
                'purpose' => '',
            ]));

        $response->assertSessionHasNoErrors();
        $this->assertSame(1, CertificateIssuance::query()->count());
    }

    public function test_delayed_psa_does_not_require_a_signatory(): void
    {
        $this->requireTemplate();

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)
            ->from('/clerk/certificates')
            ->post('/clerk/generate-certificate', $this->validPayload([
                'signatory_name' => '',
                'signatory_title' => '',
            ]));

        $response->assertSessionHasNoErrors();
        $this->assertSame(1, CertificateIssuance::query()->count());
    }

    public function test_delayed_psa_requires_the_detail_review_confirmation(): void
    {
        $this->requireTemplate();

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)
            ->from('/clerk/certificates')
            ->post('/clerk/generate-certificate', $this->validPayload([
                'details_confirmed' => '',
            ]));

        $response->assertSessionHasErrors('details_confirmed');
        $this->assertSame(0, CertificateIssuance::query()->count());
    }

    /**
     * @return array<int, array<int, string>>
     */
    public static function missingDetailProvider(): array
    {
        return [
            ['nationality'],
            ['birth_place'],
            ['father_name'],
            ['mother_maiden_name'],
            ['parents_marriage_details'],
            ['residency_start_year'],
        ];
    }

    #[DataProvider('missingDetailProvider')]
    public function test_delayed_psa_rejects_a_missing_required_detail(string $field): void
    {
        $this->requireTemplate();

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)
            ->from('/clerk/certificates')
            ->post('/clerk/generate-certificate', $this->validPayload([
                $field => '',
            ]));

        $response->assertSessionHasErrors($field);
        $this->assertSame(0, CertificateIssuance::query()->count());
    }

    public function test_delayed_psa_rejects_a_missing_date_of_birth_without_a_resident_profile(): void
    {
        $this->requireTemplate();

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)
            ->from('/clerk/certificates')
            ->post('/clerk/generate-certificate', $this->validPayload([
                'date_of_birth' => '',
            ]));

        $response->assertSessionHasErrors('date_of_birth');
        $this->assertSame(0, CertificateIssuance::query()->count());
    }

    public function test_delayed_psa_rejects_a_missing_sex_without_a_resident_profile(): void
    {
        $this->requireTemplate();

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)
            ->from('/clerk/certificates')
            ->post('/clerk/generate-certificate', $this->validPayload([
                'sex' => '',
            ]));

        $response->assertSessionHasErrors('sex');
        $this->assertSame(0, CertificateIssuance::query()->count());
    }

    public function test_delayed_psa_rejects_a_missing_purok_without_a_resident_profile(): void
    {
        $this->requireTemplate();

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)
            ->from('/clerk/certificates')
            ->post('/clerk/generate-certificate', $this->validPayload([
                'purok' => '',
            ]));

        $response->assertSessionHasErrors('purok');
        $this->assertSame(0, CertificateIssuance::query()->count());
    }

    public function test_delayed_psa_rejects_an_unrecognised_sex(): void
    {
        $this->requireTemplate();

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)
            ->from('/clerk/certificates')
            ->post('/clerk/generate-certificate', $this->validPayload([
                'sex' => 'UNKNOWN',
            ]));

        $response->assertSessionHasErrors('sex');
        $this->assertSame(0, CertificateIssuance::query()->count());
    }

    public function test_delayed_psa_rejects_a_residency_year_below_the_calendar_floor(): void
    {
        $this->requireTemplate();

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)
            ->from('/clerk/certificates')
            ->post('/clerk/generate-certificate', $this->validPayload([
                'residency_start_year' => (string) (DelayedPsaCertificate::EARLIEST_RESIDENCY_YEAR - 1),
            ]));

        $response->assertSessionHasErrors('residency_start_year');
        $this->assertSame(0, CertificateIssuance::query()->count());
    }

    public function test_delayed_psa_rejects_a_residency_year_in_the_future(): void
    {
        $this->requireTemplate();

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)
            ->from('/clerk/certificates')
            ->post('/clerk/generate-certificate', $this->validPayload([
                'residency_start_year' => (string) ((int) date('Y') + 1),
            ]));

        $response->assertSessionHasErrors('residency_start_year');
        $this->assertSame(0, CertificateIssuance::query()->count());
    }

    public function test_delayed_psa_rejects_a_residency_year_earlier_than_the_birth_year(): void
    {
        $this->requireTemplate();

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)
            ->from('/clerk/certificates')
            ->post('/clerk/generate-certificate', $this->validPayload([
                'date_of_birth' => '1990-01-05',
                'residency_start_year' => '1989',
            ]));

        $response->assertSessionHasErrors('residency_start_year');
        $this->assertSame(0, CertificateIssuance::query()->count());
    }

    public function test_delayed_psa_resident_profile_supplies_the_birth_date_sex_and_purok(): void
    {
        $this->requireTemplate();

        $resident = $this->createResident($this->barangay, $this->purok);
        $clerk = $this->createStaff($this->barangay, 'clerk');

        // The clerk-entered values deliberately disagree with the profile; the
        // profile is authoritative.
        $response = $this->actingAs($clerk)
            ->from('/clerk/certificates')
            ->post('/clerk/generate-certificate', $this->validPayload([
                'resident_id' => $resident->id,
                'date_of_birth' => '',
                'sex' => '',
                'purok' => '',
            ]));

        $response->assertSessionHasNoErrors();
        $this->assertSame(1, CertificateIssuance::query()->count());

        $issuance = CertificateIssuance::query()->first();

        $this->assertSame($resident->id, $issuance->resident_id);
        $this->assertSame('Purok 1', $issuance->purok);
    }

    public function test_delayed_psa_falls_back_to_the_clerk_entered_values_without_a_resident_profile(): void
    {
        $this->requireTemplate();

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)
            ->from('/clerk/certificates')
            ->post('/clerk/generate-certificate', $this->validPayload([
                'date_of_birth' => '1990-01-05',
                'sex' => 'FEMALE',
                'purok' => 'Purok 9',
            ]));

        $response->assertSessionHasNoErrors();

        $issuance = CertificateIssuance::query()->first();

        $this->assertNull($issuance->resident_id);
        $this->assertSame('Purok 9', $issuance->purok);
    }

    public function test_delayed_psa_ignores_a_resident_from_another_barangay(): void
    {
        $this->requireTemplate();

        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $otherPurok = $this->createPurok($otherBarangay, 'Purok 2');
        $foreignResident = $this->createResident($otherBarangay, $otherPurok);

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)
            ->from('/clerk/certificates')
            ->post('/clerk/generate-certificate', $this->validPayload([
                'resident_id' => $foreignResident->id,
                'date_of_birth' => '',
                'sex' => '',
                'purok' => '',
            ]));

        $response->assertSessionHasErrors(['date_of_birth', 'sex', 'purok']);
        $this->assertSame(0, CertificateIssuance::query()->count());
    }

    public function test_delayed_psa_persists_one_issuance_without_the_transient_details(): void
    {
        $this->requireTemplate();

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->post('/clerk/generate-certificate', $this->validPayload())
            ->assertOk();

        $this->assertSame(1, CertificateIssuance::query()->count());

        $issuance = CertificateIssuance::query()->first();

        $this->assertSame('Delayed PSA Registration', $issuance->certificate_type);
        $this->assertSame('Juan Dela Cruz', $issuance->resident_name);
        $this->assertSame('Purok 1', $issuance->purok);
        $this->assertSame($this->barangay->id, $issuance->barangay_id);
        $this->assertSame($clerk->id, $issuance->issued_by);

        // The transient Delayed PSA details must not be persisted anywhere.
        $attributes = $issuance->getAttributes();

        foreach ([
            'date_of_birth',
            'sex',
            'nationality',
            'birth_place',
            'father_name',
            'mother_maiden_name',
            'parents_marriage_details',
            'residency_start_year',
        ] as $transient) {
            $this->assertArrayNotHasKey($transient, $attributes);
        }
    }

    public function test_delayed_psa_resident_details_endpoint_returns_the_certificate_values(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok);
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)
            ->getJson("/clerk/certificates/resident-details/{$resident->id}");

        $response->assertOk();
        $response->assertJsonPath('resident.id', $resident->id);
        $response->assertJsonPath('resident.full_name', 'Juan Dela Cruz');
        $response->assertJsonPath('resident.birthdate', '1990-01-01');
        $response->assertJsonPath('resident.sex', 'Male');
        $response->assertJsonPath('resident.purok', 'Purok 1');
    }

    public function test_delayed_psa_resident_details_endpoint_hides_a_foreign_resident(): void
    {
        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $otherPurok = $this->createPurok($otherBarangay, 'Purok 2');
        $foreignResident = $this->createResident($otherBarangay, $otherPurok);

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->getJson("/clerk/certificates/resident-details/{$foreignResident->id}")
            ->assertNotFound();
    }

    public function test_delayed_psa_resident_details_endpoint_hides_an_inactive_resident(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok);
        $resident->update(['status' => 'inactive']);

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->getJson("/clerk/certificates/resident-details/{$resident->id}")
            ->assertNotFound();
    }

    public function test_delayed_psa_resident_details_endpoint_requires_an_authenticated_staff_account(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok);

        $this->get("/clerk/certificates/resident-details/{$resident->id}")
            ->assertRedirect(route('login'));

        $otherRole = $this->createStaff($this->barangay, 'pwd');

        $this->actingAs($otherRole)
            ->get("/clerk/certificates/resident-details/{$resident->id}")
            ->assertForbidden();
    }

    public function test_delayed_psa_generation_is_restricted_to_clerk_staff(): void
    {
        $this->requireTemplate();

        $otherRole = $this->createStaff($this->barangay, 'pwd');

        $this->actingAs($otherRole)
            ->post('/clerk/generate-certificate', $this->validPayload())
            ->assertForbidden();

        $this->assertSame(0, CertificateIssuance::query()->count());
    }

    public function test_delayed_psa_frontend_gates_the_purpose_and_signatory_inputs(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/pages/Clerk/Certificates.tsx'));

        // The Delayed PSA detail block exists and is rendered for that type.
        $this->assertStringContainsString("const DELAYED_PSA_TYPE = 'Delayed PSA Registration';", $source);
        $this->assertStringContainsString('renderDelayedPsaFields', $source);

        // The purpose input and the signatory selector are hidden for the
        // Delayed PSA certificate, which states its own purpose and carries
        // its own signature blocks.
        $this->assertStringContainsString(
            'data.certificate_type !== DELAYED_PSA_TYPE && (',
            $source
        );

        // The reviewed details must be confirmed before the certificate can be
        // downloaded.
        $this->assertStringContainsString("confirmation.name = 'details_confirmed';", $source);
        $this->assertStringContainsString('detailsConfirmed', $source);
        $this->assertStringContainsString('canIssueCertificate', $source);

        // Every reviewed detail is collected.
        foreach ([
            'date_of_birth',
            'sex',
            'nationality',
            'birth_place',
            'purok',
            'father_name',
            'mother_maiden_name',
            'parents_marriage_details',
            'residency_start_year',
        ] as $field) {
            $this->assertStringContainsString($field, $source, "Field [{$field}] is missing from the form.");
        }
    }

    public function test_delayed_psa_catalog_row_is_declared_with_the_agreed_fee(): void
    {
        $source = (string) file_get_contents(base_path('database/seeders/CertificateSeeder.php'));

        $this->assertStringContainsString("'code' => 'delayed-psa-registration'", $source);
        $this->assertStringContainsString("'name' => 'Delayed PSA Registration'", $source);
        $this->assertStringContainsString("'fee' => 50", $source);
    }

    public function test_delayed_psa_date_of_birth_uses_the_formal_long_form(): void
    {
        $this->assertSame(
            '5th day of January 1990',
            DelayedPsaCertificate::formatDateOfBirth(Carbon::create(1990, 1, 5))
        );

        $this->assertSame(
            '1st day of January 1990',
            DelayedPsaCertificate::formatDateOfBirth(Carbon::create(1990, 1, 1))
        );

        $this->assertSame(
            '22nd day of March 1990',
            DelayedPsaCertificate::formatDateOfBirth(Carbon::create(1990, 3, 22))
        );
    }

    public function test_delayed_psa_sex_is_normalized_to_the_template_form(): void
    {
        $this->assertSame('MALE', DelayedPsaCertificate::normalizeSex('Male'));
        $this->assertSame('FEMALE', DelayedPsaCertificate::normalizeSex('female'));
        $this->assertNull(DelayedPsaCertificate::normalizeSex('unknown'));
    }

    public function test_delayed_psa_residency_period_is_rendered_up_to_present(): void
    {
        $this->assertSame('1995 up to present', DelayedPsaCertificate::formatResidencyPeriod(1995));
    }

    public function test_delayed_psa_earliest_residency_year_follows_the_birth_year(): void
    {
        $this->assertSame(
            DelayedPsaCertificate::EARLIEST_RESIDENCY_YEAR,
            DelayedPsaCertificate::earliestResidencyYear(null)
        );

        $this->assertSame(
            1990,
            DelayedPsaCertificate::earliestResidencyYear(Carbon::create(1990, 1, 1))
        );

        $this->assertSame(
            DelayedPsaCertificate::EARLIEST_RESIDENCY_YEAR,
            DelayedPsaCertificate::earliestResidencyYear(Carbon::create(1000, 1, 1))
        );
    }

    private function requireTemplate(): void
    {
        if (! CertificateTemplates::isAvailable(DelayedPsaCertificate::TYPE)) {
            $this->markTestSkipped('The Delayed PSA template is not present in this environment.');
        }
    }

    private function templatePath(): string
    {
        return CertificateTemplates::path(DelayedPsaCertificate::TYPE);
    }

    /**
     * Write a throwaway DOCX that exposes exactly the given merge fields.
     *
     * @param  array<int, string>  $keys
     */
    private function writeTemplateWithKeys(array $keys): string
    {
        $path = tempnam(sys_get_temp_dir(), 'delayed_psa_template_').'.docx';

        $document = new PhpWord;
        $section = $document->addSection();

        foreach ($keys as $key) {
            $section->addText('${'.$key.'}');
        }

        $document->save($path);

        return $path;
    }

    private function archiveDocumentXml(string $path): string|false
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return false;
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        return $xml;
    }

    /**
     * @return array<string, string|int>
     */
    private function resolvedValues(): array
    {
        return [
            'control_number' => 'CTRL-2026-0042',
            'resident_name' => 'Juan Dela Cruz',
            'purok' => 'Purok 1',
            'date_of_birth' => '1990-01-01',
            'sex' => 'MALE',
            'nationality' => 'Filipino',
            'birth_place' => 'Puerto Princesa City',
            'father_name' => 'Pedro Dela Cruz',
            'mother_maiden_name' => 'Maria Dela Cruz',
            'parents_marriage_details' => '12 June 1985, Puerto Princesa City',
            'residency_start_year' => 1995,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'certificate_type' => 'Delayed PSA Registration',
            'resident_name' => 'Juan Dela Cruz',
            'purok' => 'Purok 1',
            'purpose' => '',
            'control_no' => 'CTRL-2026-0042',
            'fee' => '50',
            'signatory_name' => '',
            'signatory_title' => '',
            'date_of_birth' => '1990-01-01',
            'sex' => 'MALE',
            'nationality' => 'FILIPINO',
            'birth_place' => 'PUERTO PRINCESA CITY',
            'father_name' => 'Pedro Dela Cruz',
            'mother_maiden_name' => 'Maria Dela Cruz',
            'parents_marriage_details' => '12 June 1985, Puerto Princesa City',
            'residency_start_year' => '1995',
            'details_confirmed' => '1',
        ], $overrides);
    }
}
