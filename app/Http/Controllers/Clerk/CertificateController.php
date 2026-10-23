<?php

namespace App\Http\Controllers\Clerk;

use App\Http\Controllers\Controller;
use App\Models\Resident;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only resident lookups for the Clerk certificate form.
 *
 * The Clerk certificate page needs a small, explicit set of resident values
 * (date of birth, sex, purok) to fill the Delayed PSA Registration form when
 * a resident is linked. This endpoint exposes only those values, only for
 * active residents of the acting user's own barangay.
 */
class CertificateController extends Controller
{
    /**
     * Return the certificate-relevant details of a linked resident.
     *
     * The resident is resolved from the authenticated user's barangay, so a
     * foreign or unknown resident is reported as not found and never leaks
     * any resident data.
     */
    public function residentDetails(Request $request, int $resident): JsonResponse
    {
        $barangayId = $request->user()->barangay_id;

        $record = Resident::query()
            ->with('purok:id,name')
            ->where('barangay_id', $barangayId)
            ->whereKey($resident)
            ->where('status', 'active')
            ->first();

        if (! $record) {
            return response()->json([
                'message' => 'Resident not found or inactive.',
            ], 404);
        }

        return response()->json([
            'resident' => [
                'id' => $record->id,
                'full_name' => $record->full_name,
                'birthdate' => $record->birthdate?->format('Y-m-d'),
                'sex' => $record->sex,
                'purok' => $record->purok?->name,
            ],
        ]);
    }
}
