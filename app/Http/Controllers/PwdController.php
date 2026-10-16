<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\Resident;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PwdController extends Controller
{
    public function dashboard(Request $request): Response
    {
        $user = $request->user();

        if (! in_array($user->role, ['pwd_staff', 'admin'])) {
            abort(403, 'Unauthorized access to PWD Sector Portal.');
        }

        $pwdResidentsCount = Resident::where('status', 'active')
            ->whereHas('sectors', fn ($s) => $s->where('slug', 'pwd')->orWhere('name', 'LIKE', '%PWD%'))
            ->count();

        $pwdAnnouncements = Announcement::where('target_audience', 'pwd');

        return Inertia::render('Pwd/Dashboard', [
            'userName' => $user->name,
            'stats' => [
                'totalResidents' => $pwdResidentsCount,
                'totalAnnouncements' => (clone $pwdAnnouncements)->count(),
                'pendingAnnouncements' => (clone $pwdAnnouncements)->where('approval_status', 'pending')->count(),
                'approvedAnnouncements' => (clone $pwdAnnouncements)->where('approval_status', 'approved')->count(),
            ],
            'recentAnnouncements' => (clone $pwdAnnouncements)->latest()->take(5)->get(),
        ]);
    }
}