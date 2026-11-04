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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DummyBarangayStaffSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (['BSC' => ['Sicsican', 'sicsican'], 'BSM' => ['Sta. Monica', 'stamonica']] as $code => [$name, $slug]) {
                $barangay = Barangay::query()->where('code', $code)->where('is_active', true)->firstOrFail();
                $purok = Purok::query()->where('barangay_id', $barangay->id)->where('is_active', true)->orderBy('id')->first()
                    ?? Purok::query()->firstOrCreate(
                        ['barangay_id' => $barangay->id, 'code' => $code.'-DUMMY'],
                        ['name' => 'Dummy Staff', 'is_active' => true],
                    );
                foreach ([
                    'clerk' => ['Staff', 'Barangay Clerk'],
                    'admin' => ['Admin', 'Barangay Secretary'],
                    'lupon' => ['Lupon', 'Lupon Secretary'],
                    'pwd' => ['PWD', 'PWD Affairs Officer'],
                    'senior' => ['Senior', 'OSCA Head'],
                ] as $department => [$label, $position]) {
                    $email = $department === 'clerk'
                        ? "dummy.staff.{$slug}@kabarangay.local"
                        : "dummy.{$department}.{$slug}@kabarangay.local";
                    $password = Hash::make('Staff@12345');
                    $profile = [
                        'barangay_id' => $barangay->id, 'purok_id' => $purok->id,
                        'first_name' => $name, 'last_name' => 'Dummy '.$label, 'birthdate' => '1995-01-01',
                        'sex' => 'Male', 'civil_status' => 'Single', 'address_line' => 'Barangay Hall (Dummy Account)',
                        'mobile' => '0918'.str_pad((string) $barangay->id, 6, '0', STR_PAD_LEFT).array_search($department, ['clerk', 'admin', 'lupon', 'pwd', 'senior'], true),
                    ];
                    $registration = ResidentRegistration::query()->firstOrCreate(['email' => $email], array_merge($profile, [
                        'public_id' => (string) Str::uuid(), 'password' => $password,
                        'status' => 'approved', 'submitted_at' => now(),
                    ]));
                    $resident = Resident::query()->firstOrCreate(['resident_registration_id' => $registration->id], array_merge($profile, [
                        'public_id' => (string) Str::uuid(), 'resident_number' => $code.'-DUMMY-'.strtoupper($label).'-001',
                        'source' => 'dummy_barangay_staff_seeder', 'email' => $email, 'status' => 'active', 'approved_at' => now(),
                    ]));
                    ResidentAccount::query()->firstOrCreate(['resident_id' => $resident->id], ['password' => $password, 'is_active' => true]);
                    $user = User::query()->firstOrCreate(['email' => $email], [
                        'name' => $resident->full_name, 'password' => $password,
                        'barangay_id' => $barangay->id, 'resident_id' => $resident->id, 'status' => 'active',
                    ]);
                    StaffAssignment::query()->firstOrCreate([
                        'user_id' => $user->id, 'resident_id' => $resident->id, 'barangay_id' => $barangay->id,
                        'department' => $department, 'position' => $position,
                    ], ['is_active' => true]);
                }
            }
        });
    }
}
