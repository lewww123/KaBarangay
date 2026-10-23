<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Announcement;
use App\Models\AnnouncementApprovalLog;
use App\Support\AuditLogger;

/**
 * Shared audit-trail writer for the staff announcement desks.
 *
 * The announcement_approval_logs migration enumerates the supported actions
 * ('created', 'updated', 'resubmitted', 'approved', 'rejected'). Staff
 * submissions and resubmissions therefore belong in the same trail as the
 * administrator's approve/reject decisions, instead of only the latter being
 * recorded.
 */
trait RecordsAnnouncementApprovalActivity
{
    /**
     * Persist an entry in the announcement approval audit trail.
     *
     * The parent barangay is derived by the AnnouncementApprovalLog model from
     * the authenticated actor, so it is not passed explicitly.
     */
    protected function logAnnouncementApproval(
        Announcement $announcement,
        ?int $actorId,
        string $action,
        ?string $previousStatus,
        string $newStatus,
        ?string $notes = null,
    ): void {
        AnnouncementApprovalLog::create([
            'announcement_id' => $announcement->id,
            'actor_id' => $actorId,
            'action' => $action,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'notes' => $notes,
        ]);
    }

    /**
     * Persist one unified lifecycle event in the cross-cutting audit trail.
     *
     * Unlike the announcement-scoped approval log, this record survives the
     * announcement's deletion. Only the allowlisted status keys are passed;
     * AuditLogger drops every other key, so titles, content, rejection reasons
     * and other free text can never reach storage. Callers invoke this after
     * the lifecycle operation has committed, so a rolled-back transaction
     * never produces an audit row.
     */
    protected function recordAnnouncementAudit(
        Announcement $announcement,
        ?int $actorId,
        string $event,
        ?string $previousStatus,
        ?string $newStatus,
        ?string $ipAddress = null,
    ): void {
        AuditLogger::record(
            $event,
            $announcement->barangay_id,
            $actorId,
            'announcement',
            $announcement->id,
            $this->announcementAuditContext($previousStatus, $newStatus),
            $ipAddress,
        );
    }

    /**
     * Persist the announcement.deleted event using identifiers captured before
     * the row was destroyed.
     *
     * The announcement is already gone by the time this runs, so the entity and
     * barangay identifiers are paired required arguments instead of being read
     * back off a deleted model. This keeps the caller from succeeding with a
     * half-captured identifier.
     */
    protected function recordAnnouncementDeletionAudit(
        int $entityId,
        int $barangayId,
        ?int $actorId,
        ?string $previousStatus,
        ?string $ipAddress = null,
    ): void {
        AuditLogger::record(
            'announcement.deleted',
            $barangayId,
            $actorId,
            'announcement',
            $entityId,
            $this->announcementAuditContext($previousStatus, null),
            $ipAddress,
        );
    }

    /**
     * Build the allowlisted status context shared by the lifecycle writers.
     *
     * @return array<string, string>
     */
    private function announcementAuditContext(?string $previousStatus, ?string $newStatus): array
    {
        $context = [];

        if ($previousStatus !== null) {
            $context['previous_status'] = $previousStatus;
        }

        if ($newStatus !== null) {
            $context['new_status'] = $newStatus;
        }

        return $context;
    }
}
