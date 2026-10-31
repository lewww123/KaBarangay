<?php

namespace App\Support;

use App\Models\Announcement;
use Illuminate\Database\Eloquent\Builder;

final class AnnouncementLifecycle
{
    public const TAB_ALL = 'all';

    public const TAB_DRAFT = 'draft';

    public const TAB_ONGOING = 'ongoing';

    public const TAB_DONE = 'done';

    public const TABS = [self::TAB_ALL, self::TAB_DRAFT, self::TAB_ONGOING, self::TAB_DONE];

    private const STATUS_SQL = "COALESCE(program_status, CASE WHEN completed_at IS NOT NULL THEN 'done' WHEN approval_status = 'approved' AND is_published = true THEN 'ongoing' ELSE 'draft' END)";

    public static function normalizeTab(?string $tab): string
    {
        return in_array($tab, self::TABS, true) ? $tab : self::TAB_ALL;
    }

    public static function applyTab(Builder $query, string $tab): Builder
    {
        return in_array($tab, [self::TAB_DRAFT, self::TAB_ONGOING, self::TAB_DONE], true)
            ? $query->whereRaw(self::STATUS_SQL.' = ?', [$tab])
            : $query;
    }

    public static function counts(Builder $query): array
    {
        $aggregate = (clone $query)->reorder()->selectRaw('COUNT(*) AS total');
        foreach ([self::TAB_DRAFT, self::TAB_ONGOING, self::TAB_DONE] as $status) {
            $aggregate->selectRaw('COALESCE(SUM(CASE WHEN '.self::STATUS_SQL.' = ? THEN 1 ELSE 0 END), 0) AS '.$status, [$status]);
        }
        $row = $aggregate->first();

        return ['all' => (int) $row->total, 'draft' => (int) $row->draft, 'ongoing' => (int) $row->ongoing, 'done' => (int) $row->done];
    }

    public static function isEvent(Announcement $announcement): bool
    {
        return AnnouncementType::isEvent($announcement);
    }

    public static function applyEventFilter(Builder $query): Builder
    {
        return AnnouncementType::applyProgramFilter($query);
    }
}
