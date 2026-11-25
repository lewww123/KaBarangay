<?php

namespace App\Support;

use DateTimeInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class FinancialDisclosureExport
{
    public static function csv(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                fputcsv($handle, array_map(self::cell(...), $row));
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private static function cell(mixed $value): string|int|float|null
    {
        if ($value === null || is_int($value) || is_float($value)) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        $text = (string) $value;

        return preg_match('/^[=+\-@]/', ltrim($text)) === 1 ? "'".$text : $text;
    }
}
