<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\RecordsAnnouncementApprovalActivity;
use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\AnnouncementApprovalLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AnnouncementApprovalController extends Controller
{
    use RecordsAnnouncementApprovalActivity;

    /**
     * Statuses from which an administrator may reach an approval decision.
     * Approve/reject are one-way transitions out of "pending"; any other
     * state is rejected without mutating data or appending an audit entry.
     */
    private const REVIEWABLE_STATUS = 'pending';

    public function index()
    {
        $announcements = Announcement::with(['author:id,name,role', 'sector:id,name', 'reviewer:id,name', 'approvalLogs.actor:id,name'])
            ->where('barangay_id', auth()->user()->barangay_id)
            ->latest()
            ->get()
            ->map(fn (Announcement $announcement) => [
                ...$announcement->toArray(),
                'revision_token' => $announcement->revisionToken(),
                'publication' => $this->currentPublication($announcement),
            ]);

        return Inertia::render('Admin/AnnouncementApprovals', [
            'announcements' => $announcements,
        ]);
    }

    /**
     * Resolve the publication event that matches the announcement's current
     * public state. Approval logs are authoritative when present; the
     * announcement review columns only cover legacy rows without a log.
     *
     * @return array{published_at: string|null, published_by: string|null}|null
     */
    private function currentPublication(Announcement $announcement): ?array
    {
        if (! $announcement->is_published || $announcement->approval_status !== 'approved') {
            return null;
        }

        $latestStateLog = $announcement->approvalLogs
            ->first(fn (AnnouncementApprovalLog $log) => $log->action !== 'completed');

        if ($latestStateLog !== null) {
            if ($latestStateLog->action !== 'approved' || $latestStateLog->new_status !== 'approved') {
                return null;
            }

            return [
                'published_at' => $latestStateLog->created_at?->toIso8601String(),
                'published_by' => $latestStateLog->actor?->name,
            ];
        }

        if ($announcement->reviewed_at === null && $announcement->reviewer === null) {
            return null;
        }

        return [
            'published_at' => $announcement->reviewed_at?->toIso8601String(),
            'published_by' => $announcement->reviewer?->name,
        ];
    }

    public function approve(Request $request, Announcement $announcement)
    {
        $this->authorizeBarangay($announcement);
        $this->ensureReviewable($announcement);

        $validated = $request->validate([
            'revision_token' => ['required', 'string', 'size:64'],
        ]);

        $previousStatus = DB::transaction(function () use ($announcement, $validated) {
            $locked = Announcement::query()
                ->whereKey($announcement->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->authorizeBarangay($locked);
            $this->ensureReviewable($locked);
            $this->ensureRevisionMatches($locked, $validated['revision_token']);

            $previousStatus = $locked->approval_status;

            $locked->update([
                'approval_status' => 'approved',
                'is_published' => true, // Automatic na mai-publish sa Resident portal
                'rejection_reason' => null,
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
            ]);

            $this->logAnnouncementApproval(
                $locked,
                auth()->id(),
                'approved',
                $previousStatus,
                'approved',
                'Announcement approved for public distribution.',
            );

            return $previousStatus;
        }, 3);

        $this->recordAnnouncementAudit(
            $announcement,
            $request->user()->id,
            'announcement.approved',
            $previousStatus,
            'approved',
            $request->ip(),
        );

        return redirect()->back()->with('message', 'Announcement approved successfully.');
    }

    public function reject(Request $request, Announcement $announcement)
    {
        $this->authorizeBarangay($announcement);
        $this->ensureReviewable($announcement);

        $validated = $request->validate([
            'revision_token' => ['required', 'string', 'size:64'],
            'rejection_reason' => 'required|string|min:5|max:1000',
        ]);

        $previousStatus = DB::transaction(function () use ($announcement, $validated) {
            $locked = Announcement::query()
                ->whereKey($announcement->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->authorizeBarangay($locked);
            $this->ensureReviewable($locked);
            $this->ensureRevisionMatches($locked, $validated['revision_token']);

            $previousStatus = $locked->approval_status;

            $locked->update([
                'approval_status' => 'rejected',
                'is_published' => false, // Ii-unpublish sakaling dati nang nakalabas
                'rejection_reason' => $validated['rejection_reason'],
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
            ]);

            $this->logAnnouncementApproval(
                $locked,
                auth()->id(),
                'rejected',
                $previousStatus,
                'rejected',
                $validated['rejection_reason'],
            );

            return $previousStatus;
        }, 3);

        $this->recordAnnouncementAudit(
            $announcement,
            $request->user()->id,
            'announcement.rejected',
            $previousStatus,
            'rejected',
            $request->ip(),
        );

        return redirect()->back()->with('message', 'Announcement rejected.');
    }

    /**
     * Defense-in-depth barangay guard.
     *
     * Route-model binding already applies the Announcement "barangay" global
     * scope, so a foreign record normally resolves to 404 before this runs.
     * The explicit check keeps the invariant even if the scope is ever
     * bypassed, and deliberately reuses the same 404 the binding produces so
     * the observable behaviour is unchanged.
     */
    private function authorizeBarangay(Announcement $announcement): void
    {
        abort_unless(
            (int) $announcement->barangay_id === (int) auth()->user()->barangay_id,
            404,
        );
    }

    /**
     * Only pending announcements may be approved or rejected. Invalid
     * transitions raise a validation error (the existing convention used by
     * the certificate transition flow) and never touch the record or the
     * approval audit trail.
     */
    private function ensureReviewable(Announcement $announcement): void
    {
        if ($announcement->completed_at !== null) {
            throw ValidationException::withMessages([
                'approval' => 'Completed announcements cannot be approved or rejected.',
            ]);
        }

        if ($announcement->approval_status !== self::REVIEWABLE_STATUS) {
            throw ValidationException::withMessages([
                'approval' => 'Only announcements pending review can be approved or rejected.',
            ]);
        }
    }

    private function ensureRevisionMatches(Announcement $announcement, string $revisionToken): void
    {
        if (! hash_equals($announcement->revisionToken(), $revisionToken)) {
            throw ValidationException::withMessages([
                'approval' => 'This announcement changed after you loaded it. Refresh the approvals page and review it again.',
            ]);
        }
    }
}
