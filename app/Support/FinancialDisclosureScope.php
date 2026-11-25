<?php

namespace App\Support;

use App\Models\Allocation;
use App\Models\BudgetAmendment;
use App\Models\Collection;
use App\Models\Disbursement;
use App\Models\DisclosurePeriod;
use App\Models\FinancialAttachment;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;

final class FinancialDisclosureScope
{
    public static function barangayId(?Authenticatable $user): int
    {
        abort_unless($user instanceof User && $user->barangay_id !== null, 403, 'Unauthorized barangay access.');

        return (int) $user->barangay_id;
    }

    public static function actorId(?Authenticatable $user): int
    {
        abort_unless($user instanceof User, 403, 'Unauthorized financial disclosure access.');

        return (int) $user->id;
    }

    public static function allocations(int $barangayId): Builder
    {
        return Allocation::query()
            ->whereHas('createdBy', fn (Builder $query) => $query->where('barangay_id', $barangayId));
    }

    public static function allocation(int $barangayId, string $id): Allocation
    {
        $allocation = self::allocations($barangayId)->whereKey($id)->first();

        abort_unless($allocation !== null, 404);

        return $allocation;
    }

    public static function amendments(int $barangayId): Builder
    {
        return BudgetAmendment::query()
            ->whereHas(
                'allocation.createdBy',
                fn (Builder $query) => $query->where('barangay_id', $barangayId),
            );
    }

    public static function amendment(int $barangayId, string $id): BudgetAmendment
    {
        $amendment = self::amendments($barangayId)->whereKey($id)->first();

        abort_unless($amendment !== null, 404);

        return $amendment;
    }

    public static function disbursements(int $barangayId): Builder
    {
        return Disbursement::query()
            ->whereHas(
                'allocation.createdBy',
                fn (Builder $query) => $query->where('barangay_id', $barangayId),
            );
    }

    public static function disbursement(int $barangayId, string $id): Disbursement
    {
        $disbursement = self::disbursements($barangayId)->whereKey($id)->first();

        abort_unless($disbursement !== null, 404);

        return $disbursement;
    }

    public static function collections(int $barangayId): Builder
    {
        return Collection::query()
            ->whereHas(
                'encodedBy',
                fn (Builder $encoder) => $encoder->where('barangay_id', $barangayId),
            )
            ->where(function (Builder $query) use ($barangayId) {
                $query->whereNull('certificate_request_id')
                    ->orWhereHas(
                        'certificateRequest',
                        fn (Builder $certificate) => $certificate->where('barangay_id', $barangayId),
                    );
            });
    }

    public static function collection(int $barangayId, string $id): Collection
    {
        $collection = self::collections($barangayId)->whereKey($id)->first();

        abort_unless($collection !== null, 404);

        return $collection;
    }

    public static function disclosurePeriods(int $barangayId): Builder
    {
        return DisclosurePeriod::query()
            ->where('barangay_id', $barangayId);
    }

    public static function publishedDisclosurePeriod(int $barangayId, int $year, int $month): DisclosurePeriod
    {
        $period = self::disclosurePeriods($barangayId)
            ->where('year', $year)
            ->where('month', $month)
            ->where('is_published', true)
            ->first();

        abort_unless($period !== null, 404, 'Disclosure period is not published.');

        return $period;
    }

    public static function attachments(int $barangayId): Builder
    {
        return FinancialAttachment::query()
            ->whereHas('uploadedBy', fn (Builder $query) => $query->where('barangay_id', $barangayId));
    }

    public static function userBelongsToBarangay(int $barangayId, int $userId): bool
    {
        return User::query()
            ->whereKey($userId)
            ->where('barangay_id', $barangayId)
            ->exists();
    }
}
