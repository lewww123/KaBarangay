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

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Barangays
        |--------------------------------------------------------------------------
        */

        $sanJose = Barangay::updateOrCreate(
            ['code' => 'BSJ'],
            [
                'name' => 'Barangay San Jose',
                'is_active' => true,
            ]
        );

        $staMonica = Barangay::updateOrCreate(
            ['code' => 'BSM'],
            [
                'name' => 'Barangay Sta. Monica',
                'is_active' => true,
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Initial Admin Accounts
        |--------------------------------------------------------------------------
        |
        | These are the initial administrator accounts for testing.
        | Regular staff accounts will NOT be seeded here because
        | staff accounts must be created by an administrator.
        |
        */

        $this->seedAdministrator($sanJose, 'San Jose Administrator', 'admin.sanjose@kabarangay.local');
        $this->seedAdministrator($staMonica, 'Sta. Monica Administrator', 'admin.stamonica@kabarangay.local');

        $this->call(CertificateSeeder::class);
    }

    private function seedAdministrator(Barangay $barangay, string $name, string $email): void
    {
        $purok = Purok::query()->firstOrCreate(
            ['barangay_id' => $barangay->id, 'code' => $barangay->code.'-ADM'],
            ['name' => 'Administration', 'is_active' => true],
        );
        $registration = ResidentRegistration::query()->firstOrCreate(['email' => $email], [
            'public_id' => (string) Str::uuid(), 'barangay_id' => $barangay->id, 'purok_id' => $purok->id,
            'first_name' => $name, 'last_name' => 'Staff', 'birthdate' => '1990-01-01',
            'sex' => 'Male', 'civil_status' => 'Single', 'address_line' => 'Barangay Hall',
            'mobile' => '0917'.str_pad((string) $barangay->id, 7, '0', STR_PAD_LEFT),
            'password' => Hash::make('Admin@12345'), 'status' => 'approved', 'submitted_at' => now(),
        ]);
        $resident = Resident::query()->firstOrCreate(['resident_registration_id' => $registration->id], [
            'public_id' => (string) Str::uuid(), 'barangay_id' => $barangay->id, 'purok_id' => $purok->id,
            'resident_number' => $barangay->code.'-ADM-001', 'source' => 'database_seeder',
            'first_name' => $name, 'last_name' => 'Staff', 'birthdate' => '1990-01-01',
            'sex' => 'Male', 'civil_status' => 'Single', 'address_line' => 'Barangay Hall',
            'mobile' => '0917'.str_pad((string) $barangay->id, 7, '0', STR_PAD_LEFT),
            'email' => $email, 'status' => 'active', 'approved_at' => now(),
        ]);
        ResidentAccount::query()->updateOrCreate(['resident_id' => $resident->id], ['password' => Hash::make('Admin@12345'), 'is_active' => true]);
        $user = User::query()->updateOrCreate(['email' => $email], [
            'name' => $name, 'password' => Hash::make('Admin@12345'), 'barangay_id' => $barangay->id,
            'resident_id' => $resident->id, 'status' => 'active',
        ]);
        StaffAssignment::query()->updateOrCreate([
            'user_id' => $user->id, 'resident_id' => $resident->id, 'barangay_id' => $barangay->id,
            'department' => 'admin', 'position' => 'Barangay Administrator',
        ], ['is_active' => true]);
    }
}
