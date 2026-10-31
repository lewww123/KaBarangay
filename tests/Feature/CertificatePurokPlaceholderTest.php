<?php

namespace Tests\Feature;

use App\Support\CertificatePlaceholders;
use App\Support\CertificateTemplates;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\TemplateProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

class CertificatePurokPlaceholderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/certificate-placeholders-'.Str::uuid();
        File::ensureDirectoryExists($this->directory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public static function aliases(): array
    {
        return [['{PUROK}'], ['{RESIDENT_PUROK}'], ['${PUROK}'], ['${RESIDENT_PUROK}']];
    }

    #[DataProvider('aliases')]
    public function test_purok_aliases_are_valid_template_fields_and_render_across_word_runs(string $alias): void
    {
        $word = new PhpWord;
        $section = $word->addSection();
        foreach (CertificateTemplates::genericTemplateKeys('Tricycle') as $key) {
            if ($key === 'purok') {
                $run = $section->addTextRun();
                $run->addText(substr($alias, 0, 5), ['bold' => true]);
                $run->addText(substr($alias, 5), ['italic' => true]);
            } else {
                $section->addText('${'.$key.'}');
            }
        }
        $input = $this->directory.'/template.docx';
        $output = $this->directory.'/rendered.docx';
        IOFactory::createWriter($word, 'Word2007')->save($input);
        CertificateTemplates::assertTemplateContract('Tricycle', $input);
        Settings::setOutputEscapingEnabled(true);
        $processor = new TemplateProcessor($input);
        $processor->setValues(['PUROK' => 'PUROK A & B', 'RESIDENT_PUROK' => 'PUROK A & B']);
        $processor->saveAs($output);
        CertificatePlaceholders::fillDocx($output, 'PUROK A & B');

        $archive = new ZipArchive;
        $archive->open($output);
        try {
            $document = new \DOMDocument;
            $this->assertTrue($document->loadXML($archive->getFromName('word/document.xml'), LIBXML_NONET));
            $this->assertStringContainsString('PUROK A & B', $document->textContent);
            $this->assertStringNotContainsString($alias, $document->textContent);
        } finally {
            $archive->close();
        }
    }

    public function test_literal_purok_tags_are_filled_in_headers_and_footers_without_losing_artwork(): void
    {
        $word = new PhpWord;
        $section = $word->addSection();
        $header = $section->addHeader();
        $header->addText('Office of {PUROK}');
        $section->addFooter()->addText('Resident of {RESIDENT_PUROK}');
        $image = $this->directory.'/seal.png';
        File::put($image, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j6XcAAAAASUVORK5CYII='));
        $header->addImage($image, ['width' => 30, 'height' => 30]);
        $path = $this->directory.'/artwork.docx';
        IOFactory::createWriter($word, 'Word2007')->save($path);
        $archive = new ZipArchive;
        $archive->open($path);
        $media = [];
        for ($index = 0; $index < $archive->numFiles; $index++) {
            $name = $archive->getNameIndex($index);
            if (str_starts_with($name, 'word/media/')) {
                $media[$name] = $archive->getFromName($name);
            }
        }
        $archive->close();
        $this->assertNotEmpty($media);
        CertificatePlaceholders::fillDocx($path, 'PUROK BAGONG PAG-ASA');
        $archive->open($path);
        try {
            foreach (['word/header1.xml', 'word/footer1.xml'] as $name) {
                $this->assertStringContainsString('PUROK BAGONG PAG-ASA', $archive->getFromName($name));
            }
            foreach ($media as $name => $bytes) {
                $this->assertSame($bytes, $archive->getFromName($name));
            }
        } finally {
            $archive->close();
        }
    }
}
