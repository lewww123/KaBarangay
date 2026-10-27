<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureResidentRegistrationAccess
{
    public const SESSION_KEY = 'resident_registration_access';

    public const IDLE_TIMEOUT_SECONDS = 900;

    public const ABSOLUTE_TIMEOUT_SECONDS = 7200;

    public function handle(Request $request, Closure $next): Response
    {
        $access = $request->session()->get(self::SESSION_KEY);
        $now = now()->getTimestamp();

        if (! $this->isValidContext($access) || $now >= $access['idle_expires_at'] || $now >= $access['absolute_expires_at']) {
            $request->session()->forget(self::SESSION_KEY);
            $request->session()->regenerate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('resident.registration.access')
                ->with('access_feedback', 'Your secure application session expired. Sign in again to continue.');
        }

        $reference = $request->route('reference');
        if (! is_string($reference) || ! hash_equals($access['reference'], $reference)) {
            abort(404);
        }

        $access['last_activity_at'] = $now;
        $access['idle_expires_at'] = min(
            $now + self::IDLE_TIMEOUT_SECONDS,
            $access['absolute_expires_at'],
        );
        $request->session()->put(self::SESSION_KEY, $access);

        return $next($request);
    }

    private function isValidContext(mixed $access): bool
    {
        return is_array($access)
            && isset(
                $access['registration_id'],
                $access['reference'],
                $access['authenticated_at'],
                $access['last_activity_at'],
                $access['idle_expires_at'],
                $access['absolute_expires_at'],
            )
            && is_int($access['registration_id'])
            && $access['registration_id'] > 0
            && is_string($access['reference'])
            && Str::isUuid($access['reference'])
            && is_int($access['authenticated_at'])
            && is_int($access['last_activity_at'])
            && is_int($access['idle_expires_at'])
            && is_int($access['absolute_expires_at'])
            && $access['authenticated_at'] > 0
            && $access['last_activity_at'] >= $access['authenticated_at']
            && $access['absolute_expires_at'] > $access['authenticated_at']
            && $access['idle_expires_at'] <= $access['absolute_expires_at'];
    }
}
