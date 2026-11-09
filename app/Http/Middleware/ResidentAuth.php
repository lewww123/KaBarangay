<?php

namespace App\Http\Middleware;

use App\Models\Resident;
use App\Support\OfflineDegraded;
use App\Support\ResidentContext;
use Closure;
use Illuminate\Database\ConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PDOException;
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
            Log::warning('Resident authentication rejected.', [
                'reason' => 'missing_resident_session',
                'path' => $request->path(),
            ]);

            return $this->unauthenticated($request);
        }

        // Make sure the resident still exists and is active.
        try {
            $resident = ResidentContext::find();
        } catch (QueryException|ConnectionException|PDOException $exception) {
            if (! OfflineDegraded::causedByDatabaseLoss($exception)) {
                throw $exception;
            }

            // An unreachable database is not evidence that this resident is gone;
            // answering null here would fall into the logout branch below.
            return OfflineDegraded::respond($request, $exception);
        }

        // If the resident no longer exists, is inactive,
        // or has no active login account, log them out.
        if (
            ! $resident
        ) {
            try {
                $candidate = Resident::query()->with('account')->find($residentId);
            } catch (QueryException|ConnectionException|PDOException $exception) {
                if (! OfflineDegraded::causedByDatabaseLoss($exception)) {
                    throw $exception;
                }

                // The session is still intact here, so degrading costs the
                // resident nothing they had before this request.
                return OfflineDegraded::respond($request, $exception);
            }

            $reason = match (true) {
                $candidate === null => 'resident_not_found',
                $candidate->status !== 'active' => 'resident_inactive',
                ! $candidate->account?->is_active => 'resident_account_inactive',
                default => 'registration_not_approved_for_resident_barangay',
            };

            Log::warning('Resident authentication rejected.', [
                'reason' => $reason,
                'resident_id' => $residentId,
                'path' => $request->path(),
            ]);

            $request->session()->forget([
                'resident_id',
                'resident_barangay_id',
                'resident_logged_in',
                'resident_remember',
            ]);

            return $this->unauthenticated($request);
        }

        // Store the verified resident ID again.
        $request->session()->put('resident_id', $resident->id);
        $request->attributes->set(ResidentContext::ATTRIBUTE, $resident);

        return $next($request);
    }

    private function unauthenticated(Request $request): Response
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => 'Unauthenticated.'], 401)
                ->header('Cache-Control', 'private, no-store, max-age=0');
        }
        return redirect()->route('resident.login');
    }
}
