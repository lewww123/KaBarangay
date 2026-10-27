<?php

namespace Tests\Feature;

use App\Support\ResidentNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class ResidentNumberGeneratorTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    public function test_number_uses_the_recorded_barangay_code_and_purok_mapping(): void
    {
        $barangay = $this->createBarangay('Barangay San Jose', 'BSJ');
        $purok = $this->createPurok($barangay, 'Bagong Pag-asa');
        $purok->update(['code' => 'BSJ-P01', 'sort_order' => 8]);

        $number = app(ResidentNumberGenerator::class)->next($barangay, $purok->id);

        $this->assertSame('BSJ-01-0001', $number);
    }

    public function test_sequence_is_shared_across_puroks_in_one_barangay(): void
    {
        $barangay = $this->createBarangay('Barangay San Jose', 'BSJ');
        $firstPurok = $this->createPurok($barangay, 'Bagong Pag-asa');
        $firstPurok->update(['code' => 'BSJ-P01']);
        $secondPurok = $this->createPurok($barangay, 'Masunurin');
        $secondPurok->update(['code' => 'BSJ-P02']);
        $generator = app(ResidentNumberGenerator::class);

        $this->assertSame('BSJ-01-0001', $generator->next($barangay, $firstPurok->id));
        $this->assertSame('BSJ-02-0002', $generator->next($barangay, $secondPurok->id));
    }

    public function test_legacy_suffixes_do_not_advance_the_official_sequence(): void
    {
        $barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $purok = $this->createPurok($barangay, 'Purok 1');
        $legacy = $this->createResident($barangay, $purok, [
            'mobile' => '09170000991',
        ]);
        $legacy->update(['resident_number' => 'BRGYONE-RES-900001']);

        $number = app(ResidentNumberGenerator::class)->next($barangay, $purok->id);

        $this->assertSame('BRGYONE-01-0001', $number);
        $this->assertSame('BRGYONE-RES-900001', $legacy->fresh()->resident_number);
    }
}
