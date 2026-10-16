<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\AnnouncementApprovalLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class AnnouncementApprovalController extends Controller
{
    public function index()
    {
        $announcements = Announcement::with(['author:id,name,role', 'sector:id,name', 'reviewer:id,name', 'approvalLogs.actor:id,name'])
            ->latest()
            ->get();

        return Inertia::render('Admin/AnnouncementApprovals', [
            'announcements' => $announcements,
        ]);
    }

    public function approve(Announcement $announcement)
    {
        DB::transaction(function () use ($announcement) {
            $prevStatus = $announcement->approval_status;

            $announcement->update([
                'approval_status' => 'approved',
                'is_published' => true, // Automatic na mai-publish sa Resident portal
                'rejection_reason' => null,
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
            ]);

            AnnouncementApprovalLog::create([
                'announcement_id' => $announcement->id,
                'actor_id' => auth()->id(),
                'action' => 'approved',
                'previous_status' => $prevStatus,
                'new_status' => 'approved',
                'notes' => 'Announcement approved for public distribution.',
            ]);
        });

        return redirect()->back()->with('message', 'Announcement approved successfully.');
    }

    public function reject(Request $request, Announcement $announcement)
    {
        $validated = $request->validate([
            'rejection_reason' => 'required|string|min:5|max:1000',
        ]);

        DB::transaction(function () use ($announcement, $validated) {
            $prevStatus = $announcement->approval_status;

            $announcement->update([
                'approval_status' => 'rejected',
                'is_published' => false, // Ii-unpublish sakaling dati nang nakalabas
                'rejection_reason' => $validated['rejection_reason'],
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
            ]);

            AnnouncementApprovalLog::create([
                'announcement_id' => $announcement->id,
                'actor_id' => auth()->id(),
                'action' => 'rejected',
                'previous_status' => $prevStatus,
                'new_status' => 'rejected',
                'notes' => $validated['rejection_reason'],
            ]);
        });

        return redirect()->back()->with('message', 'Announcement rejected.');
    }
}