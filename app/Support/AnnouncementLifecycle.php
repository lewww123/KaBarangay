<?php

namespace App\Support;

use App\Models\Announcement;
use Illuminate\Database\Eloquent\Builder;

final class AnnouncementLifecycle
{
    public const TAB_ALL = 'all';

    public const TAB_ONGOING = 'ongoing';

    public const TAB_PENDING = 'pending';

    public const TAB_APPROVED = 'approved';

    public const TAB_DONE = 'done';

    public const TABS = [
        self::TAB_ALL,
        self::TAB_ONGOING,
        self::TAB_PENDING,
        self::TAB_APPROVED,
        self::TAB_DONE,
    ];

    public static function normalizeTab(?string $tab): string
    {
        return in_array($tab, self::TABS, true) ? $tab : self::TAB_ALL;
    }

    public static function applyTab(Builder $query, string $tab): Builder
    {
        return match ($tab) {
            self::TAB_ONGOING => self::applyOngoing($query),
            self::TAB_PENDING => $query
                ->whereNull('completed_at')
                ->where('approval_status', 'pending'),
            self::TAB_APPROVED => self::applyApproved($query),
            self::TAB_DONE => $query->whereNotNull('completed_at'),
            default => $query,
        };
    }

    /**
     * @return array<string, int>
     */
    public static function counts(Builder $query): array
    {
        $counts = [];

        foreach (self::TABS as $tab) {
            $counts[$tab] = self::applyTab(clone $query, $tab)->count();
        }

        return $counts;
    }

    public static function isEvent(Announcement $announcement): bool
    {
        return $announcement->category === 'Event'
            || $announcement->requires_attendance === true;
    }

    public static function applyEventFilter(Builder $query): Builder
    {
        return $query->where(function (Builder $query) {
            $query->where('category', 'Event')
                ->orWhere('requires_attendance', true);
        });
    }

    private static function applyOngoing(Builder $query): Builder
    {
        $now = now();

        return self::applyEventFilter(
            $query
                ->whereNull('completed_at')
                ->where('approval_status', 'approved')
                ->whereNotNull('event_start_at')
                ->where('event_start_at', '<=', $now)
        );
    }

    private static function applyApproved(Builder $query): Builder
    {
        $now = now();

        return $query
            ->whereNull('completed_at')
            ->where('approval_status', 'approved')
            ->where(function (Builder $query) use ($now) {
                $query->whereNull('event_start_at')
                    ->orWhere('event_start_at', '>', $now)
                    ->orWhere(function (Builder $query) {
                        $query->where('category', '!=', 'Event')
                            ->where('requires_attendance', false);
                    });
            });
    }
}
