<?php

namespace App\Support;

use App\Models\Barangay;

final class CertificateTenantAccess
{
    public static function types(?int $barangayId): array
    {
        $name = strtolower(trim((string) Barangay::find($barangayId)?->name));

        return match ($name) {
            'san jose', 'barangay san jose' => CertificateTemplates::types(),
            'sicsican', 'barangay sicsican' => ['Business Clearance'],
            default => [],
        };
    }

    public static function allows(?int $barangayId, string $type): bool
    {
        return in_array($type, self::types($barangayId), true);
    }
}
