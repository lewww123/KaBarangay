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

        if (! $user->hasActiveAssignment(['clerk', 'admin'])) {
            abort(403, 'Unauthorized access to General Clerk Portal.');
        }

        $barangayId = $user->barangay_id;

        return Inertia::render('Clerk/Dashboard', [
            'userName' => $user->name,
            'stats' => $this->deferProp(fn () => $this->rememberAggregate(
                "clerk:dashboard:stats:v2:{$barangayId}",
                30,
                function () use ($barangayId): array {
                    // The authenticated staff record is the authority for
                    // dashboard scope, so a missing barangay_id matches no
                    // announcements.
                    $announcements = Announcement::query()
                        ->where('barangay_id', $barangayId);

                    $counts = $announcements->selectRaw("COUNT(*) as total, COUNT(CASE WHEN approval_status = 'pending' THEN 1 END) as pending, COUNT(CASE WHEN approval_status = 'approved' AND (program_status IS NULL OR program_status != 'draft') THEN 1 END) as approved")
                        ->selectRaw("COUNT(CASE WHEN program_status = 'draft' OR (program_status IS NULL AND (category = 'Event' OR requires_attendance = true) AND completed_at IS NULL AND (approval_status != 'approved' OR is_published = false)) THEN 1 END) as draft_programs")
                        ->selectSub(Resident::query()->where('status', 'active')->where('barangay_id', $barangayId)->selectRaw('COUNT(*)'), 'residents')->first();

                    return [
                        'totalResidents' => (int) $counts->residents,
                        'totalAnnouncements' => (int) $counts->total,
                        'pendingAnnouncements' => (int) $counts->pending,
                        'draftPrograms' => (int) $counts->draft_programs,
                        'approvedAnnouncements' => (int) $counts->approved,
                    ];
                },
            )),
            'recentAnnouncements' => $this->deferProp(fn () => Announcement::query()
                ->where('barangay_id', $barangayId)
                ->latest()
                ->orderByDesc('id')
                ->take(5)
                ->get(['id', 'title', 'category', 'approval_status', 'is_published', 'created_at', 'program_status', 'requires_attendance', 'completed_at'])
                ->map(fn (Announcement $announcement) => [
                    'id' => $announcement->id,
                    'title' => $announcement->title,
                    'category' => $announcement->category,
                    'program_status' => $announcement->program_status,
                    'requires_attendance' => $announcement->requires_attendance,
                    'approval_status' => $announcement->approval_status,
                    'is_published' => (bool) $announcement->is_published,
                    'created_at' => $announcement->created_at?->toISOString(),
                ])
                ->values()),
        ]);
    }
}
