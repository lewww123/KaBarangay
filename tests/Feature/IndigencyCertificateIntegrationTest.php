<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\CertificateIssuance;
use App\Models\OrgChartPosition;
use App\Support\CertificateTemplates;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpWord\TemplateProcessor;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;
use ZipArchive;

class IndigencyCertificateIntegrationTest extends TestCase
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

    public function test_indigency_template_is_available_and_generates_a_download(): void
    {
        $this->requireIndigencyTemplate();

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)
            ->post('/clerk/generate-certificate', $this->validPayload());

        $response->assertOk();
        $this->assertStringContainsString(
            'attachment',
            (string) $response->headers->get('content-disposition')
        );
        $this->assertStringContainsString(
            '.docx',
            (string) $response->headers->get('content-disposition')
        );
    }

    public function test_indigency_generation_persists_one_issuance(): void
    {
        $this->requireIndigencyTemplate();

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->post('/clerk/generate-certificate', $this->validPayload())
            ->assertOk();

        $this->assertSame(1, CertificateIssuance::query()->count());

        $issuance = CertificateIssuance::query()->first();

        $this->assertSame('Indigency', $issuance->certificate_type);
        $this->assertSame('Juan Dela Cruz', $issuance->resident_name);
        $this->assertSame('Purok 1', $issuance->purok);
        $this->assertSame($this->barangay->id, $issuance->barangay_id);
        $this->assertSame($clerk->id, $issuance->issued_by);
    }

    public function test_indigency_merge_fields_are_resolved_in_the_generated_document(): void
    {
        $this->requireIndigencyTemplate();

        // Render the source template exactly as the controller does for the
        // values it supplies, then confirm every Indigency placeholder is
        // resolved. This exercises the repaired template without depending on
        // the streamed download response internals.
        $processor = new TemplateProcessor(CertificateTemplates::path('Indigency'));

        $values = [
            "resident\u{2019}s_name" => mb_strtoupper('Juan Dela Cruz'),
            'purok' => mb_strtoupper('Purok 1'),
            'purpose' => mb_strtoupper('Financial Assistance'),
            'current_date' => date('jS \d\a\y \o\f F Y'),
            'signatory_name' => mb_strtoupper('Hon. Test'),
        ];

        foreach ($values as $key => $value) {
            $processor->setValue($key, $value);
        }

        $output = tempnam(sys_get_temp_dir(), 'indigency_render_').'.docx';

        try {
            $processor->saveAs($output);

            $xml = $this->archiveDocumentXml($output);

            $this->assertNotFalse($xml, 'The rendered Indigency document is not a readable DOCX archive.');

            foreach ($values as $key => $value) {
                $this->assertStringContainsString($value, $xml, "Supplied value for [{$key}] is missing.");
                $this->assertStringNotContainsString(
                    '${'.$key.'}',
                    $xml,
                    "Unresolved merge field [{$key}] remains in the generated document."
                );
            }
        } finally {
            @unlink($output);
        }
    }

    public function test_indigency_merge_fields_retain_their_official_run_formatting(): void
    {
        $this->requireIndigencyTemplate();

        $source = $this->archiveDocumentXml(CertificateTemplates::path('Indigency'));
        $this->assertNotFalse($source, 'The Indigency template is not a readable DOCX archive.');

        $processor = new TemplateProcessor(CertificateTemplates::path('Indigency'));
        $processor->setValue("resident\u{2019}s_name", 'JUAN DELA CRUZ');
        $processor->setValue('purok', 'PUROK 1');
        $processor->setValue('purpose', 'FINANCIAL ASSISTANCE');

        $output = tempnam(sys_get_temp_dir(), 'indigency_format_').'.docx';

        try {
            $processor->saveAs($output);
            $rendered = $this->archiveDocumentXml($output);
            $this->assertNotFalse($rendered, 'The rendered Indigency document is not a readable DOCX archive.');

            foreach ([
                '${resident’s_name}' => 'JUAN DELA CRUZ',
                '${purok}' => 'PUROK 1',
                '${purpose}' => 'FINANCIAL ASSISTANCE',
            ] as $placeholder => $value) {
                $sourceProperties = $this->runPropertiesForText($source, $placeholder);
                $renderedProperties = $this->runPropertiesForText($rendered, $value);

                $this->assertSame(
                    $sourceProperties,
                    $renderedProperties,
                    "Replacement for [{$placeholder}] changed its official run formatting."
                );
                $this->assertStringContainsString('<w:b/>', $renderedProperties);
                $this->assertStringContainsString('<w:u w:val="single"/>', $renderedProperties);
            }
        } finally {
            @unlink($output);
        }
    }

    public function test_indigency_template_has_no_fragmented_placeholders(): void
    {
        $this->requireIndigencyTemplate();

        $path = CertificateTemplates::path('Indigency');

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'The Indigency template archive could not be opened.');

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        $this->assertNotFalse($xml, 'word/document.xml is missing from the Indigency template.');

        foreach (["resident\u{2019}s_name", 'purok', 'current_date'] as $key) {
            $token = '${'.$key.'}';

            // The token must be intact within a single text node/run, not split
            // across runs (which would leave PHPWord unable to resolve it).
            $this->assertMatchesRegularExpression(
                '/<w:t[^>]*>[^<]*'.preg_quote($token, '/').'[^<]*<\/w:t>/u',
                $xml,
                "Merge field [{$key}] is not contained intact within a single run."
            );
        }

        $this->assertStringContainsString('${resident’s_name}', $xml);
    }

    public function test_indigency_preview_branch_reflects_the_template_wording(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/pages/Clerk/Certificates.tsx'));

        // The preview branch is gated on the exact certificate-type value.
        $this->assertStringContainsString(
            "data.certificate_type === 'Indigency'",
            $source
        );

        // Scope the wording assertions to the Indigency branch itself, so the
        // Scholarship branch's similar lines cannot satisfy them.
        $branch = (string) preg_replace(
            '/\s+/',
            ' ',
            $this->indigencyPreviewBranch($source),
        );

        $this->assertNotSame('', $branch, 'The Indigency preview branch could not be located.');

        // Key wording copied from the authoritative DOCX.
        $this->assertStringContainsString('C E R T I F I C A T I O N', $branch);
        $this->assertStringContainsString('(Indigent Certificate)', $branch);
        $this->assertStringContainsString(
            'That she/he belongs to the indigent family.',
            $branch
        );
        $this->assertStringContainsString(
            'Monthly Income is not sufficient for their other Needs.',
            $branch
        );
        $this->assertStringContainsString(
            'This certification is being issued upon the request of the above-named person for the purpose of',
            $branch
        );
        $this->assertStringContainsString(
            'Issued on this',
            $branch
        );
        $this->assertStringContainsString('HON. JENNIFER CALINGAO TRINIDAD', $branch);
        $this->assertStringContainsString('By the Authority of the Punong Barangay,', $branch);
        $this->assertStringContainsString('Officer on duty', $branch);
        $this->assertStringContainsString(
            'Note: This Certification is valid only for Three (3) Months from the day this was issued.',
            $branch
        );
        $this->assertStringContainsString(
            'Do not accept if a photocopy of this Certification is not a Certified true Copy, with erasures or Alterations.',
            $branch
        );

        // The branch must use the existing form state for the merge values.
        $this->assertStringContainsString('data.resident_name.toUpperCase()', $branch);
        $this->assertStringContainsString('data.purok', $branch);
        $this->assertStringContainsString('data.purpose.toUpperCase()', $branch);
        $this->assertStringContainsString('data.signatory_name', $branch);

        // No unresolved template tokens may reach the preview.
        $this->assertStringNotContainsString('${', $branch);
    }

    /**
     * Extract the Indigency-specific preview branch from the page source.
     */
    private function indigencyPreviewBranch(string $source): string
    {
        $start = strpos($source, "if (data.certificate_type === 'Indigency')");

        if ($start === false) {
            return '';
        }

        $end = strpos($source, "\n        return (", $start);

        if ($end === false) {
            return '';
        }

        return substr($source, $start, $end - $start);
    }

    private function requireIndigencyTemplate(): void
    {
        if (! CertificateTemplates::isAvailable('Indigency')) {
            $this->markTestSkipped('The Indigency template is not present in this environment.');
        }
    }

    /**
     * Extract word/document.xml from an on-disk DOCX archive.
     */
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

    private function runPropertiesForText(string $xml, string $text): string
    {
        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($xml), 'The DOCX document XML is invalid.');

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        foreach ($xpath->query('//w:t') ?: [] as $textNode) {
            if (! str_contains($textNode->textContent, $text)) {
                continue;
            }

            $run = $textNode->parentNode;
            $properties = $xpath->query('./w:rPr', $run)?->item(0);

            return $properties instanceof \DOMElement ? $document->saveXML($properties) : '';
        }

        $this->fail("Unable to locate a formatted run for [{$text}].");
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'certificate_type' => 'Indigency',
            'resident_name' => 'Juan Dela Cruz',
            'purok' => 'Purok 1',
            'purpose' => 'Financial Assistance',
            'control_no' => 'CTRL-2026-0002',
            'fee' => '50',
            'signatory_name' => 'HON. TEST',
            'signatory_title' => 'Punong Barangay',
        ], $overrides);
    }
}
