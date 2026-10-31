<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaffRole
{
    /**
     * Ensure the authenticated staff account is active and holds one of the
     * roles allowed for the route.
     *
     * The reserved `super_admin` role is intentionally absent from every
     * allowed-roles list, keeping it dormant with no special bypass.
     */
    public function handle(Request $request, Closure $next, string ...$allowedRoles): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        if ($user->status !== 'active') {
            abort(403, 'This account is inactive.');
        }

        if (! $user->hasActiveAssignment($allowedRoles)) {
            abort(403, 'Unauthorized access.');
        }

        return $next($request);
    }
}
