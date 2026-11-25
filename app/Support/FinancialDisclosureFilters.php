<?php

namespace App\Support;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;

final class FinancialDisclosureFilters
{
    public static function string(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    public static function allowed(mixed $value, array $allowed): ?string
    {
        $value = self::string($value);

        return in_array($value, $allowed, true) ? $value : null;
    }

    public static function tokens(string $search): array
    {
        return collect(preg_split('/\s+/', $search) ?: [])
            ->map(fn (string $token) => mb_substr(str_replace(['%', '_'], '', $token), 0, 100))
            ->filter(fn (string $token) => $token !== '')
            ->values()
            ->all();
    }

    public static function applySearch(Builder $query, string $search, array $columns): void
    {
        $tokens = self::tokens($search);

        if ($search !== '' && $tokens === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        foreach ($tokens as $token) {
            $term = '%'.$token.'%';

            $query->where(function (Builder $group) use ($columns, $term) {
                foreach ($columns as $column) {
                    $group->orWhere($column, 'like', $term);
                }
            });
        }
    }

    public static function dateRange(Builder $query, string $column, mixed $from, mixed $to): void
    {
        $from = self::parseDate($from);
        $to = self::parseDate($to);

        if ($from !== null) {
            $query->where($column, '>=', $from);
        }

        if ($to !== null) {
            $query->where($column, '<=', $to);
        }
    }

    public static function parseDate(mixed $value): ?string
    {
        $value = self::string($value);

        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    public static function integer(mixed $value): ?int
    {
        $value = self::string($value);

        return preg_match('/^\d{4}$/', $value) === 1 ? (int) $value : null;
    }

    public static function sort(Builder $query, mixed $sort, array $allowed, string $default): string
    {
        $sort = self::string($sort);

        if (! array_key_exists($sort, $allowed)) {
            $sort = $default;
        }

        [$column, $direction] = $allowed[$sort];
        $query->orderBy($column, $direction);

        return $sort;
    }
}
