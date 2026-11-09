<?php

namespace App\Support;

use App\Models\LuponComplaint;

final class LuponReferences
{
    /** Existing LUP-year-five-digit sequence, protected by the existing unique key. */
    public static function next(string $prefix): string
    {
        $last = LuponComplaint::where('reference', 'like', $prefix.'%')->orderByDesc('reference')->value('reference');
        $sequence = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;
        do {
            $reference = $prefix.str_pad((string) $sequence++, 5, '0', STR_PAD_LEFT);
        } while (LuponComplaint::where('reference', $reference)->exists());
        return $reference;
    }
}
