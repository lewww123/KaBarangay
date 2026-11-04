<?php

namespace Tests\Feature;

use App\Models\CertificateIssuance;
use App\Models\OrgChartPosition;
use App\Support\CertificateOfficialDocument;
use App\Support\CertificateOfficials;
use App\Support\CertificateTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class CertificateOfficialsTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private function officials(): array
    {
        $barangay = $this->createBarangay('One', 'ONE');
        $clerk = $this->createStaff($barangay, 'clerk');
        $make = fn ($title, $name, $status = 'published') => OrgChartPosition::create([
            'barangay_id' => $barangay->id, 'title' => $title, 'assigned_person' => $name,
            'status' => $status, 'created_by' => $clerk->id,
        ]);

        return [$barangay, $clerk, $make('Punong Barangay', 'Hon. Default Captain'), $make('Kagawad on Health', 'Hon. Alice & Reyes'), $make('Barangay Kagawad', 'Hon. Bob Santos'), $make];
    }

    public function test_page_uses_default_captain_and_published_kagawads(): void
    {
        [$barangay, $clerk, $captain, $alice, $bob, $make] = $this->officials();
        $make('Kagawad', 'Draft Member', 'draft');
        $make('Secretary', 'Secretary Person');
        $make('Kagawad on Peace', 'hon. bob santos');
        $this->withoutVite();
        $this->actingAs($clerk)->get('/clerk/certificates')->assertOk()->assertInertia(fn ($page) => $page
            ->where('punong_barangay.id', $captain->id)->where('punong_barangay.title', 'Punong Barangay')
            ->has('kagawads', 2)->has('active_kagawads', 2)->where('kagawads.0.id', $alice->id));
    }

    public function test_acting_captain_is_excluded_by_id_and_name_and_default_can_be_restored(): void
    {
        [$barangay, $clerk, $captain, $alice, $bob, $make] = $this->officials();
        $make('Kagawad on Peace', 'HON. ALICE & REYES');
        $this->withoutVite();
        $this->actingAs($clerk)->get('/clerk/certificates?acting_captain_id='.$alice->id)->assertOk()->assertInertia(fn ($page) => $page
            ->where('punong_barangay.id', $alice->id)->where('punong_barangay.title', 'Barangay Kagawad, OIC')
            ->where('default_punong_barangay.id', $captain->id)->has('kagawads', 1)->where('kagawads.0.id', $bob->id)
            ->has('active_kagawads', 2));
        $resolved = CertificateOfficials::resolve($barangay->id);
        $this->assertSame($captain->id, $resolved['punong_barangay']['id']);
        $this->assertCount(2, $resolved['kagawads']);
    }

    public function test_invalid_foreign_unpublished_and_non_kagawad_selections_are_rejected(): void
    {
        [$barangay, $clerk, $captain, $alice, $bob, $make] = $this->officials();
        $other = $this->createBarangay('Other', 'OTHER');
        $foreign = OrgChartPosition::create(['barangay_id' => $other->id, 'title' => 'Kagawad', 'assigned_person' => 'Foreign Member', 'status' => 'published', 'created_by' => $clerk->id]);
        $draft = $make('Kagawad', 'Draft Member', 'draft');
        foreach ([$captain->id, $foreign->id, $draft->id, 999999, -1, 'bad'] as $id) {
            foreach (['/clerk/certificates/preview', '/clerk/generate-certificate'] as $url) {
                $this->actingAs($clerk)->postJson($url, [...$this->payload(), 'acting_captain_id' => $id])
                    ->assertUnprocessable()->assertJsonValidationErrors('acting_captain_id');
            }
        }
        $this->assertSame(0, CertificateIssuance::count());
    }

    public function test_preview_and_issue_contain_the_same_acting_captain_and_filtered_board(): void
    {
        [$barangay, $clerk, $captain, $alice, $bob] = $this->officials();
        foreach (['/clerk/certificates/preview', '/clerk/generate-certificate'] as $index => $url) {
            $response = $this->actingAs($clerk)->post($url, [...$this->payload(), 'acting_captain_id' => $alice->id])->assertOk();
            $xpath = $this->document($response);
            $this->assertSame(1, $xpath->query('//w:t[text()="HON. ALICE & REYES"]')->length);
            $this->assertSame(1, $xpath->query('//w:t[text()="Barangay Kagawad, OIC"]')->length);
            $this->assertSame(0, $xpath->query('//w:tbl[w:tblPr/w:tblCaption[@w:val="CertificateKagawads"]]')->length);
            $this->assertSame(0, $xpath->query('//w:t[contains(text(), "By the Authority")]')->length);
            $this->assertSame(0, $xpath->query('//w:p[normalize-space(.)="Officer on duty"]')->length);
            $this->assertSame($index, CertificateIssuance::count());
        }
    }

    public function test_default_captain_replaces_template_literal_without_acting_parameter(): void
    {
        [$barangay, $clerk] = $this->officials();
        foreach ([[], ['acting_captain_id' => ''], ['acting_captain_id' => null]] as $index => $selection) {
            foreach (['/clerk/certificates/preview', '/clerk/generate-certificate'] as $url) {
                $response = $this->actingAs($clerk)->post($url, [...$this->payload(), ...$selection, 'control_no' => 'CTRL-2026-100'.$index])->assertOk();
                $xpath = $this->document($response);
                $this->assertSame(1, $xpath->query('//w:t[text()="HON. DEFAULT CAPTAIN"]')->length);
                $this->assertSame(0, $xpath->query('//w:t[text()="Barangay Kagawad, OIC"]')->length);
                $this->assertSame(0, $xpath->query('//w:tbl[w:tblPr/w:tblCaption[@w:val="CertificateKagawads"]]')->length);
                $this->assertSame(1, $xpath->query('//w:p[normalize-space(.)="Officer on duty"]')->length);
            }
        }
        $this->assertSame(3, CertificateIssuance::count());
    }

    public function test_no_published_captain_does_not_fall_back_to_a_stale_template_name(): void
    {
        [$barangay, $clerk, $captain] = $this->officials();
        $captain->update(['status' => 'draft']);
        $this->assertNull(CertificateOfficials::resolve($barangay->id)['punong_barangay']);
        $response = $this->actingAs($clerk)->post('/clerk/certificates/preview', $this->payload())->assertOk();
        $xpath = $this->document($response);
        $this->assertSame(0, $xpath->query('//w:t[contains(text(), "JENNIFER")]')->length);
    }

    public function test_oic_removes_the_separate_authority_signature(): void
    {
        [$barangay, $clerk, $captain, $alice] = $this->officials();
        $response = $this->actingAs($clerk)->post('/clerk/certificates/preview', [
            ...$this->payload(), 'acting_captain_id' => $alice->id,
            'signatory_name' => $captain->assigned_person, 'signatory_title' => $captain->title,
        ])->assertOk();
        $xpath = $this->document($response);
        $this->assertSame(1, $xpath->query('//w:t[text()="HON. ALICE & REYES"]')->length);
        $this->assertSame(0, $xpath->query('//w:t[text()="HON. DEFAULT CAPTAIN"]')->length);
        $this->assertSame(1, $xpath->query('//w:t[text()="Barangay Kagawad, OIC"]')->length);
    }

    public function test_authority_position_with_ampersand_remains_valid_xml(): void
    {
        [$barangay, $clerk, $captain, $alice, $bob, $make] = $this->officials();
        $duty = $make('Secretary & Treasurer', 'Hon. Duty Person');
        $response = $this->actingAs($clerk)->post('/clerk/certificates/preview', [
            ...$this->payload(), 'acting_captain_id' => $alice->id,
            'signatory_name' => $duty->assigned_person, 'signatory_title' => $duty->title,
        ])->assertOk();
        $xpath = $this->document($response);
        $this->assertSame(0, $xpath->query('//w:t[text()="Secretary & Treasurer"]')->length);
        $this->assertSame(0, $xpath->query('//w:t[text()="HON. DUTY PERSON"]')->length);
    }

    public function test_signature_substitution_preserves_template_formatting_in_both_modes(): void
    {
        [$barangay, $clerk, $captain, $alice] = $this->officials();
        foreach ([null, $alice->id] as $selection) {
            $path = tempnam(sys_get_temp_dir(), 'official-format-');
            try {
                $properties = '<w:pPr><w:jc w:val="right"/><w:ind w:left="1200"/></w:pPr>';
                $run = '<w:rPr><w:b/><w:sz w:val="24"/></w:rPr>';
                $xml = '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>';
                foreach (['Old Captain', 'Punong Barangay'] as $text) {
                    $xml .= '<w:p>'.$properties.'<w:r>'.$run.'<w:t>'.$text.'</w:t></w:r></w:p>';
                }
                $zip = new \ZipArchive;
                $this->assertTrue($zip->open($path, \ZipArchive::OVERWRITE));
                $zip->addFromString('word/document.xml', $xml.'</w:body></w:document>');
                $zip->close();
                CertificateOfficialDocument::apply($path, CertificateOfficials::resolve($barangay->id, $selection), false);
                $zip->open($path);
                $result = $zip->getFromName('word/document.xml');
                $zip->close();
                $this->assertSame(2, substr_count($result, $properties));
                $this->assertSame(2, substr_count($result, $run));
                $this->assertStringContainsString($selection === null ? 'HON. DEFAULT CAPTAIN' : 'HON. ALICE &amp; REYES', $result);
            } finally {
                unlink($path);
            }
        }
    }

    private function payload(): array
    {
        if (! CertificateTemplates::isAvailable('Indigency')) {
            $this->markTestSkipped('Indigency template is not available.');
        }

        return ['certificate_type' => 'Indigency', 'resident_name' => 'Juan Cruz', 'purok' => 'Purok 1', 'purpose' => 'Employment', 'control_no' => 'CTRL-2026-0921', 'fee' => '0'];
    }

    private function document($response): \DOMXPath
    {
        $path = $response->getFile()->getPathname();
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path));
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        $doc = new \DOMDocument;
        $this->assertTrue($doc->loadXML($xml, LIBXML_NONET));
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        // Windows can briefly hold a generated download open after it is read.
        for ($attempt = 0; $attempt < 10; $attempt++) {
            if (@unlink($path)) {
                break;
            }
            usleep(100000);
        }

        return $xpath;
    }
}
