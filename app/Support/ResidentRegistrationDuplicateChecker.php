<?php

namespace App\Support;

use App\Models\Resident;
use App\Models\ResidentRegistration;
use Illuminate\Database\Eloquent\Builder;

final class ResidentRegistrationDuplicateChecker
{
    public function find(array $input): ?string
    {
        $identity = function (Builder $query) use ($input): void {
            $email = strtolower(trim((string) ($input['email'] ?? '')));
            if ($email !== '') {
                $query->whereRaw('LOWER(TRIM(email)) = ?', [$email]);
            }
            $query->orWhere('mobile', $input['mobile'])
                ->orWhere(function (Builder $person) use ($input): void {
                    $person->whereRaw('LOWER(TRIM(first_name)) = ?', [strtolower(trim($input['first_name']))])
                        ->whereRaw('LOWER(TRIM(last_name)) = ?', [strtolower(trim($input['last_name']))])
                        ->whereRaw("LOWER(TRIM(COALESCE(middle_name, ''))) = ?", [strtolower(trim($input['middle_name'] ?? ''))])
                        ->whereRaw("LOWER(TRIM(COALESCE(suffix, ''))) = ?", [strtolower(trim($input['suffix'] ?? ''))])
                        ->whereDate('birthdate', $input['birthdate']);
                });
        };

        $existing = ResidentRegistration::query()->with('barangay')
            ->whereIn('status', ['pending', 'needs_correction', 'approved'])
            ->where($identity)->first();

        $existing ??= Resident::query()->with('barangay')->whereHas('account')
            ->where($identity)->first();

        if ($existing === null) {
            return null;
        }

        $barangay = $existing->barangay?->name ?? 'another barangay';

        return "You are already registered in {$barangay}. Multiple resident accounts across barangays are not allowed. If your application is pending, please wait for admin approval.";
    }
}
