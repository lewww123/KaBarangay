<?php

namespace Database\Seeders;

use App\Models\Barangay;
use App\Models\Purok;
use Illuminate\Database\Seeder;

class PurokSeeder extends Seeder
{
    public function run(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Barangay San Jose
        |--------------------------------------------------------------------------
        */

        $sanJose = Barangay::where('code', 'BSJ')->firstOrFail();

        $sanJosePuroks = [
            'Bagong Pag-asa',
            'Masunurin',
            'Maligaya',
            'Matapat',
            'Matiyaga',
            'Pagkakaisa',
            'Masigla',
            'Masikap I',
            'Masikap II',
            'Mapagmahal',
            'Masayahin',
            'Masagana',
        ];

        foreach ($sanJosePuroks as $index => $name) {
            Purok::updateOrCreate(
                [
                    'barangay_id' => $sanJose->id,
                    'name' => $name,
                ],
                [
                    'code' => 'BSJ-P' . str_pad(
                        (string) ($index + 1),
                        2,
                        '0',
                        STR_PAD_LEFT
                    ),
                    'sort_order' => $index + 1,
                    'is_active' => true,
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Barangay Sta. Monica
        |--------------------------------------------------------------------------
        |
        | We will add the actual Sta. Monica puroks once confirmed.
        |
        */

        $staMonica = Barangay::where('code', 'BSM')->firstOrFail();

        $staMonicaPuroks = [
            // Add actual Barangay Sta. Monica puroks here later.
        ];

        foreach ($staMonicaPuroks as $index => $name) {
            Purok::updateOrCreate(
                [
                    'barangay_id' => $staMonica->id,
                    'name' => $name,
                ],
                [
                    'code' => 'BSM-P' . str_pad(
                        (string) ($index + 1),
                        2,
                        '0',
                        STR_PAD_LEFT
                    ),
                    'sort_order' => $index + 1,
                    'is_active' => true,
                ]
            );
        }
    }
}