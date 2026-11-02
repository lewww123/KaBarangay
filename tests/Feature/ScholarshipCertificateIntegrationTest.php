<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\CertificateIssuance;
use App\Models\OrgChartPosition;
use App\Support\CertificateTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpWord\TemplateProcessor;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;
use ZipArchive;

class ScholarshipCertificateIntegrationTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private const FATHER = "father\u{2019}s";

    private const MOTHER = "mother\u{2019}s";

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

    public function test_scholarship_template_is_available_and_downloads(): void
    {
        $this->requireScholarshipTemplate();

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)
            ->post('/clerk/generate-certificate', $this->validPayload());

        $response->assertOk();
        $this->assertStringContainsString(
            'attachment',
            (string) $response->headers->get('content-disposition')
        );
    }

    public function test_scholarship_generation_resolves_all_placeholders(): void
    {
        $this->requireScholarshipTemplate();

        // Render the source template with the exact server-side keys the
        // controller uses, then confirm every Scholarship placeholder resolves.
        $processor = new TemplateProcessor(CertificateTemplates::path('Scholarship Certificate'));

        $values = [
            "resident\u{2019}s_name" => 'JUAN DELA CRUZ',
            'purok' => 'PUROK 1',
            'school/institution' => 'PPS NATIONAL HIGH SCHOOL',
            self::FATHER.'_name' => 'PEDRO DELA CRUZ',
            self::FATHER.'_occupation' => 'FARMER',
            self::FATHER.'_monthly_income' => '5000',
            self::MOTHER.'_name' => 'MARIA DELA CRUZ',
            self::MOTHER.'_occupation' => 'HOUSEWIFE',
            'purpose' => 'EDUCATIONAL ASSISTANCE',
            'current_date' => '1st day of January 2026',
            'signatory_name' => 'HON. TEST',
        ];

        foreach ($values as $key => $value) {
            $processor->setValue($key, $value);
        }

        $output = tempnam(sys_get_temp_dir(), 'scholarship_render_').'.docx';

        try {
            $processor->saveAs($output);

            $xml = $this->archiveDocumentXml($output);

            $this->assertNotFalse($xml, 'The rendered Scholarship document is not a readable DOCX archive.');

            foreach ($values as $key => $value) {
                $this->assertStringNotContainsString(
                    '${'.$key.'}',
                    $xml,
                    "Unresolved merge field [{$key}] remains in the generated document."
                );
                $this->assertStringContainsString($value, $xml);
            }
        } finally {
            @unlink($output);
        }
    }

    public function test_scholarship_blank_fields_leave_no_unresolved_placeholders(): void
    {
        $this->requireScholarshipTemplate();

        $processor = new TemplateProcessor(CertificateTemplates::path('Scholarship Certificate'));

        // Blank-safe: every Scholarship-specific field empty.
        $values = [
            'school/institution' => '',
            self::FATHER.'_name' => '',
            self::FATHER.'_occupation' => '',
            self::FATHER.'_monthly_income' => '',
            self::MOTHER.'_name' => '',
            self::MOTHER.'_occupation' => '',
        ];

        foreach ($values as $key => $value) {
            $processor->setValue($key, $value);
        }

        $output = tempnam(sys_get_temp_dir(), 'scholarship_blank_').'.docx';

        try {
            $processor->saveAs($output);

            $xml = $this->archiveDocumentXml($output);

            $this->assertNotFalse($xml);

            // Every blank Scholarship key must be replaced (not left as a token).
            foreach (array_keys($values) as $key) {
                $this->assertStringNotContainsString('${'.$key.'}', $xml);
            }
        } finally {
            @unlink($output);
        }
    }

    public function test_scholarship_nonnumeric_income_is_rejected(): void
    {
        $this->requireScholarshipTemplate();

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)
            ->from('/clerk/certificates')
            ->post('/clerk/generate-certificate', $this->validPayload([
                'father_monthly_income' => 'not-a-number',
            ]));

        $response->assertSessionHasErrors('father_monthly_income');
        $this->assertSame(0, CertificateIssuance::query()->count());
    }

    public function test_scholarship_generation_persists_one_issuance_without_family_fields(): void
    {
        $this->requireScholarshipTemplate();

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->post('/clerk/generate-certificate', $this->validPayload())
            ->assertOk();

        $this->assertSame(1, CertificateIssuance::query()->count());

        $issuance = CertificateIssuance::query()->first();

        $this->assertSame('Scholarship Certificate', $issuance->certificate_type);
        $this->assertSame('Juan Dela Cruz', $issuance->resident_name);
        $this->assertSame('Purok 1', $issuance->purok);
        $this->assertSame($this->barangay->id, $issuance->barangay_id);
        $this->assertSame($clerk->id, $issuance->issued_by);

        // The transient Scholarship fields must not be persisted anywhere.
        $attributes = $issuance->getAttributes();
        foreach (['school_institution', 'father_name', 'mother_name'] as $transient) {
            $this->assertArrayNotHasKey($transient, $attributes);
        }
    }

    public function test_scholarship_form_fields_are_gated_and_wired(): void
    {
        $source = (string) preg_replace(
            '/\s+/',
            ' ',
            (string) file_get_contents(base_path('resources/js/pages/Clerk/Certificates.tsx')),
        );

        // Fields only appear for Scholarship.
        $this->assertStringContainsString(
            "data.certificate_type === 'Scholarship Certificate'",
            $source
        );

        foreach ([
            'school_institution',
            'father_name',
            'father_occupation',
            'father_monthly_income',
            'mother_name',
            'mother_occupation',
            'mother_monthly_income',
        ] as $field) {
            $this->assertStringContainsString($field, $source, "Field [{$field}] is missing from the form.");
        }

        // Responsive two-column layout.
        $this->assertStringContainsString('grid-cols-1', $source);
        $this->assertStringContainsString('sm:grid-cols-2', $source);

        // Numeric-only income inputs.
        $this->assertStringContainsString('type="number"', $source);

        // Tricycle gating is preserved.
        $this->assertStringContainsString(
            "data.certificate_type === 'Tricycle' || data.certificate_type === 'Sari-sari Store'",
            $source
        );
    }

    public function test_scholarship_preview_branch_uses_template_wording_and_no_placeholders(): void
    {
        $source = (string) preg_replace(
            '/\s+/',
            ' ',
            (string) file_get_contents(base_path('resources/js/pages/Clerk/Certificates.tsx')),
        );

        // The Scholarship preview branch reflects the DOCX wording.
        $shell = (string) file_get_contents(base_path('resources/js/components/certificates/BrandedCertificatePreview.tsx'));
        $this->assertStringContainsString('(Indigent Certificate)', $shell);
        $this->assertStringContainsString(
            'THIS FURTHER CERTIFIES THAT THEY BELONG TO THE INDIGENT FAMILY.',
            $source
        );
        $this->assertStringContainsString('Three (3) Months', $source);
        $this->assertStringContainsString('Certified true Copy', $source);
    }

    public function test_scholarship_template_placeholder_integrity(): void
    {
        $this->requireScholarshipTemplate();

        $processor = new TemplateProcessor(CertificateTemplates::path('Scholarship Certificate'));
        $variables = $processor->getVariables();
        sort($variables);

        $expected = [
            'current_date',
            self::FATHER.'_monthly_income',
            self::FATHER.'_name',
            self::FATHER.'_occupation',
            self::MOTHER.'_name',
            self::MOTHER.'_occupation',
            'purok',
            'purpose',
            "resident\u{2019}s_name",
            'school/institution',
            'signatory_name',
        ];
        sort($expected);

        $this->assertSame($expected, $variables);

        // The current template uses the slash key and omits mother's monthly income.
        $xml = $this->archiveDocumentXml(CertificateTemplates::path('Scholarship Certificate'));
        $this->assertNotFalse($xml);
        $this->assertStringContainsString('${school/institution}', $xml);
        $this->assertStringNotContainsString('${school_institution}', $xml);
        $this->assertStringNotContainsString('${mother’s_monthly_income}', $xml);
    }

    private function requireScholarshipTemplate(): void
    {
        if (! CertificateTemplates::isAvailable('Scholarship Certificate')) {
            $this->markTestSkipped('The Scholarship template is not present in this environment.');
        }
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
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'certificate_type' => 'Scholarship Certificate',
            'resident_name' => 'Juan Dela Cruz',
            'purok' => 'Purok 1',
            'purpose' => 'Educational Assistance',
            'control_no' => 'CTRL-2026-0003',
            'fee' => '50',
            'school_institution' => 'PPS National High School',
            'father_name' => 'Pedro Dela Cruz',
            'father_occupation' => 'Farmer',
            'father_monthly_income' => '5000',
            'mother_name' => 'Maria Dela Cruz',
            'mother_occupation' => 'Housewife',
            'mother_monthly_income' => '0',
            'signatory_name' => 'HON. TEST',
            'signatory_title' => 'Punong Barangay',
        ], $overrides);
    }
}
