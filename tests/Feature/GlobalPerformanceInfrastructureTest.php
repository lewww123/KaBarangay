<?php

namespace Tests\Feature;

use App\Auth\CachedEloquentUserProvider;
use App\Http\Middleware\HandleInertiaRequests;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GlobalPerformanceInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_application_shared_props_are_lazy(): void
    {
        $props = app(HandleInertiaRequests::class)->share(request());
        foreach (['name', 'auth', 'flash', 'certificateTypes'] as $key) {
            $this->assertInstanceOf(Closure::class, $props[$key]);
        }
    }

    public function test_every_configured_staff_guard_uses_the_cached_provider(): void
    {
        foreach (config('auth.guards') as $guard) {
            if (($guard['provider'] ?? null) === 'users') {
                $this->assertInstanceOf(CachedEloquentUserProvider::class, Auth::createUserProvider($guard['provider']));
            }
        }
    }

    public function test_all_foreign_keys_and_conventional_identity_columns_have_leading_index_coverage(): void
    {
        foreach (Schema::getTableListing(null, false) as $table) {
            $indexes = Schema::getIndexes($table);
            $columns = Schema::getColumnListing($table);
            $foreignKeys = array_column(Schema::getForeignKeys($table), 'columns');
            foreach (['barangay_id', 'user_id', 'resident_id'] as $column) {
                if (in_array($column, $columns, true)) {
                    $foreignKeys[] = [$column];
                }
            }
            foreach ($foreignKeys as $foreignColumns) {
                $covered = collect($indexes)->contains(fn ($index) => array_slice($index['columns'], 0, count($foreignColumns)) === $foreignColumns);
                $this->assertTrue($covered, $table.' missing leading index for '.implode(', ', $foreignColumns));
            }
        }
    }

    public function test_global_index_migration_is_idempotent_and_rollback_preserves_existing_indexes(): void
    {
        $migration = require database_path('migrations/2026_10_29_000003_add_missing_foreign_key_performance_indexes.php');
        $before = Schema::getIndexes('users');
        $migration->up();
        $this->assertSame($before, Schema::getIndexes('users'));
        $migration->down();
        $this->assertTrue(Schema::hasIndex('users', 'users_id_barangay_perf_idx'));
        $this->assertTrue(Schema::hasIndex('residents', 'residents_barangay_status_source_perf_idx'));
        $migration->up();
        $this->assertSame($before, Schema::getIndexes('users'));
    }
}
