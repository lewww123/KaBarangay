<?php

namespace App\Support;

use App\Models\Resident;
use App\Models\ResidentQrCredential;
use App\Models\ResidentRegistration;
use Illuminate\Support\Facades\Schema;

final class ResidentRegistrationQrMatcher
{
    /**
     * Resolve the existing QR credential format as a suggestion reference.
     * Invalid, inactive, revoked, or out-of-barangay credentials are ignored.
     */
    public function resolveCredential(?string $rawToken, int $barangayId): ?ResidentQrCredential
    {
        if (
            $rawToken === null ||
            ! preg_match('/^KBQR-[A-Za-z0-9]{64}$/D', $rawToken) ||
            ! Schema::hasColumn('resident_registrations', 'qr_credential_id')
        ) {
            return null;
        }

        $credential = ResidentQrCredential::query()
            ->where('token', $rawToken)
            ->where('is_active', true)
            ->whereNull('revoked_at')
            ->whereHas('resident', fn ($query) => $query
                ->where('barangay_id', $barangayId)
                ->where('status', 'active'))
            ->first(['id', 'resident_id']);

        // Keep matching byte-exact even on databases with case-insensitive
        // collations. The raw value is never returned, stored, or logged.
        if ($credential === null || ! hash_equals((string) $credential->token, $rawToken)) {
            return null;
        }

        return $credential;
    }

    /**
     * Revalidate a stored credential reference before showing it to an admin.
     */
    public function suggestedResident(ResidentRegistration $registration): ?Resident
    {
        if (
            ! Schema::hasColumn('resident_registrations', 'qr_credential_id') ||
            $registration->qr_credential_id === null
        ) {
            return null;
        }

        $credential = ResidentQrCredential::query()
            ->whereKey($registration->qr_credential_id)
            ->where('is_active', true)
            ->whereNull('revoked_at')
            ->whereHas('resident', fn ($query) => $query
                ->where('barangay_id', $registration->barangay_id)
                ->where('status', 'active'))
            ->with([
                'resident.purok:id,name',
                'resident.account:id,resident_id,is_active',
            ])
            ->first();

        if ($credential === null || $credential->resident === null) {
            return null;
        }

        return $credential->resident;
    }
}
