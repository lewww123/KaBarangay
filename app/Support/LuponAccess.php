<?php

namespace App\Support;

use App\Models\LuponCase;
use App\Models\Resident;
use Illuminate\Http\Request;

final class LuponAccess
{
    public static function secretary(Request $request, ?LuponCase $case = null): void
    {
        self::staff($request, $case);
        abort_unless(self::canVerify($request), 403, 'Payment verification requires an active Lupon Secretary assignment.');
    }

    /**
     * Fee verification follows the session-selected assignment only. Holding a
     * secondary Secretary assignment does not authorize a staff member whose
     * selected assignment is a different Lupon position.
     */
    public static function canVerify(Request $request): bool
    {
        $user = $request->user();
        if (! $user || ! $user->hasActiveAssignment(['lupon'])) {
            return false;
        }

        $assignmentId = $request->session()->get('staff_assignment_id');
        if (! $assignmentId) {
            return false;
        }

        return $user->staffAssignments()
            ->whereKey($assignmentId)
            ->where('barangay_id', $user->barangay_id)
            ->where('resident_id', $user->resident_id)
            ->where('department', 'lupon')
            ->where('is_active', true)
            ->whereRaw('LOWER(position) LIKE ?', ['%secretary%'])
            ->exists();
    }

    public static function staff(Request $request, ?LuponCase $case = null): void
    {
        $user = $request->user();
        abort_unless($user && $user->status === 'active' && $user->barangay_id !== null && $user->hasActiveAssignment(['lupon']), 403);
        if ($case) {
            abort_unless((int) $case->barangay_id === (int) $user->barangay_id, 403);
        }
    }

    public static function isComplainant(Resident $resident, LuponCase $case): bool
    {
        if ((int) $resident->barangay_id !== (int) $case->barangay_id) {
            return false;
        }
        $case->loadMissing(['complaint', 'parties']);

        return (int) $case->complaint?->resident_id === (int) $resident->id || $case->parties->contains(fn ($party) => $party->party_type === 'complainant' && (int) $party->resident_id === (int) $resident->id);
    }

    public static function resident(Resident $resident, LuponCase $case): bool
    {
        if ((int) $resident->barangay_id !== (int) $case->barangay_id) {
            return false;
        }

        return self::isComplainant($resident, $case) || $case->parties()->where('party_type', 'respondent')->where('resident_id', $resident->id)->where('association_status', 'verified')->exists();
    }
}
