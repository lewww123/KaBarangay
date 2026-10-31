<?php

namespace App\Support;

use App\Models\Resident;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** The validated resident is reused only within the current HTTP request. */
final class ResidentContext
{
    public const ATTRIBUTE = 'authenticated_resident';

    public static function find(): ?Resident
    {
        $request = request();
        $resident = $request->attributes->get(self::ATTRIBUTE);
        $residentId = $request->session()->get('resident_id');

        if (! $residentId) {
            return null;
        }

        if (! ($resident instanceof Resident) || (int) $resident->id !== (int) $residentId) {
            $resident = Resident::query()
                ->whereKey($residentId)
                ->where('status', 'active')
                ->whereHas('account', fn ($query) => $query->where('is_active', true))
                ->first();
            $request->attributes->set(self::ATTRIBUTE, $resident);
        }

        return $resident;
    }

    public static function get(array $relations = []): Resident
    {
        $resident = self::find() ?? throw (new ModelNotFoundException)->setModel(Resident::class);

        return $resident->loadMissing($relations);
    }
}
