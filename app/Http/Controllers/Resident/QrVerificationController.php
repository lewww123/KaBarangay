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
                'resident.sectors',
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

                        'resident' => null,
                    ],
                ]
            );
        }

        $resident = $qrCredential->resident;

        /*
        |--------------------------------------------------------------------------
        | Resident Classification
        |--------------------------------------------------------------------------
        */

        $classification = $resident->sectors->isEmpty()
            ? 'Regular Resident'
            : $resident->sectors
                ->pluck('name')
                ->implode(' • ');

        /*
        |--------------------------------------------------------------------------
        | Valid QR
        |--------------------------------------------------------------------------
        */

        return Inertia::render(
            'Resident/VerifyResident',
            [
                'verification' => [
                    'valid' => true,

                    'message' =>
                        'Resident verified successfully.',

                    'resident' => [
                        'name' =>
                            $resident->full_name,

                        'residentId' =>
                            $resident->resident_number,

                        'barangay' =>
                            $resident->barangay?->name,

                        'city' =>
                            $resident->barangay?->city_municipality,

                        'province' =>
                            $resident->barangay?->province,

                        'classification' =>
                            $classification,

                        'status' =>
                            $resident->status,
                    ],
                ],
            ]
        );
    }
}