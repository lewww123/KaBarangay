<?php

namespace Database\Seeders;

use App\Models\Barangay;
use App\Models\Purok;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BarangaySeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $barangays = [];
            foreach ([
                'BSJ' => 'Barangay San Jose',
                'BSM' => 'Barangay Sta. Monica',
                'BSC' => 'Barangay Sicsican',
            ] as $code => $name) {
                $barangays[$code] = Barangay::updateOrCreate(
                    ['code' => $code],
                    [
                        'name' => $name,
                        'city_municipality' => 'Puerto Princesa City',
                        'province' => 'Palawan',
                        'is_active' => true,
                    ],
                );
            }

            $this->syncPuroks($barangays['BSJ'], [
                'Purok Bagong Pag-asa',
                'Purok Masunurin',
                'Purok Maligaya',
                'Purok Matapat',
                'Purok Matiyaga',
                'Purok Pagkakaisa',
                'Purok Masigla',
                'Purok Masikap I',
                'Purok Masikap II',
                'Purok Mapagmahal',
                'Purok Masayahin',
                'Purok Masagana',
            ]);
            $this->syncPuroks($barangays['BSC'], array_map(
                fn (int $number) => 'Purok '.$number,
                range(1, 7),
            ));
        });
    }

    /** @param list<string> $names */
    private function syncPuroks(Barangay $barangay, array $names): void
    {
        $existing = Purok::query()->where('barangay_id', $barangay->id)->lockForUpdate()->get();
        $retainedIds = [];

        foreach ($names as $index => $name) {
            $code = sprintf('%s-P%02d', $barangay->code, $index + 1);
            // Rename legacy seeded rows in place to preserve resident links.
            $purok = $existing->firstWhere('name', $name)
                ?? $existing->firstWhere('name', preg_replace('/^Purok /', '', $name))
                ?? $existing->firstWhere('code', $code)
                ?? new Purok(['barangay_id' => $barangay->id]);
            $purok->fill([
                'name' => $name,
                'code' => $code,
                'sort_order' => $index + 1,
                'is_active' => true,
            ])->save();
            $retainedIds[] = $purok->id;
        }

        foreach ($existing->whereNotIn('id', $retainedIds) as $obsolete) {
            // Existing FK constraints reject deletion of referenced records;
            // the surrounding transaction then rolls back the entire refresh.
            $obsolete->delete();
        }
    }
}
