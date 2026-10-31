<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class AdminRelationshipQueryBudgetTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    public static function pages(): array
    {
        return [['/admin/residents'], ['/admin/sectors']];
    }

    #[DataProvider('pages')]
    public function test_relationship_query_count_does_not_grow_with_list_size(string $path): void
    {
        $this->withoutVite();
        config(['inertia.defer_heavy_props' => false]);
        $barangay = $this->createBarangay('Query Budget', 'QUERY-BUDGET');
        $admin = $this->createStaff($barangay, 'admin');
        $addRecords = function (int $number) use ($barangay): void {
            // Distinct puroks expose accidental per-row relationship loading.
            $purok = $this->createPurok($barangay, 'Purok '.$number);
            $resident = $this->createResident($barangay, $purok, ['mobile' => sprintf('0917%07d', $number)]);
            $this->assignSector($resident, 'pwd');
            $this->createPendingRegistration($barangay, $purok, ['mobile' => sprintf('0998%07d', $number)]);
        };
        $addRecords(1);
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        $this->actingAs($admin);

        Cache::flush();
        $count = 0;
        $this->get($path)->assertOk();
        $singleRowCount = $count;

        foreach (range(2, 15) as $number) {
            $addRecords($number);
        }

        Cache::flush();
        $count = 0;
        $this->get($path)->assertOk();
        $this->assertGreaterThan(0, $singleRowCount);
        $this->assertSame($singleRowCount, $count, 'Relationship query count must remain constant from 1 to 15 rows.');
    }
}
