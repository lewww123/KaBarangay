<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class PurokSeeder extends Seeder
{
    public function run(): void
    {
        // Keep both entry points on the same canonical barangay/purok catalog.
        $this->call(BarangaySeeder::class);
    }
}
