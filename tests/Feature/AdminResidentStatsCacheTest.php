<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class AdminResidentStatsCacheTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    public function test_live_stats_are_scoped_by_barangay_and_filters_without_stale_counts(): void
    {
        $this->withoutVite();
        $barangay = $this->createBarangay('Stats Cache', 'STATS-CACHE');
        $user = $this->createStaff($barangay, 'admin');
        $purok = $this->createPurok($barangay);
        $this->createResident($barangay, $purok);
        $otherUser = $this->createStaff($this->createBarangay('Other Stats', 'OTHER-STATS'), 'admin');
        $aggregateQueries = 0;
        DB::listen(function ($query) use (&$aggregateQueries): void {
            if (str_contains($query->sql, 'total_residents') && str_contains($query->sql, 'pending_reviews')) {
                $aggregateQueries++;
            }
        });

        // Exercise the production path; pending queues must use current counts.
        // Do not cache a zero count that could hide a newly submitted account.
        $this->app['env'] = 'local';
        try {
            $headers = [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()) ?? '',
                'X-Inertia-Partial-Component' => 'Admin/Residents',
                'X-Inertia-Partial-Data' => 'stats',
            ];
            $this->actingAs($user)->get('/admin/residents', $headers)
                ->assertOk()->assertJsonPath('props.stats.totalResidents', 2);
            $this->createResident($barangay, $purok, ['mobile' => '09179990001']);
            $this->get('/admin/residents', $headers)->assertOk()
                ->assertJsonPath('props.stats.totalResidents', 3);
            $this->assertSame(2, $aggregateQueries);

            $this->get('/admin/residents?resident_search=Juan', $headers)->assertOk();
            $this->get('/admin/residents?registration_search=Pending', $headers)->assertOk();
            $this->get('/admin/residents?purok='.$purok->id, $headers)->assertOk();
            $this->assertSame(5, $aggregateQueries);

            $this->actingAs($otherUser)->get('/admin/residents', $headers)
                ->assertOk()->assertJsonPath('props.stats.totalResidents', 1);
            $this->assertSame(6, $aggregateQueries);

            $this->travel(31)->seconds();
            $this->actingAs($user)->get('/admin/residents', $headers)->assertOk();
            $this->assertSame(7, $aggregateQueries);
        } finally {
            $this->app['env'] = 'testing';
        }
    }
}
