<?php

namespace Database\Seeders;

use App\Models\Sector;
use Illuminate\Database\Seeder;

class SectorSeeder extends Seeder
{
    public function run(): void
    {
        Sector::updateOrCreate(
            ['slug' => 'regular'],
            [
                'name' => 'Regular Resident',
                'requires_proof' => false,
                'is_active' => true,
            ]
        );

        Sector::updateOrCreate(
            ['slug' => 'senior'],
            [
                'name' => 'Senior Citizen',
                'requires_proof' => true,
                'is_active' => true,
            ]
        );

        Sector::updateOrCreate(
            ['slug' => 'pwd'],
            [
                'name' => 'Person with Disability (PWD)',
                'requires_proof' => true,
                'is_active' => true,
            ]
        );
    }
}
