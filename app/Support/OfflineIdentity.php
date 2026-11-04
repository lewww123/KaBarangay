<?php

namespace App\Support;

use Illuminate\Http\Request;

final class OfflineIdentity
{
    public static function scope(Request $request): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        $user = $request->user();
        $resident = $request->session()->has('resident_id') ? ResidentContext::find() : null;
        $staffId = $user?->status === 'active' ? $user->id : null;
        if ($staffId === null && $resident === null) {
            return null;
        }

        return hash_hmac('sha256', implode(':', [
            $request->session()->getId(), $staffId ?? '', $resident?->id ?? '',
        ]), (string) config('app.key'));
    }
}
