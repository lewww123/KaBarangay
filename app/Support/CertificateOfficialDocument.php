<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;
use ZipArchive;

class CertificateOfficialDocument
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public static function apply(string $path, array $officials, bool $includeBoard = false): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException('Unable to open certificate document.');
        }
        try {
            $xml = $zip->getFromName('word/document.xml');
            $doc = new DOMDocument;
            $doc->preserveWhiteSpace = true;
            if (! is_string($xml) || ! $doc->loadXML($xml, LIBXML_NONET)) {
                throw new InvalidArgumentException('Invalid certificate document XML.');
            }
            $xpath = new DOMXPath($doc);
            $xpath->registerNamespace('w', self::W);
            foreach ($xpath->query('//w:p') as $paragraph) {
                $text = self::text($xpath, $paragraph);
                if (mb_strtolower($text) !== 'punong barangay') {
                    continue;
                }
                $name = $paragraph->previousSibling;
                while ($name && (! $name instanceof DOMElement || $name->localName !== 'p' || self::text($xpath, $name) === '')) {
                    $name = $name->previousSibling;
                }
                if ($name instanceof DOMElement) {
                    self::replaceText($xpath, $name, mb_strtoupper($officials['punong_barangay']['name'] ?? '___________________'));
                }
                self::replaceText($xpath, $paragraph, $officials['punong_barangay']['title'] ?? 'Punong Barangay');
                break; // The separate authority/officer signature must retain its own selection.
            }
            if (($officials['punong_barangay']['title'] ?? '') === 'Barangay Kagawad, OIC') {
                $removing = false;
                foreach ($xpath->query('//w:p') as $paragraph) {
                    $text = mb_strtolower(self::text($xpath, $paragraph));
                    if (str_contains($text, 'by the authority of the punong barangay')) {
                        $removing = true;
                    }
                    if ($removing) {
                        self::replaceText($xpath, $paragraph, '');
                        if ($text === 'officer on duty') {
                            break;
                        }
                    }
                }
            }
            $body = $xpath->query('/w:document/w:body')->item(0);
            if ($includeBoard && $body && count($officials['kagawads'])) {
                $table = self::boardTable($doc, $officials['kagawads']);
                $section = $xpath->query('./w:sectPr', $body)->item(0);
                $body->insertBefore($table, $section);
            }
            if (! $zip->addFromString('word/document.xml', $doc->saveXML())) {
                throw new InvalidArgumentException('Unable to update certificate officials.');
            }
        } finally {
            $zip->close();
        }
    }

    private static function text(DOMXPath $xpath, DOMElement $paragraph): string
    {
        $text = '';
        foreach ($xpath->query('.//w:t', $paragraph) as $node) {
            $text .= $node->textContent;
        }

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    private static function replaceText(DOMXPath $xpath, DOMElement $paragraph, string $value): void
    {
        $nodes = $xpath->query('.//w:t', $paragraph);
        foreach ($nodes as $index => $node) {
            while ($node->firstChild) {
                $node->removeChild($node->firstChild);
            }
            $node->appendChild($paragraph->ownerDocument->createTextNode($index === 0 ? $value : ''));
        }
        // Preserve the template paragraph and run formatting for both modes.

    }

    private static function boardTable(DOMDocument $doc, array $kagawads): DOMElement
    {
        $table = $doc->createElementNS(self::W, 'w:tbl');
        $properties = $table->appendChild($doc->createElementNS(self::W, 'w:tblPr'));
        $position = $properties->appendChild($doc->createElementNS(self::W, 'w:tblpPr'));
        foreach (['vertAnchor' => 'page', 'horzAnchor' => 'page', 'tblpX' => '900', 'tblpY' => '13833', 'leftFromText' => '0', 'rightFromText' => '0', 'topFromText' => '0', 'bottomFromText' => '0'] as $key => $value) {
            $position->setAttributeNS(self::W, 'w:'.$key, $value);
        }
        $width = $properties->appendChild($doc->createElementNS(self::W, 'w:tblW'));
        $width->setAttributeNS(self::W, 'w:w', '10080');
        $width->setAttributeNS(self::W, 'w:type', 'dxa');
        $caption = $properties->appendChild($doc->createElementNS(self::W, 'w:tblCaption'));
        $caption->setAttributeNS(self::W, 'w:val', 'CertificateKagawads');
        $grid = $table->appendChild($doc->createElementNS(self::W, 'w:tblGrid'));
        for ($column = 0; $column < 3; $column++) {
            $grid->appendChild($doc->createElementNS(self::W, 'w:gridCol'))->setAttributeNS(self::W, 'w:w', '3360');
        }
        $rows = [[['name' => 'BARANGAY KAGAWADS', 'title' => '']], ...array_chunk($kagawads, 3)];
        foreach ($rows as $index => $members) {
            $row = $table->appendChild($doc->createElementNS(self::W, 'w:tr'));
            foreach ($members as $member) {
                $cell = $row->appendChild($doc->createElementNS(self::W, 'w:tc'));
                if ($index === 0) {
                    $cell->appendChild($doc->createElementNS(self::W, 'w:tcPr'))->appendChild($doc->createElementNS(self::W, 'w:gridSpan'))->setAttributeNS(self::W, 'w:val', '3');
                }
                foreach ([$member['name'], $member['title']] as $line => $text) {
                    if ($text === '') {
                        continue;
                    }
                    $paragraph = $cell->appendChild($doc->createElementNS(self::W, 'w:p'));
                    $ppr = $paragraph->appendChild($doc->createElementNS(self::W, 'w:pPr'));
                    $ppr->appendChild($doc->createElementNS(self::W, 'w:jc'))->setAttributeNS(self::W, 'w:val', 'center');
                    $spacing = $ppr->appendChild($doc->createElementNS(self::W, 'w:spacing'));
                    foreach (['before' => '0', 'after' => '0', 'line' => '200', 'lineRule' => 'auto'] as $key => $value) {
                        $spacing->setAttributeNS(self::W, 'w:'.$key, $value);
                    }
                    $run = $paragraph->appendChild($doc->createElementNS(self::W, 'w:r'));
                    $rpr = $run->appendChild($doc->createElementNS(self::W, 'w:rPr'));
                    $rpr->appendChild($doc->createElementNS(self::W, 'w:rFonts'))->setAttributeNS(self::W, 'w:ascii', 'Times New Roman');
                    $rpr->appendChild($doc->createElementNS(self::W, 'w:sz'))->setAttributeNS(self::W, 'w:val', '16');
                    if ($line === 0) {
                        $rpr->appendChild($doc->createElementNS(self::W, 'w:b'));
                    }
                    $run->appendChild($doc->createElementNS(self::W, 'w:t'))->appendChild($doc->createTextNode($line === 0 ? mb_strtoupper($text) : $text));
                }
            }
        }

        return $table;
    }
}
