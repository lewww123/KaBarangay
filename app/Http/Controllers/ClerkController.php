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

        // The authenticated staff record is the authority for dashboard scope.
        // A missing barangay_id therefore matches no announcements.
        $announcements = Announcement::query()
            ->where('barangay_id', $user->barangay_id);

        return Inertia::render('Clerk/Dashboard', [
            'userName' => $user->name,
            'stats' => [
                'totalResidents' => Resident::where('status', 'active')
                    ->where('barangay_id', $user->barangay_id)
                    ->count(),
                'totalAnnouncements' => (clone $announcements)->count(),
                'pendingAnnouncements' => (clone $announcements)
                    ->where('approval_status', 'pending')
                    ->count(),
                'approvedAnnouncements' => (clone $announcements)
                    ->where('approval_status', 'approved')
                    ->count(),
            ],
            'recentAnnouncements' => (clone $announcements)->latest()
                ->take(5)
                ->get(['id', 'title', 'category', 'approval_status', 'is_published', 'created_at'])
                ->map(fn (Announcement $announcement) => [
                    'id' => $announcement->id,
                    'title' => $announcement->title,
                    'category' => $announcement->category,
                    'approval_status' => $announcement->approval_status,
                    'is_published' => (bool) $announcement->is_published,
                    'created_at' => $announcement->created_at?->toISOString(),
                ])
                ->values(),
        ]);
    }
}
