<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\CertificateIssuance;
use App\Models\Purok;
use App\Support\CertificateTemplates;
use App\Support\MarriageCohabitationCertificate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\TemplateProcessor;
use RuntimeException;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;
use ZipArchive;

class MarriageCohabitationCertificateIntegrationTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Purok $purok;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $this->purok = $this->createPurok($this->barangay, 'Masunurin');
    }

    public function test_marriage_template_is_available_and_downloads(): void
    {
        $this->requireTemplate();

        $resident = $this->createResident($this->barangay, $this->purok);
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)
            ->post('/clerk/generate-certificate', $this->validPayload([
                'resident_id' => $resident->id,
            ]));

        $response->assertOk();
        $this->assertSourceContainsString(
            'attachment',
            (string) $response->headers->get('content-disposition')
        );
    }

    public function test_marriage_template_exposes_exactly_the_approved_merge_fields(): void
    {
        $this->requireTemplate();

        $variables = (new TemplateProcessor($this->templatePath()))->getVariables();
        sort($variables);

        $expected = MarriageCohabitationCertificate::TEMPLATE_KEYS;
        sort($expected);

        $this->assertSame($expected, $variables);

        // The template states its own purpose and carries its own signature
        // blocks, so it must not expose the generic merge fields.
        $this->assertNotContains('purpose', $variables);
        $this->assertNotContains('signatory_name', $variables);
        $this->assertNotContains('signatory_title', $variables);
        $this->assertNotContains('control_no', $variables);
        $this->assertNotContains('fee', $variables);
    }

    public function test_marriage_template_contract_assertion_accepts_the_provisioned_template(): void
    {
        $this->requireTemplate();

        MarriageCohabitationCertificate::assertTemplateContract($this->templatePath());

        $this->assertTrue(true);
    }

    public function test_marriage_template_contract_assertion_rejects_a_missing_merge_field(): void
    {
        $path = $this->writeTemplateWithKeys(['resident_age', 'purok']);

        try {
            MarriageCohabitationCertificate::assertTemplateContract($path);

            $this->fail('A template with a missing merge field must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSourceContainsString('contract', $exception->getMessage());
        } finally {
            @unlink($path);
        }
    }

    public function test_marriage_template_contract_assertion_rejects_an_extra_merge_field(): void
    {
        $keys = MarriageCohabitationCertificate::TEMPLATE_KEYS;
        $keys[] = 'purpose';

        $path = $this->writeTemplateWithKeys($keys);

        try {
            MarriageCohabitationCertificate::assertTemplateContract($path);

            $this->fail('A template with an extra merge field must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSourceContainsString('contract', $exception->getMessage());
        } finally {
            @unlink($path);
        }
    }

    public function test_marriage_misprovisioned_template_is_rejected_before_a_document_is_issued(): void
    {
        $this->requireTemplate();

        $resident = $this->createResident($this->barangay, $this->purok);
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $original = (string) file_get_contents($this->templatePath());
        $broken = $this->writeTemplateWithKeys(['resident_age', 'purok']);

        try {
            // Swap in a template that does not honour the merge-field contract.
            copy($broken, $this->templatePath());

            $response = $this->actingAs($clerk)
                ->from('/clerk/certificates')
                ->post('/clerk/generate-certificate', $this->validPayload([
                    'resident_id' => $resident->id,
                ]));

            $response->assertSessionHasErrors('certificate_type');
            $this->assertSame(0, CertificateIssuance::query()->count());
        } finally {
            file_put_contents($this->templatePath(), $original);
            @unlink($broken);
        }
    }

    public function test_marriage_generation_resolves_every_placeholder(): void
    {
        $this->requireTemplate();

        $processor = new TemplateProcessor($this->templatePath());

        foreach (MarriageCohabitationCertificate::templateValues($this->resolvedValues()) as $key => $value) {
            $processor->setValue($key, $value);
        }

        $output = tempnam(sys_get_temp_dir(), 'marriage_render_').'.docx';

        try {
            $processor->saveAs($output);

            $xml = $this->archiveDocumentXml($output);

            $this->assertNotFalse($xml, 'The rendered Marriage document is not a readable DOCX archive.');

            foreach (MarriageCohabitationCertificate::TEMPLATE_KEYS as $key) {
                $this->assertStringNotContainsString(
                    '${'.$key.'}',
                    $xml,
                    "Unresolved merge field [{$key}] remains in the generated document."
                );
            }

            $text = $this->documentText($output);

            // The printed values must be present in the document.
            $this->assertSourceContainsString('JUAN DELA CRUZ', $text);
            $this->assertSourceContainsString('MARIA SANTOS', $text);
            $this->assertSourceContainsString('PUROK MASUNURIN', $text);
            $this->assertSourceContainsString('JUAN DELA CRUZ, 30 and MARIA SANTOS', $text);
            $this->assertSourceContainsString('living together for 5', $text);
            $this->assertSourceContainsString('Marriage Purposes', $text);
            $this->assertSourceContainsString(
                MarriageCohabitationCertificate::formatIssueDate(),
                $text
            );
        } finally {
            @unlink($output);
        }
    }

    public function test_marriage_requires_a_linked_resident(): void
    {
        $this->requireTemplate();

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)
            ->from('/clerk/certificates')
            ->post('/clerk/generate-certificate', $this->validPayload());

        $response->assertSessionHasErrors('resident_id');
        $this->assertSame(0, CertificateIssuance::query()->count());
    }

    public function test_marriage_ignores_a_resident_from_another_barangay(): void
    {
        $this->requireTemplate();

        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $otherPurok = $this->createPurok($otherBarangay, 'Masunurin');
        $foreignResident = $this->createResident($otherBarangay, $otherPurok);

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)
            ->from('/clerk/certificates')
            ->post('/clerk/generate-certificate', $this->validPayload([
                'resident_id' => $foreignResident->id,
            ]));

        $response->assertSessionHasErrors('resident_id');
        $this->assertSame(0, CertificateIssuance::query()->count());
    }

    public function test_marriage_uses_the_linked_resident_profile_for_the_name_age_and_purok(): void
    {
        $this->requireTemplate();

        $resident = $this->createResident($this->barangay, $this->purok);
        $resident->update([
            'birthdate' => Carbon::now()->subYears(30)->toDateString(),
        ]);

        $clerk = $this->createStaff($this->barangay, 'clerk');

        // The clerk-entered values deliberately disagree with the profile; the
        // profile is authoritative.
        $response = $this->actingAs($clerk)
            ->from('/clerk/certificates')
            ->post('/clerk/generate-certificate', $this->validPayload([
                'resident_id' => $resident->id,
                'resident_name' => 'Spoofed Resident',
                'purok' => 'Purok 9',
            ]));

        $response->assertSessionHasNoErrors();

        $issuance = CertificateIssuance::query()->first();

        $this->assertNotNull($issuance);
        $this->assertSame($resident->id, $issuance->resident_id);
        $this->assertSame('Juan Dela Cruz', $issuance->resident_name);
        $this->assertSame('Masunurin', $issuance->purok);

        $document = $this->latestGeneratedCertificate();

        try {
            $this->assertNotNull($document, 'The generated certificate document was not found.');

            $text = $this->documentText((string) $document);

            $this->assertSourceContainsString('JUAN DELA CRUZ, 30 and MARIA SANTOS', $text);
            $this->assertSourceContainsString('PUROK MASUNURIN', $text);
            $this->assertStringNotContainsString('Spoofed Resident', $text);
            $this->assertStringNotContainsString('PUROK PUROK 9', $text);
        } finally {
            if ($document !== null) {
                @unlink($document);
            }
        }
    }

    public function test_marriage_rejects_a_missing_partner_full_name(): void
    {
        $this->requireTemplate();

        $resident = $this->createResident($this->barangay, $this->purok);
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)
            ->from('/clerk/certificates')
            ->post('/clerk/generate-certificate', $this->validPayload([
                'resident_id' => $resident->id,
                'partner_full_name' => '',
            ]));

        $response->assertSessionHasErrors('partner_full_name');
        $this->assertSame(0, CertificateIssuance::query()->count());
    }

    public function test_marriage_rejects_a_missing_partner_age(): void
    {
        $this->requireTemplate();

        $resident = $this->createResident($this->barangay, $this->purok);
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)
            ->from('/clerk/certificates')
            ->post('/clerk/generate-certificate', $this->validPayload([
                'resident_id' => $resident->id,
                'partner_age' => '',
            ]));

        $response->assertSessionHasErrors('partner_age');
        $this->assertSame(0, CertificateIssuance::query()->count());
    }

    public function test_marriage_rejects_a_missing_number_of_years_living_together(): void
    {
        $this->requireTemplate();

        $resident = $this->createResident($this->barangay, $this->purok);
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)
            ->from('/clerk/certificates')
            ->post('/clerk/generate-certificate', $this->validPayload([
                'resident_id' => $resident->id,
                'years_living_together' => '',
            ]));

        $response->assertSessionHasErrors('years_living_together');
        $this->assertSame(0, CertificateIssuance::query()->count());
    }

    public function test_marriage_rejects_a_partner_age_outside_the_allowed_range(): void
    {
        $this->requireTemplate();

        $resident = $this->createResident($this->barangay, $this->purok);
        $clerk = $this->createStaff($this->barangay, 'clerk');

        foreach (['0', '121', '29.5', 'twenty nine'] as $partnerAge) {
            $response = $this->actingAs($clerk)
                ->from('/clerk/certificates')
                ->post('/clerk/generate-certificate', $this->validPayload([
                    'resident_id' => $resident->id,
                    'partner_age' => $partnerAge,
                ]));

            $response->assertSessionHasErrors('partner_age');
        }

        $this->assertSame(0, CertificateIssuance::query()->count());
    }

    public function test_marriage_rejects_a_number_of_years_living_together_outside_the_allowed_range(): void
    {
        $this->requireTemplate();

        $resident = $this->createResident($this->barangay, $this->purok);
        $clerk = $this->createStaff($this->barangay, 'clerk');

        foreach (['-1', '121', '5.5', 'five'] as $years) {
            $response = $this->actingAs($clerk)
                ->from('/clerk/certificates')
                ->post('/clerk/generate-certificate', $this->validPayload([
                    'resident_id' => $resident->id,
                    'years_living_together' => $years,
                ]));

            $response->assertSessionHasErrors('years_living_together');
        }

        $this->assertSame(0, CertificateIssuance::query()->count());
    }

    public function test_marriage_does_not_require_a_purpose_or_a_signatory(): void
    {
        $this->requireTemplate();

        $resident = $this->createResident($this->barangay, $this->purok);
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)
            ->from('/clerk/certificates')
            ->post('/clerk/generate-certificate', $this->validPayload([
                'resident_id' => $resident->id,
                'purpose' => '',
                'signatory_name' => '',
                'signatory_title' => '',
            ]));

        $response->assertSessionHasNoErrors();
        $this->assertSame(1, CertificateIssuance::query()->count());
    }

    public function test_marriage_persists_one_issuance_without_the_transient_partner_details(): void
    {
        $this->requireTemplate();

        $resident = $this->createResident($this->barangay, $this->purok);
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->post('/clerk/generate-certificate', $this->validPayload([
                'resident_id' => $resident->id,
            ]))
            ->assertOk();

        $this->assertSame(1, CertificateIssuance::query()->count());

        $issuance = CertificateIssuance::query()->first();

        $this->assertSame('Marriage Cohabitation', $issuance->certificate_type);
        $this->assertSame('Juan Dela Cruz', $issuance->resident_name);
        $this->assertSame('Masunurin', $issuance->purok);
        $this->assertSame($this->barangay->id, $issuance->barangay_id);
        $this->assertSame($clerk->id, $issuance->issued_by);

        // The transient partner details must not be persisted anywhere.
        $attributes = $issuance->getAttributes();

        foreach ([
            'partner_full_name',
            'partner_age',
            'years_living_together',
        ] as $transient) {
            $this->assertArrayNotHasKey($transient, $attributes);
        }
    }

    public function test_marriage_generation_is_restricted_to_clerk_staff(): void
    {
        $this->requireTemplate();

        $resident = $this->createResident($this->barangay, $this->purok);
        $otherRole = $this->createStaff($this->barangay, 'pwd');

        $this->actingAs($otherRole)
            ->post('/clerk/generate-certificate', $this->validPayload([
                'resident_id' => $resident->id,
            ]))
            ->assertForbidden();

        $this->assertSame(0, CertificateIssuance::query()->count());
    }

    public function test_marriage_frontend_collects_the_partner_details_and_hides_the_purpose_and_signatory_inputs(): void
    {
        $source = (string) preg_replace(
            '/\s+/',
            ' ',
            (string) file_get_contents(base_path('resources/js/pages/Clerk/Certificates.tsx')),
        );

        // The Marriage detail block exists and is rendered for that type.
        $this->assertSourceContainsString("const MARRIAGE_TYPE = 'Marriage Cohabitation';", $source);
        $this->assertSourceContainsString('renderMarriageFields', $source);

        // The partner details are collected.
        foreach ([
            'partner_full_name',
            'partner_age',
            'years_living_together',
        ] as $field) {
            $this->assertSourceContainsString($field, $source, "Field [{$field}] is missing from the form.");
        }

        // The purpose input and the signatory selector are hidden for the
        // Marriage certificate, which states its own purpose and carries its
        // own signature blocks.
        $this->assertSourceContainsString(
            'data.certificate_type !== MARRIAGE_TYPE && !isResidency && (',
            $source
        );

        // The Delayed PSA gating must stay intact.
        $this->assertSourceContainsString(
            'data.certificate_type !== DELAYED_PSA_TYPE && (',
            $source
        );

        // The certificate cannot be issued until the partner details are
        // complete.
        $this->assertSourceContainsString('marriageDetailsComplete', $source);
        $this->assertSourceContainsString('canIssueCertificate', $source);
    }

    public function test_marriage_purok_uses_the_template_prefix(): void
    {
        $this->assertSame('PUROK MASUNURIN', MarriageCohabitationCertificate::formatPurok('Masunurin'));
        $this->assertSame('PUROK 1', MarriageCohabitationCertificate::formatPurok('Purok 1'));
        $this->assertSame('', MarriageCohabitationCertificate::formatPurok('  '));
    }

    public function test_marriage_issue_date_uses_the_formal_long_form(): void
    {
        $this->assertSame(
            date('jS \d\a\y \o\f F Y'),
            MarriageCohabitationCertificate::formatIssueDate()
        );
    }

    public function test_marriage_resident_age_is_computed_from_the_birthdate(): void
    {
        $this->assertSame(
            30,
            MarriageCohabitationCertificate::ageFromBirthdate(Carbon::now()->subYears(30))
        );

        $this->assertSame(
            29,
            MarriageCohabitationCertificate::ageFromBirthdate(Carbon::now()->subYears(30)->addDay())
        );

        $this->assertSame(
            0,
            MarriageCohabitationCertificate::ageFromBirthdate(Carbon::now())
        );
    }

    private function requireTemplate(): void
    {
        if (! CertificateTemplates::isAvailable(MarriageCohabitationCertificate::TYPE)) {
            $this->markTestSkipped('The Marriage Cohabitation template is not present in this environment.');
        }
    }

    private function templatePath(): string
    {
        return CertificateTemplates::path(MarriageCohabitationCertificate::TYPE);
    }

    /**
     * Write a throwaway DOCX that exposes exactly the given merge fields.
     *
     * @param  array<int, string>  $keys
     */
    private function writeTemplateWithKeys(array $keys): string
    {
        $path = tempnam(sys_get_temp_dir(), 'marriage_template_').'.docx';

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
     * The readable text of a generated DOCX document.
     */
    private function documentText(string $path): string
    {
        $xml = $this->archiveDocumentXml($path);

        if ($xml === false) {
            return '';
        }

        $document = new \DOMDocument;
        $document->loadXML($xml);

        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $text = '';

        foreach ($xpath->query('//w:t') as $node) {
            $text .= $node->textContent;
        }

        return $text;
    }

    /**
     * The certificate document written by the most recent generation.
     */
    private function latestGeneratedCertificate(): ?string
    {
        $files = glob(storage_path('app/public/Certificate_*.docx')) ?: [];

        if ($files === []) {
            return null;
        }

        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $files[0];
    }

    /**
     * @return array<string, string|int|null>
     */
    private function resolvedValues(): array
    {
        return [
            'resident_name' => 'Juan Dela Cruz',
            'resident_age' => 30,
            'purok' => 'Masunurin',
            'partner_full_name' => 'Maria Santos',
            'partner_age' => 29,
            'years_living_together' => 5,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'certificate_type' => 'Marriage Cohabitation',
            'resident_id' => '',
            'resident_name' => 'Juan Dela Cruz',
            'purok' => 'Masunurin',
            'purpose' => '',
            'control_no' => 'CTRL-2026-0001',
            'fee' => '50',
            'signatory_name' => '',
            'signatory_title' => '',
            'partner_full_name' => 'Maria Santos',
            'partner_age' => '29',
            'years_living_together' => '5',
        ], $overrides);
    }
}
