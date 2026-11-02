<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\CertificateIssuance;
use App\Models\Purok;
use App\Support\CertificateTemplates;
use App\Support\ResidencyCertificate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\TemplateProcessor;
use RuntimeException;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;
use ZipArchive;

class ResidencyCertificateIntegrationTest extends TestCase
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

    public function test_residency_template_is_available_and_downloads(): void
    {
        $this->requireTemplate();

        $resident = $this->createResident($this->barangay, $this->purok);
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)
            ->post('/clerk/generate-certificate', $this->validPayload([
                'resident_id' => $resident->id,
            ]));

        $response->assertOk();
        $this->assertStringContainsString(
            'attachment',
            (string) $response->headers->get('content-disposition')
        );
    }

    public function test_residency_preview_downloads_the_rendered_document_without_issuing(): void
    {
        $this->requireTemplate();

        $resident = $this->createResident($this->barangay, $this->purok);
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->post('/clerk/certificates/preview', $this->validPayload([
                'resident_id' => $resident->id,
            ]))
            ->assertOk()
            ->assertHeader(
                'Content-Type',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            );

        $this->assertSame(0, CertificateIssuance::query()->count());
    }

    public function test_residency_template_exposes_exactly_the_approved_merge_fields(): void
    {
        $this->requireTemplate();

        $variables = (new TemplateProcessor($this->templatePath()))->getVariables();
        sort($variables);

        $expected = ResidencyCertificate::TEMPLATE_KEYS;
        sort($expected);

        $this->assertSame($expected, $variables);

        // The template states its own purpose and carries its own signature
        // blocks, and it prints no control number, so it must not expose the
        // generic merge fields.
        $this->assertNotContains('purpose', $variables);
        $this->assertContains('signatory_name', $variables);
        $this->assertNotContains('signatory_title', $variables);
        $this->assertNotContains('control_no', $variables);
        $this->assertNotContains('fee', $variables);
    }

    public function test_residency_template_contract_assertion_accepts_the_provisioned_template(): void
    {
        $this->requireTemplate();

        ResidencyCertificate::assertTemplateContract($this->templatePath());

        $this->assertTrue(true);
    }

    public function test_residency_template_contract_assertion_rejects_a_missing_merge_field(): void
    {
        $path = $this->writeTemplateWithKeys(['purok', 'years_of_residency']);

        try {
            ResidencyCertificate::assertTemplateContract($path);

            $this->fail('A template with a missing merge field must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('contract', $exception->getMessage());
        } finally {
            @unlink($path);
        }
    }

    public function test_residency_template_contract_assertion_rejects_an_extra_merge_field(): void
    {
        $keys = ResidencyCertificate::TEMPLATE_KEYS;
        $keys[] = 'purpose';

        $path = $this->writeTemplateWithKeys($keys);

        try {
            ResidencyCertificate::assertTemplateContract($path);

            $this->fail('A template with an extra merge field must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('contract', $exception->getMessage());
        } finally {
            @unlink($path);
        }
    }

    public function test_residency_misprovisioned_template_is_rejected_before_a_document_is_issued(): void
    {
        $this->requireTemplate();

        $resident = $this->createResident($this->barangay, $this->purok);
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $original = (string) file_get_contents($this->templatePath());
        $broken = $this->writeTemplateWithKeys(['purok', 'years_of_residency']);

        try {
            // Swap in a template that does not honour the merge-field contract.
            copy($broken, $this->templatePath());

            $this->actingAs($clerk)
                ->postJson('/clerk/certificates/preview', $this->validPayload([
                    'resident_id' => $resident->id,
                ]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('certificate_type');

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

    public function test_residency_generation_resolves_every_placeholder(): void
    {
        $this->requireTemplate();

        $processor = new TemplateProcessor($this->templatePath());

        foreach (ResidencyCertificate::templateValues($this->resolvedValues()) as $key => $value) {
            $processor->setValue($key, $value);
        }

        $output = tempnam(sys_get_temp_dir(), 'residency_render_').'.docx';

        try {
            $processor->saveAs($output);

            $xml = $this->archiveDocumentXml($output);

            $this->assertNotFalse($xml, 'The rendered Residency document is not a readable DOCX archive.');

            foreach (ResidencyCertificate::TEMPLATE_KEYS as $key) {
                $this->assertStringNotContainsString(
                    '${'.$key.'}',
                    $xml,
                    "Unresolved merge field [{$key}] remains in the generated document."
                );
            }

            $text = $this->documentText($output);

            // The printed values must be present in the document.
            $this->assertStringContainsString('JUAN DELA CRUZ', $text);
            $this->assertStringContainsString('PUROK MASUNURIN', $text);
            $this->assertMatchesRegularExpression(
                '/is a resident of this barangay for\s+5\s+years/',
                $text
            );
            $this->assertStringContainsString(
                ResidencyCertificate::formatIssueDate(),
                $text
            );
        } finally {
            @unlink($output);
        }
    }

    public function test_residency_document_prints_no_honorific_and_no_control_number(): void
    {
        $this->requireTemplate();

        $resident = $this->createResident($this->barangay, $this->purok);
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)
            ->post('/clerk/generate-certificate', $this->validPayload([
                'resident_id' => $resident->id,
            ]));

        $response->assertOk();

        $document = $response->baseResponse->getFile()?->getPathname();

        try {
            $this->assertNotNull($document, 'The generated certificate document was not found.');

            $text = $this->documentText((string) $document);

            // No honorific is invented for the resident.
            $this->assertStringNotContainsString('Mr.', $text);
            $this->assertStringNotContainsString('Ms.', $text);

            // The template prints no control number, so the issued document
            // must not carry the clerk-side control number.
            $this->assertStringNotContainsString('CTRL-2026-0001', $text);
        } finally {
            if ($document !== null) {
                @unlink($document);
            }
        }
    }

    public function test_residency_uses_the_server_date_and_ignores_a_client_supplied_date(): void
    {
        $this->requireTemplate();

        $resident = $this->createResident($this->barangay, $this->purok);
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)
            ->post('/clerk/generate-certificate', $this->validPayload([
                'resident_id' => $resident->id,
                'current_date' => '1st day of January 1999',
            ]));

        $response->assertOk();

        $document = $response->baseResponse->getFile()?->getPathname();

        try {
            $this->assertNotNull($document, 'The generated certificate document was not found.');

            $text = $this->documentText((string) $document);

            $this->assertStringContainsString(
                ResidencyCertificate::formatIssueDate(),
                $text
            );
            $this->assertStringNotContainsString('1st day of January 1999', $text);
        } finally {
            if ($document !== null) {
                @unlink($document);
            }
        }
    }

    public function test_residency_requires_a_linked_resident(): void
    {
        $this->requireTemplate();

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)
            ->from('/clerk/certificates')
            ->post('/clerk/generate-certificate', $this->validPayload());

        $response->assertSessionHasErrors('resident_id');
        $this->assertSame(0, CertificateIssuance::query()->count());
    }

    public function test_residency_ignores_a_resident_from_another_barangay(): void
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

    public function test_residency_rejects_a_resident_who_is_not_active(): void
    {
        $this->requireTemplate();

        $resident = $this->createResident($this->barangay, $this->purok);
        $resident->update(['status' => 'inactive']);

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)
            ->from('/clerk/certificates')
            ->post('/clerk/generate-certificate', $this->validPayload([
                'resident_id' => $resident->id,
            ]));

        $response->assertSessionHasErrors('resident_id');
        $this->assertSame(0, CertificateIssuance::query()->count());
    }

    public function test_residency_uses_the_linked_resident_profile_for_the_name_and_purok(): void
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
                'resident_name' => 'Spoofed Resident',
                'purok' => 'Purok 9',
            ]));

        $response->assertSessionHasNoErrors();

        $issuance = CertificateIssuance::query()->first();

        $this->assertNotNull($issuance);
        $this->assertSame($resident->id, $issuance->resident_id);
        $this->assertSame('Juan Dela Cruz', $issuance->resident_name);
        $this->assertSame('Masunurin', $issuance->purok);

        $document = $response->baseResponse->getFile()?->getPathname();

        try {
            $this->assertNotNull($document, 'The generated certificate document was not found.');

            $text = $this->documentText((string) $document);

            $this->assertStringContainsString('JUAN DELA CRUZ', $text);
            $this->assertStringContainsString('PUROK MASUNURIN', $text);
            $this->assertStringNotContainsString('Spoofed Resident', $text);
            $this->assertStringNotContainsString('PUROK PUROK 9', $text);
        } finally {
            if ($document !== null) {
                @unlink($document);
            }
        }
    }

    public function test_residency_rejects_a_missing_number_of_years(): void
    {
        $this->requireTemplate();

        $resident = $this->createResident($this->barangay, $this->purok);
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)
            ->from('/clerk/certificates')
            ->post('/clerk/generate-certificate', $this->validPayload([
                'resident_id' => $resident->id,
                'years_of_residency' => '',
            ]));

        $response->assertSessionHasErrors('years_of_residency');
        $this->assertSame(0, CertificateIssuance::query()->count());
    }

    public function test_residency_rejects_a_fractional_or_non_numeric_number_of_years(): void
    {
        $this->requireTemplate();

        $resident = $this->createResident($this->barangay, $this->purok);
        $clerk = $this->createStaff($this->barangay, 'clerk');

        foreach (['5.5', 'five', '5 years', ' '] as $years) {
            $response = $this->actingAs($clerk)
                ->from('/clerk/certificates')
                ->post('/clerk/generate-certificate', $this->validPayload([
                    'resident_id' => $resident->id,
                    'years_of_residency' => $years,
                ]));

            $response->assertSessionHasErrors('years_of_residency');
        }

        $this->assertSame(0, CertificateIssuance::query()->count());
    }

    public function test_residency_rejects_a_negative_number_of_years(): void
    {
        $this->requireTemplate();

        $resident = $this->createResident($this->barangay, $this->purok);
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)
            ->from('/clerk/certificates')
            ->post('/clerk/generate-certificate', $this->validPayload([
                'resident_id' => $resident->id,
                'years_of_residency' => '-1',
            ]));

        $response->assertSessionHasErrors('years_of_residency');
        $this->assertSame(0, CertificateIssuance::query()->count());
    }

    public function test_residency_rejects_a_number_of_years_above_the_allowed_range(): void
    {
        $this->requireTemplate();

        $resident = $this->createResident($this->barangay, $this->purok);
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)
            ->from('/clerk/certificates')
            ->post('/clerk/generate-certificate', $this->validPayload([
                'resident_id' => $resident->id,
                'years_of_residency' => '121',
            ]));

        $response->assertSessionHasErrors('years_of_residency');
        $this->assertSame(0, CertificateIssuance::query()->count());
    }

    public function test_residency_accepts_the_boundary_values_of_the_allowed_range(): void
    {
        $this->requireTemplate();

        $resident = $this->createResident($this->barangay, $this->purok);
        $clerk = $this->createStaff($this->barangay, 'clerk');

        foreach (['0', '120'] as $years) {
            $this->actingAs($clerk)
                ->post('/clerk/generate-certificate', $this->validPayload([
                    'resident_id' => $resident->id,
                    'years_of_residency' => $years,
                    'control_no' => 'CTRL-2026-'.str_pad($years, 4, '0', STR_PAD_LEFT),
                ]))
                ->assertOk();
        }

        $this->assertSame(2, CertificateIssuance::query()->count());
    }

    public function test_residency_requires_a_control_number_but_not_an_optional_purpose_or_signatory(): void
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
                'control_no' => '',
            ]));

        $response->assertSessionHasErrors('control_no');
        $this->assertSame(0, CertificateIssuance::query()->count());
    }

    public function test_residency_persists_one_issuance_without_the_transient_years(): void
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

        $this->assertSame('Residency', $issuance->certificate_type);
        $this->assertSame('Juan Dela Cruz', $issuance->resident_name);
        $this->assertSame('Masunurin', $issuance->purok);
        $this->assertSame($this->barangay->id, $issuance->barangay_id);
        $this->assertSame($clerk->id, $issuance->issued_by);

        // The transient number of years must not be persisted anywhere.
        $attributes = $issuance->getAttributes();

        $this->assertArrayNotHasKey('years_of_residency', $attributes);
        $this->assertSame('CTRL-2026-0001', $attributes['control_no']);
    }

    public function test_residency_generation_is_restricted_to_clerk_staff(): void
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

    public function test_residency_frontend_collects_the_years_and_hides_the_purpose_signatory_and_control_number(): void
    {
        $source = (string) preg_replace(
            '/\s+/',
            ' ',
            (string) file_get_contents(base_path('resources/js/pages/Clerk/Certificates.tsx')),
        );

        // The Residency detail block exists and is rendered for that type.
        $this->assertStringContainsString("const RESIDENCY_TYPE = 'Residency';", $source);
        $this->assertStringContainsString('renderResidencyFields', $source);

        // The number of years is collected.
        $this->assertStringContainsString('years_of_residency', $source);

        // The purpose input, the signatory selector, and the control number
        // are hidden for the Residency certificate, which states its own
        // purpose, carries its own signature blocks, and prints no control
        // number.
        $this->assertStringContainsString(
            'data.certificate_type !== MARRIAGE_TYPE && (!isResidency) && (',
            $source
        );
        $this->assertStringContainsString(
            'data.certificate_type !== RESIDENCY_TYPE && (',
            $source
        );

        // The certificate cannot be issued until the resident is linked and
        // the number of years is complete.
        $this->assertStringContainsString('residencyDetailsComplete', $source);
        $this->assertStringContainsString('canIssueCertificate', $source);

        // The Delayed PSA and the Marriage Cohabitation gating must stay
        // intact.
        $this->assertStringContainsString(
            'data.certificate_type !== DELAYED_PSA_TYPE && (',
            $source
        );
        $this->assertStringContainsString('marriageDetailsComplete', $source);
    }

    public function test_residency_purok_uses_the_template_prefix(): void
    {
        $this->assertSame('PUROK MASUNURIN', ResidencyCertificate::formatPurok('Masunurin'));
        $this->assertSame('PUROK 1', ResidencyCertificate::formatPurok('Purok 1'));
        $this->assertSame('', ResidencyCertificate::formatPurok('  '));
    }

    public function test_residency_issue_date_uses_the_formal_long_form(): void
    {
        $this->assertSame(
            date('jS \d\a\y \o\f F Y'),
            ResidencyCertificate::formatIssueDate()
        );
    }

    private function requireTemplate(): void
    {
        if (! CertificateTemplates::isAvailable(ResidencyCertificate::TYPE)) {
            $this->markTestSkipped('The Residency template is not present in this environment.');
        }
    }

    private function templatePath(): string
    {
        return CertificateTemplates::path(ResidencyCertificate::TYPE);
    }

    /**
     * Write a throwaway DOCX that exposes exactly the given merge fields.
     *
     * @param  array<int, string>  $keys
     */
    private function writeTemplateWithKeys(array $keys): string
    {
        $path = tempnam(sys_get_temp_dir(), 'residency_template_').'.docx';

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
     *
     * The paragraphs are kept apart, because the template prints the number
     * of years in a paragraph of its own.
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

        $paragraphs = [];

        foreach ($xpath->query('//w:p') as $paragraph) {
            $text = '';

            foreach ($xpath->query('.//w:t', $paragraph) as $node) {
                $text .= $node->textContent;
            }

            $paragraphs[] = $text;
        }

        return implode("\n", $paragraphs);
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
            'purok' => 'Masunurin',
            'years_of_residency' => 5,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'certificate_type' => 'Residency',
            'resident_id' => '',
            'resident_name' => 'Juan Dela Cruz',
            'purok' => 'Masunurin',
            'purpose' => '',
            'control_no' => 'CTRL-2026-0001',
            'fee' => '50',
            'signatory_name' => '',
            'signatory_title' => '',
            'years_of_residency' => '5',
        ], $overrides);
    }
}
