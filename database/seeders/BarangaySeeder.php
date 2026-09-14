<?php

namespace Database\Seeders;

use App\Models\Barangay;
use Illuminate\Database\Seeder;

class BarangaySeeder extends Seeder
{
    public function run(): void
    {
        Barangay::updateOrCreate(
            ['code' => 'BSJ'],
            [
                'name' => 'Barangay San Jose',
                'city_municipality' => 'Puerto Princesa City',
                'province' => 'Palawan',
                'is_active' => true,
            ]
        );

        Barangay::updateOrCreate(
            ['code' => 'BSM'],
            [
                'name' => 'Barangay Sta. Monica',
                'city_municipality' => 'Puerto Princesa City',
                'province' => 'Palawan',
                'is_active' => true,
            ]
        );
    }
}