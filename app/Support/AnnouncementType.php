<?php

namespace App\Support;

use App\Models\Announcement;
use Illuminate\Database\Eloquent\Builder;

final class AnnouncementType
{
    public const EVENT = 'event';

    public const INFORMATIONAL = 'informational';

    public static function for(Announcement $announcement): string
    {
        return self::isEvent($announcement) ? self::EVENT : self::INFORMATIONAL;
    }

    public static function isEvent(Announcement $announcement): bool
    {
        return $announcement->category === 'Event' || $announcement->requires_attendance;
    }

    public static function isInformational(Announcement $announcement): bool
    {
        return ! self::isEvent($announcement);
    }

    public static function matchesKind(Announcement $announcement, string $kind): bool
    {
        return match ($kind) {
            'update' => self::isInformational($announcement),
            'program' => self::isEvent($announcement),
            default => true,
        };
    }

    public static function applyUpdateFilter(Builder $query): Builder
    {
        return $query
            ->where('category', '!=', 'Event')
            ->where('requires_attendance', false);
    }

    public static function applyProgramFilter(Builder $query): Builder
    {
        return $query->where(function (Builder $query) {
            $query->where('category', 'Event')
                ->orWhere('requires_attendance', true);
        });
    }
}
