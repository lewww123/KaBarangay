<?php

namespace App\Support;

use App\Models\Announcement;

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
}
