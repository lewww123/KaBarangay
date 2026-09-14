<?php

namespace Database\Seeders;

use App\Models\Barangay;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

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

        User::updateOrCreate(
            ['email' => 'admin.sanjose@kabarangay.local'],
            [
                'name' => 'San Jose Administrator',
                'password' => Hash::make('Admin@12345'),
                'barangay_id' => $sanJose->id,
                'staff_id' => 'BSJ-ADM-001',
                'department' => 'Administration',
                'role' => 'admin',
                'status' => 'active',
            ]
        );

        User::updateOrCreate(
            ['email' => 'admin.stamonica@kabarangay.local'],
            [
                'name' => 'Sta. Monica Administrator',
                'password' => Hash::make('Admin@12345'),
                'barangay_id' => $staMonica->id,
                'staff_id' => 'BSM-ADM-001',
                'department' => 'Administration',
                'role' => 'admin',
                'status' => 'active',
            ]
        );
    }
}