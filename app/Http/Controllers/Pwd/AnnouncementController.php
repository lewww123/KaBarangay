<?php

namespace App\Http\Controllers\Pwd;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\AnnouncementAttendance;
use App\Models\Resident;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AnnouncementController extends Controller
{
    public function index(Request $request)
    {
        $announcements = Announcement::where('target_audience', 'pwd')
            ->where('barangay_id', $request->user()->barangay_id)
            ->with('author:id,name')
            ->latest()
            ->get();

        return Inertia::render('Pwd/Announcements/Index', [
            'announcements' => $announcements,
            'user' => [
                'name' => $request->user()->name,
                'staff_id' => $request->user()->staff_id,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'category' => 'required|string',
            'requires_attendance' => 'boolean',
            'event_start_at' => 'nullable|date',
            'location' => 'nullable|string|max:255',
        ]);

        Announcement::create([
            ...$validated,
            'barangay_id' => $request->user()->barangay_id,
            'target_audience' => 'pwd',
            'created_by' => $request->user()->id,
            'approval_status' => 'pending',
            'is_published' => false,
        ]);

        return redirect()->back()->with('success', 'PWD sector announcement submitted for admin approval.');
    }

    public function update(Request $request, Announcement $announcement)
    {
        if ($announcement->target_audience !== 'pwd' || $announcement->barangay_id !== $request->user()->barangay_id) {
            abort(403, 'Unauthorized action.');
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'category' => 'required|string',
            'requires_attendance' => 'boolean',
            'event_start_at' => 'nullable|date',
            'location' => 'nullable|string|max:255',
        ]);

        $announcement->update([
            ...$validated,
            'approval_status' => 'pending',
        ]);

        return redirect()->back()->with('success', 'Announcement updated and resubmitted for approval.');
    }

    public function destroy(Request $request, Announcement $announcement)
    {
        if ($announcement->target_audience !== 'pwd' || $announcement->barangay_id !== $request->user()->barangay_id) {
            abort(403, 'Unauthorized action.');
        }

        $announcement->delete();

        return redirect()->back()->with('success', 'Announcement deleted successfully.');
    }

    public function getAttendanceSheet(Request $request, Announcement $announcement)
    {
        if ($announcement->target_audience !== 'pwd' || $announcement->barangay_id !== $request->user()->barangay_id) {
            abort(403, 'Unauthorized action.');
        }

        $attendances = AnnouncementAttendance::with(['resident' => fn ($q) => $q->with('purok')])
            ->where('announcement_id', $announcement->id)
            ->latest()
            ->get();

        $residents = Resident::where('barangay_id', $request->user()->barangay_id)
            ->where('status', 'active')
            ->orderBy('last_name')
            ->get(['id', 'full_name', 'resident_number']);

        return Inertia::render('Pwd/Announcements/Attendance', [
            'announcement' => $announcement,
            'attendances' => $attendances,
            'residents' => $residents,
            'user' => [
                'name' => $request->user()->name,
                'staff_id' => $request->user()->staff_id,
            ],
        ]);
    }

    public function markAttendance(Request $request, Announcement $announcement)
    {
        $validated = $request->validate([
            'resident_id' => 'required|exists:residents,id',
            'status' => 'required|in:present,claimed,absent',
            'method' => 'nullable|string',
        ]);

        $resident = Resident::where('id', $validated['resident_id'])
            ->where('barangay_id', $request->user()->barangay_id)
            ->firstOrFail();

        AnnouncementAttendance::updateOrCreate(
            [
                'announcement_id' => $announcement->id,
                'resident_id' => $resident->id,
            ],
            [
                'status' => $validated['status'],
                'method' => $validated['method'] ?? 'manual',
                'attended_at' => now(),
            ]
        );

        return redirect()->back()->with('success', "Attendance recorded successfully for {$resident->full_name}.");
    }
}