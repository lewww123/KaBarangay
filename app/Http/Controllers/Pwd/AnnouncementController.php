<?php

namespace App\Http\Controllers\Pwd;

use App\Http\Controllers\Concerns\ManagesAnnouncementAttendance;
use App\Http\Controllers\Concerns\RecordsAnnouncementApprovalActivity;
use App\Http\Controllers\Concerns\ValidatesAnnouncementEventDetails;
use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\AnnouncementAttendance;
use App\Support\AnnouncementType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AnnouncementController extends Controller
{
    use ManagesAnnouncementAttendance;
    use RecordsAnnouncementApprovalActivity;
    use ValidatesAnnouncementEventDetails;

    public function index(Request $request)
    {
        $announcements = Announcement::where('target_audience', 'pwd')
            ->where('barangay_id', $request->user()->barangay_id)
            ->with('author:id,name')
            ->latest()
            ->get()
            ->map(fn (Announcement $announcement) => [
                ...$announcement->toArray(),
                'type' => AnnouncementType::for($announcement),
            ]);

        return Inertia::render('Pwd/Announcements/Index', [
            'announcements' => $announcements,
            'user' => [
                'name' => $request->user()->name,
                'staff_id' => $request->user()->staff_id,
            ],
        ]);
    }

    public function show(Request $request, Announcement $announcement)
    {
        if ($announcement->target_audience !== 'pwd' || $announcement->barangay_id !== $request->user()->barangay_id) {
            abort(403, 'Unauthorized action.');
        }

        $announcement->load('author:id,name');

        return Inertia::render('Pwd/Announcements/Show', [
            'announcement' => [
                ...$announcement->toArray(),
                'type' => AnnouncementType::for($announcement),
            ],
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
            'event_start_at' => ['nullable', 'date', Rule::requiredIf($this->eventDetailsRequired($request))],
            'location' => ['nullable', 'string', 'max:255', Rule::requiredIf($this->eventDetailsRequired($request))],
        ]);

        $announcement = DB::transaction(function () use ($validated, $request) {
            $announcement = Announcement::create([
                ...$validated,
                'barangay_id' => $request->user()->barangay_id,
                'target_audience' => 'pwd',
                'created_by' => $request->user()->id,
                'approval_status' => 'pending',
                'is_published' => false,
            ]);

            $this->logAnnouncementApproval(
                $announcement,
                $request->user()->id,
                'created',
                null,
                'pending',
            );

            return $announcement;
        });

        $this->recordAnnouncementAudit(
            $announcement,
            $request->user()->id,
            'announcement.created',
            null,
            $announcement->approval_status,
            $request->ip(),
        );

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
            'event_start_at' => ['nullable', 'date', Rule::requiredIf($this->eventDetailsRequired($request))],
            'location' => ['nullable', 'string', 'max:255', Rule::requiredIf($this->eventDetailsRequired($request))],
        ]);

        $previousStatus = $announcement->approval_status;

        DB::transaction(function () use ($announcement, $validated, $request, $previousStatus) {
            // An edited or resubmitted announcement must not remain public until
            // an administrator approves it again.
            $announcement->update([
                ...$validated,
                'approval_status' => 'pending',
                'is_published' => false,
            ]);

            $this->logAnnouncementApproval(
                $announcement,
                $request->user()->id,
                'resubmitted',
                $previousStatus,
                'pending',
            );
        });

        $this->recordAnnouncementAudit(
            $announcement,
            $request->user()->id,
            'announcement.resubmitted',
            $previousStatus,
            $announcement->approval_status,
            $request->ip(),
        );

        return redirect()->back()->with('success', 'Announcement updated and resubmitted for approval.');
    }

    public function destroy(Request $request, Announcement $announcement)
    {
        if ($announcement->target_audience !== 'pwd' || $announcement->barangay_id !== $request->user()->barangay_id) {
            abort(403, 'Unauthorized action.');
        }

        $announcementId = $announcement->id;
        $barangayId = $announcement->barangay_id;
        $previousStatus = $announcement->approval_status;

        $announcement->delete();

        $this->recordAnnouncementDeletionAudit(
            $announcementId,
            $barangayId,
            $request->user()->id,
            $previousStatus,
            $request->ip(),
        );

        return redirect()->back()->with('success', 'Announcement deleted successfully.');
    }

    public function getAttendanceSheet(Request $request, Announcement $announcement)
    {
        if ($announcement->target_audience !== 'pwd' || $announcement->barangay_id !== $request->user()->barangay_id) {
            abort(403, 'Unauthorized action.');
        }

        $this->ensureAttendanceIsOpen($announcement);

        $attendances = AnnouncementAttendance::with(['resident' => fn ($q) => $q->with('purok')])
            ->where('announcement_id', $announcement->id)
            ->latest()
            ->get();

        $residents = $this->eligibleResidentsQuery($announcement, $request->user()->barangay_id)
            ->get(['id', 'first_name', 'last_name', 'middle_name', 'suffix', 'resident_number']);

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
        if ($announcement->target_audience !== 'pwd' || $announcement->barangay_id !== $request->user()->barangay_id) {
            abort(403, 'Unauthorized action.');
        }

        $this->ensureAttendanceIsOpen($announcement);

        $validated = $request->validate([
            'resident_id' => 'required|exists:residents,id',
            'status' => 'required|in:present,claimed,absent',
            'method' => ['nullable', 'string', Rule::in(self::ATTENDANCE_METHODS)],
            'qr_proof' => ['nullable', 'string'],
        ]);

        $resident = $this->findEligibleResident(
            $announcement,
            (int) $validated['resident_id'],
            $request->user()->barangay_id,
        );

        $this->ensureQrProofIsValid($request, $announcement, $resident, $validated['method'] ?? null);

        $this->recordAttendance(
            $announcement,
            $resident,
            $validated['status'],
            $validated['method'] ?? null,
            $request->user()->id,
        );

        return redirect()->back()->with('success', "Attendance recorded successfully for {$resident->full_name}.");
    }
}
