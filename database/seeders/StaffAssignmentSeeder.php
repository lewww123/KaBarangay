<?php

namespace Database\Seeders;

use App\Models\Barangay;
use App\Models\Purok;
use App\Models\Resident;
use App\Models\ResidentAccount;
use App\Models\ResidentRegistration;
use App\Models\StaffAssignment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class StaffAssignmentSeeder extends Seeder
{
    public function run(): void
    {
        $barangay = Barangay::query()->firstOrCreate(
            ['code' => 'STAFF-TEST'],
            ['name' => 'Staff Assignment Test Barangay', 'city_municipality' => 'Puerto Princesa City', 'province' => 'Palawan', 'is_active' => true],
        );
        $purok = Purok::query()->firstOrCreate(
            ['barangay_id' => $barangay->id, 'name' => 'Purok Staff'],
            ['code' => 'STAFF-P01', 'is_active' => true],
        );

        $residents = Resident::query()->where('barangay_id', $barangay->id)->where('status', 'active')->orderBy('id')->limit(3)->get();
        for ($index = $residents->count() + 1; $index <= 5; $index++) {
            $registration = ResidentRegistration::query()->firstOrCreate(
                ['email' => "staff-fixture-{$index}@example.invalid"],
                [
                    'public_id' => (string) Str::uuid(),
                    'barangay_id' => $barangay->id, 'purok_id' => $purok->id,
                    'first_name' => 'Staff', 'last_name' => "Fixture {$index}", 'birthdate' => '1990-01-01',
                    'sex' => 'Male', 'civil_status' => 'Single', 'address_line' => 'Purok Staff',
                    'mobile' => '09170000'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                    'email' => "staff-fixture-{$index}@example.invalid", 'password' => Hash::make('password'),
                    'status' => 'approved', 'submitted_at' => now(),
                ],
            );
            $residents->push(Resident::query()->firstOrCreate(
                ['resident_registration_id' => $registration->id],
                [
                    'public_id' => (string) Str::uuid(), 'barangay_id' => $barangay->id, 'purok_id' => $purok->id,
                    'resident_number' => "STAFF-RES-{$index}", 'source' => 'staff_assignment_seeder',
                    'first_name' => 'Staff', 'last_name' => "Fixture {$index}", 'birthdate' => '1990-01-01',
                    'sex' => 'Male', 'civil_status' => 'Single', 'address_line' => 'Purok Staff',
                    'mobile' => '09170000'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                    'email' => "staff-fixture-{$index}@example.invalid", 'status' => 'active',
                ],
            ));
        }

        foreach ($residents->values()->take(5)->zip([['lupon', 'Lupon Secretary'], ['clerk', 'Barangay Clerk'], ['admin', 'Barangay Admin'], ['pwd', 'PWD Affairs Officer'], ['senior', 'OSCA Head']]) as [$resident, $assignment]) {
            ResidentAccount::query()->updateOrCreate(['resident_id' => $resident->id], ['password' => Hash::make('password'), 'is_active' => true]);
            $user = User::query()->updateOrCreate(['resident_id' => $resident->id], [
                'barangay_id' => $barangay->id, 'name' => $resident->full_name,
                'email' => "staff-assignment-{$resident->id}@example.invalid", 'password' => Hash::make('password'),
                'status' => 'active',
            ]);
            StaffAssignment::query()->updateOrCreate([
                'user_id' => $user->id, 'resident_id' => $resident->id, 'barangay_id' => $barangay->id,
                'department' => $assignment[0], 'position' => $assignment[1],
            ], ['is_active' => true]);
        }
    }
}
