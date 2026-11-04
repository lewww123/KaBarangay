<?php

namespace Database\Seeders;

use App\Models\Barangay;
use App\Models\Purok;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BarangaySeeder extends Seeder
{
    public const SICSICAN_PUROKS = [
        'Anonang', 'Avocado', 'Acacia', 'Banaba', 'Bakawan',
        'Fire Tree', 'Ilang-Ilang', 'Langka', 'Lanzones', 'Mabuhay',
        'Makopa', 'Mahogany', 'Mangga', 'Mulawin', 'Narra',
        'Palawan Cherry', 'Sampaloc', 'Star Apple', 'Talisay',
    ];

    public const STA_MONICA_PUROKS = [
        'Purok Maunlad', 'Purok El Rancho', 'Purok Fisherman’s Village',
        'Purok Villa Princesa', 'Purok Magalang', 'Purok Gumamela',
        'Purok Pagkakaisa', 'Purok Maagap', 'Purok Mountain View (Mt. View)',
        'Purok Sampaguita', 'Purok Pres. Paredes', 'Purok Pres. Buenaceda',
        'Employees Village',
    ];

    public function syncStaMonica(Barangay $barangay): void
    {
        foreach (self::STA_MONICA_PUROKS as $index => $name) {
            Purok::updateOrCreate(
                ['barangay_id' => $barangay->id, 'name' => $name],
                ['code' => sprintf('%s-P%02d', $barangay->code, $index + 1),
                    'sort_order' => $index + 1, 'is_active' => true],
            );
        }
    }

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
            $this->syncSicsican($barangays['BSC']);
            $this->syncStaMonica($barangays['BSM']);
        });
    }

    public function syncSicsican(Barangay $barangay): void
    {
        $existing = Purok::where('barangay_id', $barangay->id)->lockForUpdate()->get();
        $palawan = $existing->firstWhere('name', 'Palawan');
        $combined = $existing->firstWhere('name', 'Palawan Cherry');
        if ($palawan && $combined) {
            throw new \RuntimeException('Both Palawan and Palawan Cherry exist; reconcile before refreshing.');
        }
        $palawan ??= $combined;
        if ($palawan) {
            $palawan->update(['name' => 'Palawan Cherry']);
            $cherry = $existing->firstWhere('name', 'Cherry');
            if ($cherry) {
                foreach (['resident_claims', 'resident_registrations', 'residents', 'households'] as $table) {
                    DB::table($table)->where('purok_id', $cherry->id)->update(['purok_id' => $palawan->id]);
                }
                $cherry->delete();
            }
        }
        $this->syncPuroks($barangay, self::SICSICAN_PUROKS, true);
    }

    /** @param list<string> $names */
    private function syncPuroks(Barangay $barangay, array $names, bool $preserveObsolete = false): void
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
            if ($preserveObsolete) {
                $obsolete->update(['is_active' => false]);
            } else {
                $obsolete->delete();
            }
        }
    }
}
