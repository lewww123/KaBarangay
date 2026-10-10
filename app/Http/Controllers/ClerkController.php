<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClerkController extends Controller
{
    public function dashboard(Request $request): Response
    {
        $user = $request->user();

        // Security: I-kick out ang user kung hindi 'clerk' ang role niya
        if ($user->role !== 'clerk') {
            abort(403, 'Unauthorized access. This page is for Barangay Clerks only.');
        }

        // I-render ang React page at ipasa ang user data
        return Inertia::render('Clerk/Dashboard', [
            'auth' => [
                'user' => $user,
            ],
        ]);
    }
}