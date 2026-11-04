<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Resident\SessionController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class LoginController extends Controller
{
    public function destroy(Request $request): RedirectResponse
    {
        // Older resident tabs still post to /logout. Resident sessions do not
        // authenticate the staff guard, so this endpoint must not require it.
        $referer = parse_url((string) $request->header('referer'));
        $residentOrigin = is_array($referer)
            && ($referer['host'] ?? '') === $request->getHost()
            && preg_match('#^/resident(?:/|$)#', $referer['path'] ?? '') === 1;
        if ($residentOrigin || ($request->session()->has('resident_id') && $request->user() === null)) {
            return app(SessionController::class)->destroy($request);
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Inertia::clearHistory();

        return redirect()->route('login');
    }
}
