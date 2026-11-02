<?php

namespace App\Support;

use App\Models\LuponCase;
use App\Models\LuponEvidence;
use App\Models\LuponHearing;
use App\Models\Resident;
use Illuminate\Validation\ValidationException;

final class LuponDocket
{
    public static function party(array $data, string $prefix, int $barangay): array
    {
        $id = $data[$prefix.'_resident_id'] ?? null;
        if (! $id) {
            return $data;
        }
        $resident = Resident::whereKey($id)->where('barangay_id', $barangay)->where('status', 'active')->with('purok')->first();
        if (! $resident) {
            throw ValidationException::withMessages([$prefix.'_resident_id' => 'Choose an active resident from your barangay.']);
        }
        $data[$prefix.'_name'] = $resident->full_name;
        $data[$prefix.'_address'] = trim(implode(', ', array_filter([$resident->address_line, $resident->purok?->name])));
        $data[$prefix.'_contact'] = $resident->mobile;
        $data[$prefix.'_is_resident'] = true;

        return $data;
    }

    public static function finish(LuponCase $case, array $data, int $actor): void
    {
        LuponCaseWorkflow::intake($case, $actor);
        if ($case->complaint_id) {
            LuponEvidence::where('complaint_id', $case->complaint_id)->where('barangay_id', $case->barangay_id)->update(['case_id' => $case->id]);
        }
        $hearing = null;
        if (! empty($data['hearing_date'])) {
            $hearing = LuponHearing::create(['case_id' => $case->id, 'barangay_id' => $case->barangay_id, 'hearing_number' => 1, 'phase' => 'Kapitan', 'hearing_date' => $data['hearing_date'], 'hearing_time' => $data['hearing_time'], 'venue' => $data['venue'] ?? 'Barangay Hall', 'status' => 'Scheduled', 'officer_id' => $actor]);
        }
        LuponDocuments::queueNotices($case, $hearing, $actor);
    }
}
