<?php

namespace App\Support;

use App\Models\LuponCase;
use App\Models\LuponComplaint;
use App\Models\LuponEvidence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class LuponEvidenceStore
{
    public static function store(array $files, ?LuponComplaint $complaint = null, ?LuponCase $case = null, ?int $resident = null, ?int $staff = null): array
    {
        $paths = [];
        try {
            foreach ($files as $file) {
                $barangay = $case?->barangay_id ?? $complaint->barangay_id;
                $path = $file->store('lupon/evidence/'.$barangay, 'local');
                $paths[] = $path;
                if (DB::transactionLevel() > 0) {
                    DB::afterRollBack(fn () => Storage::disk('local')->delete($path));
                }
                $name = preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $file->getClientOriginalName())));
                LuponEvidence::create(['barangay_id' => $barangay, 'complaint_id' => $complaint?->id, 'case_id' => $case?->id, 'resident_id' => $resident, 'uploaded_by' => $staff, 'original_name' => mb_substr($name, 0, 255), 'path' => $path, 'mime_type' => $file->getMimeType(), 'size' => $file->getSize()]);
            }

            return $paths;
        } catch (Throwable $error) {
            Storage::disk('local')->delete($paths);
            throw $error;
        }
    }

    public static function summary(LuponEvidence $evidence, string $portal = 'lupon'): array
    {
        return ['id' => $evidence->id, 'name' => $evidence->original_name, 'size' => $evidence->size, 'mime' => $evidence->mime_type, 'shared' => $evidence->visible_to_parties, 'url' => route(($portal === 'resident' ? 'resident.lupon.evidence.download' : 'lupon.evidence.download'), ['evidence' => $evidence->id])];
    }
}
