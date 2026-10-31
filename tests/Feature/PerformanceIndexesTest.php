<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PerformanceIndexesTest extends TestCase
{
    use RefreshDatabase;

    public function test_performance_indexes_cover_the_requested_columns_and_can_be_rolled_back(): void
    {
        $indexes = [
            'users' => ['users_id_barangay_perf_idx', ['id', 'barangay_id']],
            'residents' => ['residents_barangay_status_source_perf_idx', ['barangay_id', 'status', 'source']],
            'resident_registrations' => ['registrations_barangay_status_matched_perf_idx', ['barangay_id', 'status', 'matched_resident_id']],
            'resident_claims' => ['claims_barangay_status_resident_perf_idx', ['barangay_id', 'status', 'resident_id']],
            'resident_accounts' => ['resident_accounts_resident_active_perf_idx', ['resident_id', 'is_active']],
        ];
        foreach ($indexes as $table => [$name, $columns]) {
            $index = collect(Schema::getIndexes($table))->firstWhere('name', $name);
            $this->assertNotNull($index);
            $this->assertSame($columns, $index['columns']);
        }

        $migration = require database_path('migrations/2026_10_29_000002_add_performance_indexes_to_tables.php');
        $migration->down();
        foreach ($indexes as $table => [$name]) {
            $this->assertFalse(Schema::hasIndex($table, $name));
        }
        $migration->up();
    }
}
