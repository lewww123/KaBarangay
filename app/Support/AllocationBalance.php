<?php

namespace App\Support;

use App\Models\Allocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AllocationBalance
{
    public const VOIDED_STATUS = 'Voided';

    public const RELEASED_STATUS = 'Released';

    public static function totals(Allocation $allocation, ?string $excludeDisbursementId = null): array
    {
        $approved = round((float) $allocation->amount_approved, 2);
        $amendments = round((float) $allocation->budgetAmendments()->sum('amount'), 2);

        $usedQuery = $allocation->disbursements()
            ->where('status', self::RELEASED_STATUS);

        if ($excludeDisbursementId !== null) {
            $usedQuery->where('id', '!=', $excludeDisbursementId);
        }

        $used = round((float) $usedQuery->sum('amount'), 2);

        return [
            'approved' => $approved,
            'amendments' => $amendments,
            'available' => round($approved + $amendments, 2),
            'used' => $used,
            'remaining' => round($approved + $amendments - $used, 2),
        ];
    }

    public static function withLock(
        Allocation $allocation,
        ?string $excludeDisbursementId,
        callable $callback,
    ): mixed {
        return DB::transaction(function () use ($allocation, $excludeDisbursementId, $callback) {
            self::acquireAllocationLock((string) $allocation->id);

            $locked = Allocation::query()
                ->whereKey($allocation->id)
                ->lockForUpdate()
                ->firstOrFail();

            return $callback($locked, self::totals($locked, $excludeDisbursementId));
        });
    }

    private static function acquireAllocationLock(string $allocationId): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [$allocationId]);
    }

    public static function assertWithinBalance(array $totals, float $amount): void
    {
        if ($amount > $totals['remaining'] + 0.005) {
            throw ValidationException::withMessages([
                'amount' => 'The amount exceeds the remaining allocation balance.',
            ]);
        }
    }

    public static function addTotalsToQuery(Builder $query): Builder
    {
        return $query->withSum('budgetAmendments as amendments_total_sum', 'amount')->withSum([
            'disbursements as used_total_sum' => fn ($disbursements) => $disbursements->where('status', self::RELEASED_STATUS),
        ], 'amount');
    }
}
