<?php

namespace App\Http\Controllers\Clerk;

use App\Http\Controllers\Concerns\ManagesAnnouncementAttendance;
use App\Http\Controllers\Concerns\ManagesAnnouncementCompletion;
use App\Http\Controllers\Concerns\PublishesPrograms;
use App\Http\Controllers\Concerns\RecordsAnnouncementApprovalActivity;
use App\Http\Controllers\Concerns\ValidatesAnnouncementEventDetails;
use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\AnnouncementAttendance;
use App\Support\AnnouncementLifecycle;
use App\Support\AnnouncementType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AnnouncementController extends Controller
{
    use ManagesAnnouncementAttendance;
    use ManagesAnnouncementCompletion;
    use PublishesPrograms;
    use RecordsAnnouncementApprovalActivity;
    use ValidatesAnnouncementEventDetails;

    public function index(Request $request, string $kind = 'auto')
    {
        $kind = in_array($kind, ['update', 'program'], true) ? $kind : 'auto';

        $query = Announcement::query();

        if ($kind === 'update') {
            AnnouncementType::applyUpdateFilter($query);
        } elseif ($kind === 'program') {
            AnnouncementType::applyProgramFilter($query);
        }

        $activeTab = $kind === 'update'
            ? AnnouncementLifecycle::TAB_ALL
            : AnnouncementLifecycle::normalizeTab($request->query('tab'));

        return Inertia::render('Clerk/Announcements/Index', [
            'announcements' => $this->deferProp(fn () => AnnouncementLifecycle::applyTab(clone $query, $activeTab)
                ->with(['author:id,name', 'completer:id,name'])
                ->latest()
                ->get()
                ->map(fn (Announcement $announcement) => [
                    ...$announcement->toArray(),
                    'attendance_available' => $announcement->attendanceIsAvailable(),
                    'type' => AnnouncementType::for($announcement),
                ])),
            'kind' => $kind,
            'activeTab' => $activeTab,
            'tabCounts' => $this->deferProp(fn () => AnnouncementLifecycle::counts($query)),
            'user' => [
                'name' => $request->user()->name,
                'staffAssignments' => $request->user()->staffAssignments()
                    ->where('department', 'clerk')->where('is_active', true)
                    ->get(['department', 'position']),
            ],
        ]);
    }

    public function show(Request $request, Announcement $announcement, string $kind = 'auto')
    {
        $this->authorizeBarangay($request, $announcement);
        abort_unless(AnnouncementType::matchesKind($announcement, $kind), 404);
        $announcement->load(['author:id,name', 'completer:id,name']);
        $kind = in_array($kind, ['update', 'program'], true) ? $kind : 'auto';

        return Inertia::render('Clerk/Announcements/Show', [
            'announcement' => [
                ...$announcement->toArray(),
                'attendance_available' => $announcement->attendanceIsAvailable(),
                'type' => AnnouncementType::for($announcement),
            ],
            'kind' => $kind,
            'user' => [
                'name' => $request->user()->name,
                'staffAssignments' => $request->user()->staffAssignments()
                    ->where('department', 'clerk')->where('is_active', true)
                    ->get(['department', 'position']),
            ],
        ]);
    }

    public function store(Request $request, string $kind = 'auto')
    {
        $kind = in_array($kind, ['update', 'program'], true) ? $kind : 'auto';
        $requiresEvent = $kind === 'program' || ($kind === 'auto' && $this->eventDetailsRequired($request));

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'category' => 'required|string',
            'importance' => ['sometimes', 'required', 'string', Rule::in(Announcement::IMPORTANCE_LEVELS)],
            'target_audience' => ['required', Rule::in(['all', 'senior_citizens', 'pwd'])],
            'requires_attendance' => 'boolean',
            'event_start_at' => ['nullable', 'date', Rule::requiredIf($requiresEvent)],
            'location' => ['nullable', 'string', 'max:255', Rule::requiredIf($requiresEvent)],
        ]);

        if ($kind === 'update') {
            $validated['category'] = $validated['category'] === 'Event' ? 'General' : $validated['category'];
            $validated['requires_attendance'] = false;
            $validated['event_start_at'] = null;
            $validated['location'] = null;
        } elseif ($kind === 'program') {
            $validated['category'] = 'Event';
        }

        if ($requiresEvent && $kind !== 'update') {
            return $this->publishProgram($request, $validated);
        }

        $isDirectUpdate = $kind === 'update';

        $announcement = DB::transaction(function () use ($validated, $request, $isDirectUpdate) {
            $announcement = Announcement::create([
                ...$validated,
                'created_by' => $request->user()->id,
                'approval_status' => $isDirectUpdate ? 'approved' : 'pending',
                'is_published' => $isDirectUpdate,
                'reviewed_by' => $isDirectUpdate ? $request->user()->id : null,
                'reviewed_at' => $isDirectUpdate ? now() : null,
            ]);

            $this->logAnnouncementApproval(
                $announcement,
                $request->user()->id,
                $isDirectUpdate ? 'approved' : 'created',
                null,
                $announcement->approval_status,
                $isDirectUpdate ? 'Update published directly by Clerk.' : null,
            );

            return $announcement;
        });

        $this->recordAnnouncementAudit(
            $announcement,
            $request->user()->id,
            $isDirectUpdate ? 'announcement.published' : 'announcement.created',
            null,
            $announcement->approval_status,
            $request->ip(),
        );

        return redirect()->back()->with(
            'success',
            $isDirectUpdate ? 'Update published immediately.' : 'Program submitted for approval.'
        );
    }

    public function update(Request $request, Announcement $announcement, string $kind = 'auto')
    {
        $this->authorizeBarangay($request, $announcement);
        abort_unless(AnnouncementType::matchesKind($announcement, $kind), 404);
        $this->ensureAnnouncementIsEditable($announcement);

        $kind = in_array($kind, ['update', 'program'], true) ? $kind : 'auto';
        $requiresEvent = $kind === 'program' || ($kind === 'auto' && $this->eventDetailsRequired($request));

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'category' => 'required|string',
            'importance' => ['sometimes', 'required', 'string', Rule::in(Announcement::IMPORTANCE_LEVELS)],
            'target_audience' => ['required', Rule::in(['all', 'senior_citizens', 'pwd'])],
            'requires_attendance' => 'boolean',
            'event_start_at' => ['nullable', 'date', Rule::requiredIf($requiresEvent)],
            'location' => ['nullable', 'string', 'max:255', Rule::requiredIf($requiresEvent)],
        ]);

        if ($kind === 'update') {
            $validated['category'] = $validated['category'] === 'Event' ? 'General' : $validated['category'];
            $validated['requires_attendance'] = false;
            $validated['event_start_at'] = null;
            $validated['location'] = null;
        } elseif ($kind === 'program') {
            $validated['category'] = 'Event';
        }

        if ($requiresEvent && $kind !== 'update') {
            return $this->publishProgram($request, $validated, $announcement);
        }

        $isDirectUpdate = $kind === 'update';
        $previousStatus = $announcement->approval_status;
        $newStatus = $isDirectUpdate ? 'approved' : 'pending';

        DB::transaction(function () use ($announcement, $validated, $request, $previousStatus, $isDirectUpdate, $newStatus) {
            $announcement->update([
                ...$validated,
                'approval_status' => $newStatus,
                'is_published' => $isDirectUpdate,
                'rejection_reason' => $isDirectUpdate ? null : $announcement->rejection_reason,
                'reviewed_by' => $isDirectUpdate ? $request->user()->id : null,
                'reviewed_at' => $isDirectUpdate ? now() : null,
            ]);

            $this->logAnnouncementApproval(
                $announcement,
                $request->user()->id,
                $isDirectUpdate ? 'approved' : 'resubmitted',
                $previousStatus,
                $newStatus,
                $isDirectUpdate ? 'Update republished directly by Clerk.' : null,
            );
        });

        $this->recordAnnouncementAudit(
            $announcement,
            $request->user()->id,
            $isDirectUpdate ? 'announcement.published' : 'announcement.resubmitted',
            $previousStatus,
            $newStatus,
            $request->ip(),
        );

        return redirect()->back()->with(
            'success',
            $isDirectUpdate ? 'Update republished immediately.' : 'Program updated and resubmitted for approval.'
        );
    }

    public function destroy(Request $request, Announcement $announcement, string $kind = 'auto')
    {
        $this->authorizeBarangay($request, $announcement);
        abort_unless(AnnouncementType::matchesKind($announcement, $kind), 404);
        abort_if(
            $announcement->completed_at !== null && AnnouncementType::isInformational($announcement),
            409,
            'Completed updates cannot be deleted.',
        );

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
        $this->authorizeBarangay($request, $announcement);
        $this->ensureAttendanceIsOpen($announcement);

        $attendances = fn () => AnnouncementAttendance::with(['resident' => function ($query) {
            $query->select('id', 'first_name', 'last_name', 'middle_name', 'suffix', 'resident_number');
        }])
            ->where('announcement_id', $announcement->id)
            ->latest()
            ->get();

        $residents = fn () => $this->eligibleResidentsQuery($announcement, $request->user()->barangay_id)
            ->get(['id', 'first_name', 'last_name', 'middle_name', 'suffix', 'resident_number']);

        return Inertia::render('Clerk/Announcements/Attendance', [
            'announcement' => $announcement,
            'attendances' => $this->deferProp($attendances),
            'residents' => $this->deferProp($residents),
            'user' => [
                'name' => $request->user()->name,
                'department' => 'Clerk',
            ],
        ]);
    }

    public function markDone(Request $request, Announcement $announcement)
    {
        $this->authorizeBarangay($request, $announcement);

        $result = $this->completeAnnouncement(
            $announcement,
            $request->user()->id,
            $request->ip(),
        );

        return redirect()->back()->with($result['tone'], $result['message']);
    }

    public function markUpdateDone(Request $request, Announcement $announcement)
    {
        $this->authorizeBarangay($request, $announcement);
        abort_unless(AnnouncementType::isInformational($announcement), 404);

        $result = $this->completeInformationalUpdate(
            $announcement,
            $request->user()->id,
            $request->ip(),
        );

        return redirect()->back()->with($result['tone'], $result['message']);
    }

    public function markAttendance(Request $request, Announcement $announcement)
    {
        $this->authorizeBarangay($request, $announcement);
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

        $result = $this->recordAttendance(
            $announcement,
            $resident,
            $validated['status'],
            $validated['method'] ?? null,
            $request->user()->id,
        );

        return $this->attendanceResponse($result);
    }

    private function authorizeBarangay(Request $request, Announcement $announcement): void
    {
        if ($announcement->barangay_id !== $request->user()->barangay_id) {
            abort(403, 'Unauthorized action.');
        }
    }
}
