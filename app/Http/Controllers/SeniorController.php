<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\Resident;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SeniorController extends Controller
{
    public function dashboard(Request $request): Response
    {
        $user = $request->user();

        // Tanggapin kapag 'senior', 'senior_staff', o 'admin'
        if (! in_array($user->role, ['senior', 'senior_staff', 'admin'])) {
            abort(403, 'Unauthorized access to Senior Citizens Portal.');
        }

        return Inertia::render('Senior/Dashboard', [
            'userName' => $user->name,
            'stats' => [
                'totalResidents' => Resident::where('status', 'active')->count(),
                'totalAnnouncements' => Announcement::where('target_audience', 'senior_citizens')->count(),
                'pendingAnnouncements' => Announcement::where('target_audience', 'senior_citizens')->where('approval_status', 'pending')->count(),
                'approvedAnnouncements' => Announcement::where('target_audience', 'senior_citizens')->where('approval_status', 'approved')->count(),
            ],
            'recentAnnouncements' => Announcement::where('target_audience', 'senior_citizens')->latest()->take(5)->get(),
        ]);
    }
}