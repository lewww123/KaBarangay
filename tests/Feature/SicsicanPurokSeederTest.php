<?php

namespace Tests\Feature;

use App\Models\Purok;
use Database\Seeders\BarangaySeeder;
use Database\Seeders\SicsicanPurokSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class SicsicanPurokSeederTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    public function test_exact_named_list_is_repeatable_and_scoped_to_sicsican(): void
    {
        $barangay = $this->createBarangay('Barangay Sicsican', 'BSC');
        $other = $this->createBarangay('Barangay San Jose', 'BSJ');
        $otherPurok = $this->createPurok($other, 'Existing Other Purok');
        $legacy = Purok::create(['barangay_id' => $barangay->id, 'name' => 'Purok 1', 'code' => 'BSC-P01', 'is_active' => true]);
        $resident = $this->createResident($barangay, $legacy);
        $obsolete = $this->createPurok($barangay, 'Legacy Other');
        $this->seed(SicsicanPurokSeeder::class);
        $this->seed(SicsicanPurokSeeder::class);
        $this->assertSame(BarangaySeeder::SICSICAN_PUROKS, Purok::where('barangay_id', $barangay->id)->where('is_active', true)->orderBy('sort_order')->pluck('name')->all());
        $this->assertSame(20, Purok::where('barangay_id', $barangay->id)->where('is_active', true)->count());
        $this->assertSame($legacy->id, $resident->fresh()->purok_id);
        $this->assertSame('Anonang', $legacy->fresh()->name);
        $this->assertFalse($obsolete->fresh()->is_active);
        $this->assertSame('Existing Other Purok', $otherPurok->fresh()->name);
        $this->assertTrue($otherPurok->fresh()->is_active);
    }
}
