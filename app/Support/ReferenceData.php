<?php

namespace App\Support;

use App\Models\Barangay;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Cache display-only catalogs, never authorization or mutable masterlist rows. */
final class ReferenceData
{
    public static function remember(string $key, int $seconds, Closure $resolve): mixed
    {
        // A transaction may contain unpublished catalog edits (including tests).
        if (DB::connection()->transactionLevel() > 0) {
            return $resolve();
        }

        return Cache::remember($key, $seconds, $resolve);
    }

    public static function barangay(int $id): array
    {
        return self::remember('reference:barangay:'.$id, 300, fn () => Barangay::findOrFail($id)->toArray());
    }
}
