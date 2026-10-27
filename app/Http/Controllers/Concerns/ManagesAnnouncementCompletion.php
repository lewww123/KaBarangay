<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Announcement;
use App\Support\AnnouncementLifecycle;
use App\Support\AnnouncementType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

trait ManagesAnnouncementCompletion
{
    protected function ensureAnnouncementIsEditable(Announcement $announcement): void
    {
        if ($announcement->completed_at !== null) {
            throw ValidationException::withMessages([
                'announcement' => 'Completed announcements cannot be edited.',
            ]);
        }
    }

    /**
     * @return array{outcome: string, message: string, tone: string}
     */
    protected function completeAnnouncement(
        Announcement $announcement,
        ?int $actorId,
        ?string $ipAddress = null,
    ): array {
        if ($announcement->completed_at !== null) {
            return $this->alreadyCompletedOutcome();
        }

        if (
            $announcement->approval_status !== 'approved'
            || ! AnnouncementLifecycle::isEvent($announcement)
        ) {
            throw ValidationException::withMessages([
                'completion' => 'Only approved event announcements can be marked as done.',
            ]);
        }

        $completedAt = now();

        $updated = DB::transaction(function () use ($announcement, $actorId, $completedAt) {
            $affected = Announcement::query()
                ->whereKey($announcement->getKey())
                ->whereNull('completed_at')
                ->where('approval_status', 'approved')
                ->where(function (Builder $query) {
                    AnnouncementLifecycle::applyEventFilter($query);
                })
                ->update([
                    'completed_at' => $completedAt,
                    'completed_by' => $actorId,
                ]);

            if ($affected === 0) {
                return false;
            }

            $this->logAnnouncementApproval(
                $announcement,
                $actorId,
                'completed',
                'approved',
                'approved',
            );

            return true;
        });

        if (! $updated) {
            $fresh = $announcement->fresh();

            if ($fresh?->completed_at !== null) {
                return $this->alreadyCompletedOutcome();
            }

            throw ValidationException::withMessages([
                'completion' => 'Only approved event announcements can be marked as done.',
            ]);
        }

        $this->recordAnnouncementAudit(
            $announcement,
            $actorId,
            'announcement.completed',
            'approved',
            'approved',
            $ipAddress,
        );

        return [
            'outcome' => 'completed',
            'message' => 'Announcement marked as done.',
            'tone' => 'success',
        ];
    }

    /**
     * Complete a published informational update without changing its
     * approval or publication state.
     *
     * @return array{outcome: string, message: string, tone: string}
     */
    protected function completeInformationalUpdate(
        Announcement $announcement,
        ?int $actorId,
        ?string $ipAddress = null,
    ): array {
        $result = DB::transaction(function () use ($announcement, $actorId) {
            $locked = Announcement::query()
                ->whereKey($announcement->getKey())
                ->where('barangay_id', $announcement->barangay_id)
                ->where('target_audience', $announcement->target_audience)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->completed_at !== null) {
                return ['updated' => false, 'announcement' => $locked];
            }

            if (
                ! AnnouncementType::isInformational($locked)
                || $locked->approval_status !== 'approved'
                || ! $locked->is_published
            ) {
                throw ValidationException::withMessages([
                    'completion' => 'Only approved, published informational updates can be completed.',
                ]);
            }

            $locked->update([
                'completed_at' => now(),
                'completed_by' => $actorId,
            ]);

            $this->logAnnouncementApproval(
                $locked,
                $actorId,
                'update_completed',
                'approved',
                'approved',
                'Informational update marked completed by staff.',
            );

            return ['updated' => true, 'announcement' => $locked];
        }, 3);

        if (! $result['updated']) {
            return [
                'outcome' => 'already_completed',
                'message' => 'This update has already been marked as completed.',
                'tone' => 'warning',
            ];
        }

        $this->recordAnnouncementAudit(
            $result['announcement'],
            $actorId,
            'announcement.update_completed',
            'approved',
            'approved',
            $ipAddress,
        );

        return [
            'outcome' => 'completed',
            'message' => 'Update marked as completed.',
            'tone' => 'success',
        ];
    }

    /**
     * @return array{outcome: string, message: string, tone: string}
     */
    private function alreadyCompletedOutcome(): array
    {
        return [
            'outcome' => 'already_completed',
            'message' => 'This announcement has already been marked as done.',
            'tone' => 'warning',
        ];
    }
}
