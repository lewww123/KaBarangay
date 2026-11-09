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

class LocalStaffSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Barangay::query()->whereIn('code', ['BSJ', 'BSM'])->get() as $barangay) {
            $purok = Purok::query()->where('barangay_id', $barangay->id)->where('is_active', true)->firstOrFail();
            $labelName = str_replace('Barangay ', '', $barangay->name);

            foreach ([
                'clerk' => ['Clerk', 'Barangay Clerk'],
                'lupon' => ['Lupon', 'Lupon Secretary'],
                'pwd' => ['PWD', 'PWD Affairs Officer'],
                'senior' => ['Senior', 'OSCA Head'],
            ] as $department => [$label, $position]) {
                $email = "dummy.{$department}.{$barangay->code}@kabarangay.local";
                $password = Hash::make('Staff@12345');
                $profile = [
                    'barangay_id' => $barangay->id,
                    'purok_id' => $purok->id,
                    'first_name' => $labelName,
                    'last_name' => 'Dummy '.$label,
                    'birthdate' => '1995-01-01',
                    'sex' => 'Male',
                    'civil_status' => 'Single',
                    'address_line' => 'Barangay Hall (Dummy Account)',
                    'mobile' => '0918'.str_pad((string) $barangay->id, 6, '0', STR_PAD_LEFT).$department,
                ];

                $registration = ResidentRegistration::query()->firstOrCreate(['email' => $email], array_merge($profile, [
                    'public_id' => (string) Str::uuid(),
                    'password' => $password,
                    'status' => 'approved',
                    'submitted_at' => now(),
                ]));

                $resident = Resident::query()->firstOrCreate(['resident_registration_id' => $registration->id], array_merge($profile, [
                    'public_id' => (string) Str::uuid(),
                    'resident_number' => $barangay->code.'-DUMMY-'.strtoupper($label).'-001',
                    'source' => 'local_staff_seeder',
                    'email' => $email,
                    'status' => 'active',
                    'approved_at' => now(),
                ]));

                ResidentAccount::query()->firstOrCreate(['resident_id' => $resident->id], [
                    'password' => $password,
                    'is_active' => true,
                ]);

                $user = User::query()->firstOrCreate(['email' => $email], [
                    'name' => $resident->full_name,
                    'password' => $password,
                    'barangay_id' => $barangay->id,
                    'resident_id' => $resident->id,
                    'status' => 'active',
                ]);

                StaffAssignment::query()->firstOrCreate([
                    'user_id' => $user->id,
                    'resident_id' => $resident->id,
                    'barangay_id' => $barangay->id,
                    'department' => $department,
                    'position' => $position,
                ], ['is_active' => true]);
            }
        }
    }
}
