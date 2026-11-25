<?php

namespace Tests\Feature;

use App\Models\DisclosurePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class FinancialDisclosureMigrationSafetyTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    public function test_up_refuses_existing_periods_before_any_schema_change(): void
    {
        $barangay = $this->createBarangay('Legacy Barangay', 'FD-LEGACY');

        DisclosurePeriod::create([
            'barangay_id' => $barangay->id,
            'year' => 2026,
            'month' => 11,
            'is_published' => false,
        ]);

        try {
            $this->migration()->up();
            $this->fail('The migration should refuse to assign ownership to existing periods.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'must be assigned to a barangay before this migration',
                $exception->getMessage()
            );
        }

        $this->assertTrue(Schema::hasColumn('disclosure_periods', 'barangay_id'));
        $this->assertSame(1, DB::table('disclosure_periods')->count());
    }

    public function test_down_refuses_to_remove_ownership_from_assigned_periods(): void
    {
        $barangay = $this->createBarangay('Assigned Barangay', 'FD-ASSIGNED');

        DisclosurePeriod::create([
            'barangay_id' => $barangay->id,
            'year' => 2026,
            'month' => 11,
            'is_published' => false,
        ]);

        try {
            $this->migration()->down();
            $this->fail('The rollback should refuse to discard assigned barangay ownership.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'assigned row(s) would lose ownership',
                $exception->getMessage()
            );
        }

        $this->assertTrue(Schema::hasColumn('disclosure_periods', 'barangay_id'));
        $this->assertSame($barangay->id, (int) DB::table('disclosure_periods')->value('barangay_id'));
    }

    public function test_empty_periods_round_trip_preserves_tenant_indexes_and_restrict_delete(): void
    {
        $migration = $this->migration();

        $migration->down();
        $this->assertFalse(Schema::hasColumn('disclosure_periods', 'barangay_id'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('disclosure_periods', 'barangay_id'));

        $column = collect(DB::select("PRAGMA table_info('disclosure_periods')"))
            ->firstWhere('name', 'barangay_id');
        $this->assertSame(1, (int) $column->notnull);

        $indexes = collect(DB::select("PRAGMA index_list('disclosure_periods')"))
            ->pluck('name')
            ->all();
        $this->assertContains('disclosure_periods_tenant_period_idx', $indexes);
        $this->assertContains('disclosure_periods_tenant_published_idx', $indexes);

        $foreignKey = collect(DB::select("PRAGMA foreign_key_list('disclosure_periods')"))
            ->firstWhere('table', 'barangays');
        $this->assertNotNull($foreignKey);
        $this->assertSame('barangay_id', $foreignKey->from);
        $this->assertSame('RESTRICT', strtoupper((string) $foreignKey->on_delete));
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_11_26_000002_add_barangay_to_disclosure_periods_table.php');
    }
}
