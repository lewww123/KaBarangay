<?php

namespace App\Http\Middleware;

use App\Support\ResidentContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResidentAuth
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if there is a Resident ID stored in the session.
        $residentId = $request->session()->get('resident_id');

        if (! $residentId) {
            return redirect()->route('resident.login');
        }

        // Make sure the resident still exists and is active.
        $resident = ResidentContext::find();

        // If the resident no longer exists, is inactive,
        // or has no active login account, log them out.
        if (
            ! $resident
        ) {
            $request->session()->forget([
                'resident_id',
                'resident_barangay_id',
                'resident_logged_in',
                'resident_remember',
            ]);

            return redirect()->route('resident.login');
        }

        // Store the verified resident ID again.
        $request->session()->put('resident_id', $resident->id);
        $request->attributes->set(ResidentContext::ATTRIBUTE, $resident);

        return $next($request);
    }
}
