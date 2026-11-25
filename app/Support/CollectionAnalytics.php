<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final class CollectionAnalytics
{
    public const TIMEZONE = 'Asia/Manila';

    public const MAX_RANGE_DAYS = 366;

    public const PERIODS = ['daily', 'weekly', 'monthly', 'yearly', 'range'];

    public function parameters(array $input): array
    {
        $period = FinancialDisclosureFilters::allowed($input['period'] ?? null, self::PERIODS);
        $from = FinancialDisclosureFilters::parseDate($input['date_from'] ?? null);
        $to = FinancialDisclosureFilters::parseDate($input['date_to'] ?? null);
        $date = FinancialDisclosureFilters::parseDate($input['date'] ?? null);

        if ($period === null && ($from !== null || $to !== null)) {
            $period = 'range';
        }

        $period ??= 'monthly';

        if ($period === 'range') {
            if ($from === null) {
                throw ValidationException::withMessages([
                    'date_from' => 'A valid start date is required for a custom range.',
                ]);
            }

            if ($to === null) {
                throw ValidationException::withMessages([
                    'date_to' => 'A valid end date is required for a custom range.',
                ]);
            }

            $start = CarbonImmutable::createFromFormat('!Y-m-d', $from, self::TIMEZONE);
            $end = CarbonImmutable::createFromFormat('!Y-m-d', $to, self::TIMEZONE);

            if ($end->lessThan($start)) {
                throw ValidationException::withMessages([
                    'date_to' => 'The end date must be on or after the start date.',
                ]);
            }

            if ($start->diffInDays($end) + 1 > self::MAX_RANGE_DAYS) {
                throw ValidationException::withMessages([
                    'date_from' => 'A custom range cannot exceed '.self::MAX_RANGE_DAYS.' days.',
                ]);
            }
        } else {
            $date ??= CarbonImmutable::now(self::TIMEZONE)->toDateString();
            $selected = CarbonImmutable::createFromFormat('!Y-m-d', $date, self::TIMEZONE);

            $start = match ($period) {
                'daily' => $selected,
                'weekly' => $selected->startOfWeek(CarbonImmutable::MONDAY),
                'yearly' => $selected->startOfYear(),
                default => $selected->startOfMonth(),
            };

            $end = match ($period) {
                'daily' => $selected,
                'weekly' => $selected->endOfWeek(CarbonImmutable::SUNDAY),
                'yearly' => $selected->endOfYear(),
                default => $selected->endOfMonth(),
            };

            $from = $start->toDateString();
            $to = $end->toDateString();
        }

        return [
            'period' => $period,
            'date' => $period === 'range' ? '' : $date,
            'date_from' => $from,
            'date_to' => $to,
            'granularity' => $period === 'yearly' ? 'month' : 'day',
            'timezone' => self::TIMEZONE,
            'boundary' => 'Dates are applied to collections.collection_date in Asia/Manila.',
        ];
    }

    public function applyPeriod(Builder $query, array $period): void
    {
        $exclusiveEnd = CarbonImmutable::createFromFormat('!Y-m-d', $period['date_to'], self::TIMEZONE)
            ->addDay()
            ->toDateString();

        $query->where('collections.collection_date', '>=', $period['date_from'])
            ->where('collections.collection_date', '<', $exclusiveEnd);
    }

    public function summarize(Builder $query, array $period): array
    {
        $paid = $this->aggregate($this->eligible($query));
        $recorded = $this->aggregate(
            (clone $query)->where('collections.status', 'Verified')->whereNull('collections.void_reason'),
        );
        $waivedCount = (int) (clone $query)
            ->withoutEagerLoads()
            ->where('collections.status', 'Verified')
            ->whereNull('collections.void_reason')
            ->where('collections.is_waived', true)
            ->toBase()
            ->count();
        $pendingCount = (int) (clone $query)
            ->where('collections.status', 'Pending')
            ->toBase()
            ->count();
        $voidedCount = (int) (clone $query)
            ->where('collections.status', 'Voided')
            ->toBase()
            ->count();

        $byCertificateType = $this->eligible($query)
            ->select([])
            ->selectRaw('COALESCE(certificates.name, collections.collection_type) as label')
            ->selectRaw('COALESCE(SUM(collections.amount), 0) as amount')
            ->selectRaw('COUNT(*) as transaction_count')
            ->groupByRaw('COALESCE(certificates.name, collections.collection_type)')
            ->orderByDesc('amount')
            ->orderBy('label')
            ->toBase()
            ->get()
            ->map(fn ($row) => [
                'label' => (string) $row->label,
                'amount' => round((float) $row->amount, 2),
                'count' => (int) $row->transaction_count,
            ])
            ->all();

        $trendRows = $this->eligible($query)
            ->select([])
            ->selectRaw('collections.collection_date as bucket')
            ->selectRaw('COALESCE(SUM(collections.amount), 0) as amount')
            ->selectRaw('COUNT(*) as transaction_count')
            ->groupBy('collections.collection_date')
            ->orderBy('collections.collection_date')
            ->toBase()
            ->get();

        $amounts = [];
        $counts = [];

        foreach ($trendRows as $row) {
            $key = $this->bucketKey((string) $row->bucket, $period['granularity']);
            $amounts[$key] = ($amounts[$key] ?? 0) + (float) $row->amount;
            $counts[$key] = ($counts[$key] ?? 0) + (int) $row->transaction_count;
        }

        $trend = [];

        foreach ($this->bucketKeys($period) as $key) {
            $trend[] = [
                'key' => $key,
                'label' => $this->bucketLabel($key, $period['granularity']),
                'amount' => round($amounts[$key] ?? 0, 2),
                'count' => $counts[$key] ?? 0,
            ];
        }

        return [
            'period' => $period['period'],
            'date' => $period['date'],
            'date_from' => $period['date_from'],
            'date_to' => $period['date_to'],
            'granularity' => $period['granularity'],
            'timezone' => $period['timezone'],
            'boundary' => $period['boundary'],
            'totals' => [
                'amount' => round((float) $paid->amount, 2),
                'paid_count' => (int) $paid->transaction_count,
                'recorded_count' => (int) $recorded->transaction_count,
                'waived_count' => $waivedCount,
                'pending_count' => $pendingCount,
                'voided_count' => $voidedCount,
            ],
            'by_certificate_type' => $byCertificateType,
            'trend' => $trend,
        ];
    }

    private function eligible(Builder $query): Builder
    {
        return (clone $query)
            ->withoutEagerLoads()
            ->where('collections.status', 'Verified')
            ->whereNull('collections.void_reason')
            ->where('collections.is_waived', false);
    }

    private function aggregate(Builder $query): object
    {
        return $query
            ->withoutEagerLoads()
            ->select([])
            ->selectRaw('COALESCE(SUM(collections.amount), 0) as amount')
            ->selectRaw('COUNT(*) as transaction_count')
            ->toBase()
            ->first();
    }

    private function bucketKey(string $date, string $granularity): string
    {
        return $granularity === 'month' ? substr($date, 0, 7) : substr($date, 0, 10);
    }

    private function bucketKeys(array $period): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $period['date_from'], self::TIMEZONE);
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $period['date_to'], self::TIMEZONE);
        $keys = [];

        if ($period['granularity'] === 'month') {
            $cursor = $start->startOfMonth();

            while ($cursor->lessThanOrEqualTo($end)) {
                $keys[] = $cursor->format('Y-m');
                $cursor = $cursor->addMonth();
            }

            return $keys;
        }

        $cursor = $start;

        while ($cursor->lessThanOrEqualTo($end)) {
            $keys[] = $cursor->toDateString();
            $cursor = $cursor->addDay();
        }

        return $keys;
    }

    private function bucketLabel(string $key, string $granularity): string
    {
        if ($granularity === 'month') {
            return CarbonImmutable::createFromFormat('!Y-m', $key, self::TIMEZONE)
                ->format('M Y');
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $key, self::TIMEZONE)
            ->format('M d');
    }
}
