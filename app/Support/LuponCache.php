<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Single place for invalidating the barangay-scoped Lupon aggregates.
 *
 * The complaint queue, case stage counts and dashboard metrics all read
 * aggressively cached values, so every mutation that can change them must
 * clear the same versioned keys.
 */
final class LuponCache
{
    public static function forget(int $barangayId): void
    {
        Cache::forget('lupon:complaints:stats:'.$barangayId);
        Cache::forget('lupon:cases:counts:v3:'.$barangayId);
        Cache::forget('lupon:dashboard:metrics:v3:'.$barangayId);
    }
}
