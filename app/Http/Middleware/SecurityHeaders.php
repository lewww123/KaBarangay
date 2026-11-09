<?php

namespace App\Http\Middleware;

use App\Support\OfflineIdentity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Resolving the scope must never discard an already-generated response. Identity
        // lookups can touch the database; a genuine connectivity loss or any unexpected
        // failure is treated as unresolved (fail closed) so no scope is issued or revoked.
        try {
            $identity = OfflineIdentity::resolve($request);
        } catch (\Throwable $exception) {
            // Fail closed: never emit or clear a scope when identity cannot be verified.
            // The original response is preserved; the exception is logged by OfflineIdentity
            // when it can classify it, otherwise we log minimally here.
            \Illuminate\Support\Facades\Log::warning('Offline scope header resolution failed; response preserved with unresolved identity.', [
                'reason' => get_class($exception),
                'path' => $request->path(),
            ]);
            $identity = ['scope' => null, 'state' => OfflineIdentity::UNRESOLVED];
        }

        $unresolved = ($identity['state'] ?? OfflineIdentity::UNRESOLVED) === OfflineIdentity::UNRESOLVED;
        if (! $unresolved) {
            // Absent, not empty: an unreadable account state must not revoke an existing offline partition.
            $response->headers->set('X-KaBarangay-Offline-Scope', $identity['scope'] ?? '');
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        // Reset links and private document URLs must not leak to other sites.
        $response->headers->set('Referrer-Policy', 'same-origin');

        $hasIdentitySignal = $unresolved
            || ($identity['scope'] ?? null) !== null
            || ($request->hasSession() && $request->session()->has('resident_id'));

        if (! $hasIdentitySignal) {
            try {
                $hasIdentitySignal = $request->user() !== null;
            } catch (\Throwable) {
                // Fail closed for cache policy when user state cannot be read.
                $hasIdentitySignal = true;
            }
        }

        if ($hasIdentitySignal) {
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        }

        return $response;
    }
}
