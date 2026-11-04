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
            'sicsican', 'barangay sicsican' => ['Business Clearance', 'Barangay Certification'],
            default => CertificateTemplates::types(),
        };
    }

    public static function allows(?int $barangayId, string $type): bool
    {
        $barangay = Barangay::find($barangayId);
        if (! $barangay) {
            return false;
        }

        $name = strtolower(trim($barangay->name));
        if (in_array($name, ['sicsican', 'barangay sicsican'], true)) {
            return in_array($type, ['Business Clearance', 'Barangay Certification'], true);
        }

        // Preserve existing validation and reviewed-PDF types for other tenants.
        return ! in_array($type, ['Business Clearance', 'Barangay Certification'], true);
    }
}
