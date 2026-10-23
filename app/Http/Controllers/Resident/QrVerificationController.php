<?php

namespace App\Http\Controllers\Resident;

use App\Http\Controllers\Controller;
use App\Models\ResidentQrCredential;
use Inertia\Inertia;

class QrVerificationController extends Controller
{
    /**
     * Verify a resident QR code using its opaque token.
     */
    public function show(string $token)
    {
        $qrCredential = ResidentQrCredential::query()
            ->where('token', $token)
            ->where('is_active', true)
            ->whereNull('revoked_at')
            ->with([
                'resident.barangay',
            ])
            ->first();

        /*
        |--------------------------------------------------------------------------
        | Invalid QR
        |--------------------------------------------------------------------------
        |
        | The QR is considered invalid if:
        | - token does not exist
        | - QR credential is inactive
        | - QR credential was revoked
        | - resident no longer exists
        | - resident is not active
        |
        */

        if (
            ! $qrCredential ||
            ! $qrCredential->resident ||
            $qrCredential->resident->status !== 'active'
        ) {
            return Inertia::render(
                'Resident/VerifyResident',
                [
                    'verification' => [
                        'valid' => false,

                        'message' =>
                            'This QR code is invalid or no longer active.',

                        'barangay' => null,
                    ],
                ]
            );
        }

        $resident = $qrCredential->resident;

        /*
        |--------------------------------------------------------------------------
        | Valid QR
        |--------------------------------------------------------------------------
        |
        | This page is public and is opened by anyone who scans a resident's QR
        | code, so it deliberately exposes only the minimum needed to confirm
        | that the credential belongs to an active resident: the validity flag,
        | a human-readable message, and the issuing barangay. Resident-identifying
        | fields (name, resident number), sector classification, home location
        | and record status are intentionally not returned here.
        |
        */

        return Inertia::render(
            'Resident/VerifyResident',
            [
                'verification' => [
                    'valid' => true,

                    'message' =>
                        'Resident verified successfully.',

                    'barangay' =>
                        $resident->barangay?->name,
                ],
            ]
        );
    }
}