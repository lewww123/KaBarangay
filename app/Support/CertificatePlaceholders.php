<?php

namespace App\Support;

use DOMDocument;
use DOMXPath;
use PhpOffice\PhpWord\TemplateProcessor;
use RuntimeException;
use ZipArchive;

final class CertificatePlaceholders
{
    private const TAG_PATTERN = '/(?<!\$)\{(?:RESIDENT_PUROK|PUROK)\}|PUROK MASAYAHIN/';

    public static function replaceText(string $text, string $purok): string
    {
        return strtr($text, ['{RESIDENT_PUROK}' => $purok, '{PUROK}' => $purok]);
    }

    /** Normalize supported purok aliases to the existing template contract. */
    public static function templateVariables(string $path): array
    {
        $variables = array_map(
            fn (string $variable) => in_array($variable, ['PUROK', 'RESIDENT_PUROK'], true) ? 'purok' : $variable,
            (new TemplateProcessor($path))->getVariables(),
        );
        $zip = self::open($path);
        try {
            foreach (self::xmlParts($zip) as $xml) {
                $document = self::document($xml);
                foreach ($document->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'p') as $paragraph) {
                    if (preg_match(self::TAG_PATTERN, $paragraph->textContent) === 1) {
                        $variables[] = 'purok';
                    }
                }
            }
        } finally {
            $zip->close();
        }

        return array_values(array_unique($variables));
    }

    /** Fill single-braced tags even when Word splits them across styled runs. */
    public static function fillDocx(string $path, string $purok): void
    {
        $zip = self::open($path);
        try {
            foreach (self::xmlParts($zip) as $name => $xml) {
                $document = self::document($xml);
                $xpath = new DOMXPath($document);
                $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
                $changed = false;
                foreach ($xpath->query('//w:p') ?: [] as $paragraph) {
                    $runs = [];
                    $text = '';
                    foreach ($xpath->query('.//w:t', $paragraph) ?: [] as $node) {
                        $runs[] = ['node' => $node, 'start' => strlen($text), 'end' => strlen($text) + strlen($node->textContent)];
                        $text .= $node->textContent;
                    }
                    preg_match_all(self::TAG_PATTERN, $text, $matches, PREG_OFFSET_CAPTURE);
                    foreach (array_reverse($matches[0]) as [$tag, $offset]) {
                        $end = $offset + strlen($tag);
                        $first = true;
                        foreach ($runs as $run) {
                            if ($run['end'] <= $offset || $run['start'] >= $end) {
                                continue;
                            }
                            $value = $run['node']->textContent;
                            $prefix = $run['start'] <= $offset ? substr($value, 0, $offset - $run['start']) : '';
                            $suffix = $run['end'] >= $end ? substr($value, $end - $run['start']) : '';
                            while ($run['node']->firstChild !== null) {
                                $run['node']->removeChild($run['node']->firstChild);
                            }
                            $run['node']->appendChild($document->createTextNode($prefix.($first ? $purok : '').$suffix));
                            $first = false;
                        }
                        $changed = true;
                    }
                }
                if ($changed && ! $zip->addFromString($name, $document->saveXML())) {
                    throw new RuntimeException('Unable to fill certificate purok placeholders.');
                }
            }
        } finally {
            if (! $zip->close()) {
                throw new RuntimeException('Unable to save the generated certificate placeholders.');
            }
        }
    }

    private static function open(string $path): ZipArchive
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Unable to open certificate template.');
        }

        return $zip;
    }

    private static function xmlParts(ZipArchive $zip): array
    {
        $parts = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (is_string($name) && preg_match('#^word/(document|header\d*|footer\d*)\.xml$#', $name)) {
                $xml = $zip->getFromName($name);
                if (is_string($xml)) {
                    $parts[$name] = $xml;
                }
            }
        }

        return $parts;
    }

    private static function document(string $xml): DOMDocument
    {
        $document = new DOMDocument;
        $document->preserveWhiteSpace = true;
        if (! $document->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException('Invalid certificate template XML.');
        }

        return $document;
    }
}
