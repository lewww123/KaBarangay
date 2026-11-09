<?php

namespace App\Http\Controllers;

use App\Support\OfflineCertificateContext;
use App\Support\OfflineIdentity;
use App\Support\ResidentContext;
use Illuminate\Database\ConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PDOException;

class OfflineAccessController extends Controller
{
    public function session(Request $request): JsonResponse
    {
        $identity = OfflineIdentity::resolve($request);
        $unresolved = $identity['state'] === OfflineIdentity::UNRESOLVED;
        $owner = null;

        if (! $unresolved) {
            try {
                $resident = ResidentContext::find();
            } catch (QueryException|ConnectionException|PDOException) {
                $resident = null;
                $unresolved = true;
            }
            if (! $unresolved && $resident !== null) {
                $owner = OfflineCertificateContext::owner($resident);
            }
        }

        // The endpoint answers so the app can tell a live server from a dead one, but an
        // unreadable account state issues no scope, invents no owner and reuses no session value.
        return response()->json([
            'scope' => $unresolved ? null : $identity['scope'],
            'owner' => $owner,
            'identity' => $unresolved ? OfflineIdentity::UNRESOLVED : $identity['state'],
            // Fresh token for logout completion or queued certificate submission.
            // Offline storage never persists this token.
            'csrfToken' => $request->session()->token(),
        ])->header('Cache-Control', 'private, no-store, max-age=0');
    }
}
