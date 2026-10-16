<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\Resident;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClerkController extends Controller
{
    public function dashboard(Request $request): Response
    {
        $user = $request->user();

        if (! in_array($user->role, ['clerk', 'admin'])) {
            abort(403, 'Unauthorized access to General Clerk Portal.');
        }

        return Inertia::render('Clerk/Dashboard', [
            'userName' => $user->name,
            'stats' => [
                'totalResidents' => Resident::where('status', 'active')->count(),
                'totalAnnouncements' => Announcement::count(),
                'pendingAnnouncements' => Announcement::where('approval_status', 'pending')->count(),
                'approvedAnnouncements' => Announcement::where('approval_status', 'approved')->count(),
            ],
            'recentAnnouncements' => Announcement::latest()->take(5)->get(),
        ]);
    }
}