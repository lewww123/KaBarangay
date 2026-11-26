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
        $migration = $this->migration();

        $migration->down();

        $this->assertFalse(Schema::hasColumn('disclosure_periods', 'barangay_id'));
        $this->assertTrue(Schema::hasIndex('disclosure_periods', 'disclosure_periods_year_month_idx'));
        $this->assertFalse(Schema::hasIndex('disclosure_periods', 'disclosure_periods_tenant_period_idx', 'unique'));
        $this->assertFalse(Schema::hasIndex('disclosure_periods', 'disclosure_periods_tenant_published_idx'));

        $legacyPeriodId = '00000000-0000-4000-8000-000000000001';

        DB::table('disclosure_periods')->insert([
            'id' => $legacyPeriodId,
            'year' => 2026,
            'month' => 11,
            'is_published' => false,
        ]);

        try {
            $migration->up();
            $this->fail('The migration should refuse to assign ownership to existing periods.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'must be assigned to a barangay before this migration',
                $exception->getMessage()
            );
        }

        $this->assertFalse(Schema::hasColumn('disclosure_periods', 'barangay_id'));
        $this->assertFalse(Schema::hasIndex('disclosure_periods', 'disclosure_periods_tenant_period_idx', 'unique'));
        $this->assertFalse(Schema::hasIndex('disclosure_periods', 'disclosure_periods_tenant_published_idx'));
        $this->assertSame(1, DB::table('disclosure_periods')->count());

        $legacyPeriod = DB::table('disclosure_periods')->where('id', $legacyPeriodId)->first();
        $this->assertNotNull($legacyPeriod);
        $this->assertSame($legacyPeriodId, $legacyPeriod->id);
        $this->assertSame(2026, (int) $legacyPeriod->year);
        $this->assertSame(11, (int) $legacyPeriod->month);
        $this->assertSame(0, (int) $legacyPeriod->is_published);
        $this->assertNull($legacyPeriod->published_by);
        $this->assertNull($legacyPeriod->published_at);
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
        $this->assertTrue(Schema::hasIndex('disclosure_periods', 'disclosure_periods_tenant_period_idx', 'unique'));
        $this->assertTrue(Schema::hasIndex('disclosure_periods', 'disclosure_periods_tenant_published_idx'));
        $this->assertSame(1, DB::table('disclosure_periods')->count());
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
        $this->assertNull($column->dflt_value);

        $this->assertFalse(Schema::hasIndex('disclosure_periods', 'disclosure_periods_year_month_idx'));
        $this->assertTrue(Schema::hasIndex('disclosure_periods', 'disclosure_periods_tenant_period_idx', 'unique'));
        $this->assertTrue(Schema::hasIndex('disclosure_periods', 'disclosure_periods_tenant_published_idx'));

        $tenantPeriodIndex = $this->index('disclosure_periods_tenant_period_idx');
        $this->assertTrue($tenantPeriodIndex['unique']);
        $this->assertSame(['barangay_id', 'year', 'month'], $tenantPeriodIndex['columns']);

        $tenantPublishedIndex = $this->index('disclosure_periods_tenant_published_idx');
        $this->assertFalse($tenantPublishedIndex['unique']);
        $this->assertSame(['barangay_id', 'is_published'], $tenantPublishedIndex['columns']);

        $foreignKey = collect(DB::select("PRAGMA foreign_key_list('disclosure_periods')"))
            ->firstWhere('table', 'barangays');
        $this->assertNotNull($foreignKey);
        $this->assertSame('barangay_id', $foreignKey->from);
        $this->assertSame('RESTRICT', strtoupper((string) $foreignKey->on_delete));
    }

    private function index(string $name): array
    {
        $index = collect(Schema::getIndexes('disclosure_periods'))
            ->firstWhere('name', $name);

        $this->assertIsArray($index);
        $this->assertSame($name, $index['name']);

        return $index;
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_11_26_000002_add_barangay_to_disclosure_periods_table.php');
    }
}
