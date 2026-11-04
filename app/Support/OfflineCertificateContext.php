<?php

namespace App\Support;

use App\Models\Certificate;
use App\Models\Resident;

final class OfflineCertificateContext
{
    public static function owner(Resident $resident): string
    {
        return hash_hmac('sha256', 'offline-owner:'.$resident->barangay_id.':'.$resident->id, (string) config('app.key'));
    }

    public static function encryptionKey(Resident $resident): string
    {
        return base64_encode(hash_hmac('sha256', 'offline-certificate-key:'.$resident->barangay_id.':'.$resident->id, (string) config('app.key'), true));
    }

    public static function certificate(Certificate $certificate): array
    {
        $type = CertificateRequestDetails::typeForCertificate($certificate);

        return [
            'id' => (string) $certificate->id,
            'name' => $certificate->name,
            'fee' => (string) $certificate->fee,
            'description' => $certificate->description ?? '',
            'requirements' => $certificate->requirements ?? [],
            'fixedPurpose' => CertificateRequestDetails::fixedPurpose($type),
            'requiresBusinessDetails' => CertificateRequestDetails::requiresBusinessDetails($type, (bool) $certificate->requires_business_details),
            'detailsFields' => CertificateRequestDetails::fieldsForCertificate($certificate),
        ];
    }

    public static function revision(Certificate $certificate): string
    {
        return hash_hmac('sha256', json_encode(self::certificate($certificate), JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
