<?php

namespace App\Support;

use App\Models\Barangay;
use App\Models\Purok;
use App\Models\Resident;
use App\Models\ResidentNumberSequence;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ResidentNumberGenerator
{
    /**
     * Allocate the next permanent Resident number for a barangay.
     *
     * The existing sequence is shared across the barangay; the selected
     * Purok is encoded in the middle segment of the official identifier.
     *
     * The caller normally holds the barangay row lock, but this method also
     * locks it so the sequence cannot race with another allocation.
     */
    public function next(Barangay $barangay, int $purokId): string
    {
        return DB::transaction(function () use ($barangay, $purokId) {
            Barangay::query()
                ->whereKey($barangay->id)
                ->lockForUpdate()
                ->firstOrFail();

            $purok = Purok::query()
                ->whereKey($purokId)
                ->where('barangay_id', $barangay->id)
                ->lockForUpdate()
                ->firstOrFail();

            $sequence = ResidentNumberSequence::query()
                ->where('barangay_id', $barangay->id)
                ->lockForUpdate()
                ->first();

            $code = $this->normalizedCode($barangay->code);
            $nextNumber = max(
                1,
                $this->highestExistingNumber($barangay->id, $code) + 1,
                (int) ($sequence?->next_number ?? 1),
            );

            $purokNumber = $this->purokNumber($purok);

            for ($attempt = 0; $attempt < 100; $attempt++) {
                $residentNumber = sprintf(
                    '%s-%02d-%04d',
                    $code,
                    $purokNumber,
                    $nextNumber
                );

                if (! Resident::query()
                    ->where('resident_number', $residentNumber)
                    ->exists()) {
                    $sequence ??= new ResidentNumberSequence([
                        'barangay_id' => $barangay->id,
                        'next_number' => $nextNumber,
                    ]);

                    $sequence->next_number = $nextNumber + 1;
                    $sequence->save();

                    return $residentNumber;
                }

                $nextNumber++;
            }

            throw new RuntimeException(
                'Unable to allocate a unique resident number.'
            );
        });
    }

    private function highestExistingNumber(int $barangayId, string $barangayCode): int
    {
        $pattern = '/^'.preg_quote($barangayCode, '/').'-\\d{2,}-(\\d{4,})$/';

        return Resident::query()
            ->where('barangay_id', $barangayId)
            ->pluck('resident_number')
            ->reduce(function (int $highest, string $number) use ($pattern): int {
                if (preg_match($pattern, strtoupper($number), $matches) !== 1) {
                    return $highest;
                }

                return max($highest, (int) $matches[1]);
            }, 0);
    }

    private function normalizedCode(string $code): string
    {
        $normalized = strtoupper(
            (string) preg_replace('/[^A-Za-z0-9]/', '', $code)
        );

        if ($normalized === '') {
            throw new RuntimeException(
                'Unable to allocate a Resident number without a barangay code.'
            );
        }

        return $normalized;
    }

    private function purokNumber(Purok $purok): int
    {
        $code = trim((string) $purok->code);

        if (preg_match('/(?:^|[-_])P0*(\d+)$/i', $code, $matches) === 1) {
            $number = (int) $matches[1];
            if ($number > 0) {
                return $number;
            }
        }

        if (preg_match('/(\d+)\s*$/', $purok->name, $matches) === 1) {
            $number = (int) $matches[1];
            if ($number > 0) {
                return $number;
            }
        }

        if ((int) $purok->sort_order > 0) {
            return (int) $purok->sort_order;
        }

        throw new RuntimeException(
            'Unable to determine the official Purok number for this Resident.'
        );
    }
}
