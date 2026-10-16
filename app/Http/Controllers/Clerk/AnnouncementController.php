<?php

namespace App\Http\Controllers\Clerk;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\AnnouncementAttendance;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AnnouncementController extends Controller
{
    public function index()
    {
        return Inertia::render('Clerk/Announcements/Index', [
            'announcements' => Announcement::with('author:id,name')->latest()->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'category' => 'required|string',
            'target_audience' => 'required|string',
            'requires_attendance' => 'boolean',
            'event_start_at' => 'nullable|date',
            'location' => 'nullable|string|max:255',
        ]);

        Announcement::create([
            ...$validated,
            'created_by' => $request->user()->id,
            'approval_status' => 'pending',
            'is_published' => false,
        ]);

        return redirect()->back()->with('success', 'Announcement submitted for approval.');
    }

    public function getAttendanceSheet(Announcement $announcement)
    {
        $attendances = AnnouncementAttendance::with(['resident' => fn ($q) => $q->with('purok')])
            ->where('announcement_id', $announcement->id)
            ->latest()
            ->get();

        return Inertia::render('Clerk/Announcements/Attendance', [
            'announcement' => $announcement,
            'attendances' => $attendances,
        ]);
    }
}