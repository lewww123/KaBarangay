<?php

namespace App\Support;

use App\Models\CertificateIssuance;

class CertificateControlNumbers
{
    /** Read-only suggestion. Call again under the barangay lock when issuing. */
    public static function next(int $barangayId, string $type): string
    {
        $prefix = now('Asia/Manila')->format('Y-m').'-';
        $highest = CertificateIssuance::query()->where('barangay_id', $barangayId)
            ->where('certificate_type', $type)->where('control_no', 'like', $prefix.'%')
            ->pluck('control_no')->map(function ($number) use ($prefix) {
                return preg_match('/^'.preg_quote($prefix, '/').'(\d{4,})$/', $number, $match) ? (int) $match[1] : 0;
            })->max() ?? 0;

        return $prefix.str_pad((string) ($highest + 1), 4, '0', STR_PAD_LEFT);
    }
}
