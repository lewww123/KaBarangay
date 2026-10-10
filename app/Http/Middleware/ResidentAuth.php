<?php

namespace App\Http\Middleware;

use App\Models\Resident;
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

        if (!$residentId) {
            return redirect()->route('resident.login');
        }

        // Make sure the resident still exists and is active.
        $resident = Resident::query()
            ->with('account')
            ->where('id', $residentId)
            ->where('status', 'active')
            ->first();

        // If the resident no longer exists, is inactive,
        // or has no active login account, log them out.
        if (
            !$resident ||
            !$resident->account ||
            !$resident->account->is_active
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

        return $next($request);
    }
}