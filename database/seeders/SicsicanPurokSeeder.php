<?php
namespace Database\Seeders;

use App\Models\Barangay;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SicsicanPurokSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $barangay = Barangay::query()->where('code', 'BSC')->lockForUpdate()->firstOrFail();
            (new BarangaySeeder)->syncSicsican($barangay);
        });
    }
}
