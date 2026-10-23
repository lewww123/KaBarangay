<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LuponController extends Controller
{
    public function dashboard(Request $request): Response
    {
        $user = $request->user();

        if ($user->role !== 'lupon') {
            abort(403, 'Unauthorized access to Lupon Portal.');
        }

        return Inertia::render('Lupon/Dashboard', [
            'userName' => $user->name,
            'barangayName' => $user->barangay?->name,
        ]);
    }
}
