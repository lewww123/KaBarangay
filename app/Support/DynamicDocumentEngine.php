<?php

namespace App\Support;

use PhpOffice\PhpWord\TemplateProcessor;

final class DynamicDocumentEngine
{
    public static function processor(string $template, array $officials = []): TemplateProcessor
    {
        $processor = new TemplateProcessor($template);
        $processor->setValue('punong_barangay', mb_strtoupper($officials['punong_barangay']['name'] ?? '___________________'));
        $processor->setValue('punong_barangay_title', $officials['punong_barangay']['title'] ?? 'Punong Barangay');
        $processor->setValue('kagawad_names', implode(', ', array_column($officials['kagawads'] ?? [], 'name')));

        return $processor;
    }
}
