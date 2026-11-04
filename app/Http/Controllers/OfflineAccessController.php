<?php

namespace App\Http\Controllers;

use App\Support\OfflineCertificateContext;
use App\Support\OfflineIdentity;
use App\Support\ResidentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OfflineAccessController extends Controller
{
    public function session(Request $request): JsonResponse
    {
        return response()->json([
            'scope' => OfflineIdentity::scope($request),
            'owner' => ($resident = ResidentContext::find()) ? OfflineCertificateContext::owner($resident) : null,
            // Fresh token for logout completion or queued certificate submission.
            // Offline storage never persists this token.
            'csrfToken' => $request->session()->token(),
        ])->header('Cache-Control', 'private, no-store, max-age=0');
    }
}
