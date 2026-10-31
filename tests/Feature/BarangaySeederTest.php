<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Purok;
use Database\Seeders\BarangaySeeder;
use Database\Seeders\PurokSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class BarangaySeederTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    public function test_canonical_purok_catalog_is_exact_ordered_and_repeatable_from_both_seeders(): void
    {
        $this->seed(BarangaySeeder::class);
        $sanJose = Barangay::where('code', 'BSJ')->firstOrFail();
        $sicsican = Barangay::where('code', 'BSC')->firstOrFail();
        $puroks = $sanJose->puroks()->orderBy('sort_order')->get();

        $this->assertSame([
            'Purok Bagong Pag-asa', 'Purok Masunurin', 'Purok Maligaya',
            'Purok Matapat', 'Purok Matiyaga', 'Purok Pagkakaisa',
            'Purok Masigla', 'Purok Masikap I', 'Purok Masikap II',
            'Purok Mapagmahal', 'Purok Masayahin', 'Purok Masagana',
        ], $puroks->pluck('name')->all());
        $this->assertSame(range(1, 12), $puroks->pluck('sort_order')->all());
        $this->assertSame(array_map(fn ($number) => sprintf('BSJ-P%02d', $number), range(1, 12)), $puroks->pluck('code')->all());
        $this->assertTrue($puroks->every(fn ($purok) => $purok->is_active));

        $defaults = $sicsican->puroks()->orderBy('sort_order')->get();
        $this->assertSame('Barangay Sicsican', $sicsican->name);
        $this->assertSame(array_map(fn ($number) => 'Purok '.$number, range(1, 7)), $defaults->pluck('name')->all());
        $this->assertSame(array_map(fn ($number) => sprintf('BSC-P%02d', $number), range(1, 7)), $defaults->pluck('code')->all());
        $this->assertSame(range(1, 7), $defaults->pluck('sort_order')->all());

        $ids = Purok::orderBy('id')->pluck('id')->all();
        $this->seed(BarangaySeeder::class);
        $this->seed(PurokSeeder::class);
        $this->assertSame($ids, Purok::orderBy('id')->pluck('id')->all());
        $this->assertDatabaseCount('barangays', 3);
        $this->assertDatabaseCount('puroks', 19);
    }

    public function test_refresh_preserves_legacy_purok_links_and_other_barangay_puroks(): void
    {
        $sanJose = $this->createBarangay('San Jose', 'BSJ');
        $legacy = $this->createPurok($sanJose, 'Bagong Pag-asa');
        $legacy->update(['code' => 'BSJ-P01']);
        $resident = $this->createResident($sanJose, $legacy);
        $obsolete = $this->createPurok($sanJose, 'Unused old purok');
        $other = $this->createPurok($this->createBarangay('Sta. Monica', 'BSM'), 'Existing Monica Purok');

        $this->seed(BarangaySeeder::class);

        $this->assertSame('Purok Bagong Pag-asa', $legacy->fresh()->name);
        $this->assertSame($legacy->id, $resident->fresh()->purok_id);
        $this->assertSame($legacy->id, $resident->registration()->firstOrFail()->purok_id);
        $this->assertModelMissing($obsolete);
        $this->assertModelExists($other);
        $this->assertSame(12, $sanJose->puroks()->count());
    }
}
