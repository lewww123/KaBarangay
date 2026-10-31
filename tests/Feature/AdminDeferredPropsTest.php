<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Purok;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class AdminDeferredPropsTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Purok $purok;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->barangay = $this->createBarangay('Deferred Barangay', 'DEFERRED-ONE');
        $this->purok = $this->createPurok($this->barangay);
    }

    public function test_dashboard_heavy_props_are_deferred_and_reload_on_demand(): void
    {
        config(['inertia.defer_heavy_props' => true]);
        $this->createResident($this->barangay, $this->purok);
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->missing('stats')
                ->missing('announcements')
                ->loadDeferredProps(fn ($page) => $page
                    ->where('stats.residents', 2)
                    ->has('puroks')
                    ->has('announcements')
                    ->has('notifications')
                    ->has('activity')));
    }

    public function test_sector_heavy_props_are_deferred_while_filters_stay_eager(): void
    {
        config(['inertia.defer_heavy_props' => true]);
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/sectors?search=Juan&tab=pwd')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Sectors')
                ->where('filters.search', 'Juan')
                ->where('initialTab', 'pwd')
                ->missing('stats')
                ->missing('generalMembers')
                ->loadDeferredProps(fn ($page) => $page
                    ->has('stats')
                    ->has('sectors')
                    ->has('generalMembers')
                    ->has('pwdMembers')
                    ->has('seniorMembers')
                    ->has('puroks')
                    ->has('certificates')
                    ->has('certificateRequests')
                    ->has('luponSummary')));
    }

    public function test_resident_heavy_props_are_deferred_while_filters_stay_eager(): void
    {
        config(['inertia.defer_heavy_props' => true]);
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/residents?resident_search=Juan')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Residents')
                ->where('filters.resident_search', 'Juan')
                ->missing('allResidents')
                ->missing('stats')
                ->loadDeferredProps(fn ($page) => $page
                    ->has('allResidents')
                    ->has('pendingRegistrations')
                    ->has('needsCorrectionRegistrations')
                    ->has('rejectedRegistrations')
                    ->has('puroks')
                    ->has('addedByAdmin')
                    ->has('stats')));
    }

    public function test_report_heavy_props_are_deferred_while_filters_and_window_stay_eager(): void
    {
        config(['inertia.defer_heavy_props' => true]);
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/reports?period=monthly&date=2026-10-27')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Reports')
                ->where('filters.period', 'monthly')
                ->where('window.from', '2026-10-01')
                ->missing('population')
                ->missing('certificates')
                ->missing('lupon')
                ->missing('programs')
                ->missing('financial')
                ->loadDeferredProps(fn ($page) => $page
                    ->has('population')
                    ->has('certificates')
                    ->has('lupon')
                    ->has('programs')
                    ->has('financial')));
    }
}
