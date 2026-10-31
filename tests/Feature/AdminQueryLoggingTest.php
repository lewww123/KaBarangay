<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AdminQueryLoggingTest extends TestCase
{
    public function test_queries_include_sql_and_millisecond_timing_without_bindings(): void
    {
        config(['logging.admin_queries.enabled' => true, 'logging.admin_queries.min_duration_ms' => 0]);
        $this->assertSame(storage_path('logs/laravel.log'), config('logging.channels.admin_queries.path'));
        $request = Request::create('/admin/residents', 'GET');
        $request->headers->set('X-Inertia', 'true');
        $request->headers->set('X-Inertia-Partial-Data', 'allResidents');
        $this->app->instance('request', $request);
        $entries = [];
        Log::shouldReceive('channel')->with('admin_queries')->twice()->andReturnSelf();
        Log::shouldReceive('info')->withArgs(function ($message, $context) use (&$entries): bool {
            $this->assertSame('admin_sql_query', $message);
            $entries[] = $context;

            return true;
        })->twice();

        DB::select('select ? as value', ['private-value']);
        DB::select('select ? as value', ['another-private-value']);

        $this->assertSame('select ? as value', $entries[0]['sql']);
        $this->assertGreaterThanOrEqual(0, $entries[0]['duration_ms']);
        $this->assertSame('admin/residents', $entries[0]['path']);
        $this->assertTrue($entries[0]['inertia']);
        $this->assertSame('allResidents', $entries[0]['partial_data']);
        $this->assertNotEmpty($entries[0]['request_id']);
        $this->assertSame($entries[0]['request_id'], $entries[1]['request_id']);
        $this->assertStringNotContainsString('private-value', json_encode($entries));
    }

    public function test_logging_respects_toggle_path_method_and_duration_threshold(): void
    {
        Log::shouldReceive('channel')->never();
        $this->app->instance('request', Request::create('/admin', 'GET'));
        config(['logging.admin_queries.enabled' => false]);
        DB::select('select 1');

        config(['logging.admin_queries.enabled' => true, 'logging.admin_queries.min_duration_ms' => 0]);
        $this->app->instance('request', Request::create('/resident', 'GET'));
        DB::select('select 1');
        $this->app->instance('request', Request::create('/admin/staff', 'POST'));
        DB::select('select 1');

        $this->app->instance('request', Request::create('/admin', 'GET'));
        config(['logging.admin_queries.min_duration_ms' => PHP_INT_MAX]);
        DB::select('select 1');
    }
}
