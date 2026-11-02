<?php

namespace Database\Seeders;

use App\Models\Barangay;
use Illuminate\Database\Seeder;

/**
 * Certificate branding sourced from the approved Barangay San Jose template.
 */
class BarangayBrandingSeeder extends Seeder
{
    public function run(): void
    {
        $barangay = Barangay::query()->where('code', 'BSJ')->first();

        if ($barangay === null) {
            return;
        }

        $barangay->update([
            'city_logo_path' => '/certificate-branding/city_logo.png',
            'barangay_logo_path' => '/certificate-branding/barangay_sanjose_logo.png',
            'watermark_path' => '/certificate-branding/watermark-seal.png',
            'header_text' => [
                'Republic of the Philippines',
                'City of Puerto Princesa',
                'Barangay San Jose',
                'OFFICE OF THE PUNONG BARANGAY',
            ],
        ]);
    }

}
