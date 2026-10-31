<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class NavigationPerformanceTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    public static function adminPages(): array
    {
        return [
            ['/admin/population', 'Admin/Population', ['stats', 'puroks']],
            ['/admin/certificates', 'Admin/Certificates', ['requests', 'stats']],
            ['/admin/audit-log', 'Admin/AuditLog', ['logs', 'loginAttempts', 'loginAttemptsAvailable', 'alerts', 'alertsAvailable']],
            ['/admin/announcements/approvals', 'Admin/AnnouncementApprovals', ['announcements']],
            ['/admin/emergency-numbers', 'Admin/EmergencyNumbers/Index', ['barangayHotlines', 'sharedEmergencyContacts']],
            ['/admin/sector-applications', 'Admin/SectorApplications/Index', ['applications']],
        ];
    }

    #[DataProvider('adminPages')]
    public function test_admin_navigation_defers_business_queries(string $url, string $component, array $props): void
    {
        $this->withoutVite();
        config(['inertia.defer_heavy_props' => true]);
        $barangay = $this->createBarangay('Navigation', 'NAV-1');
        $admin = $this->createAssignedStaff([
            'barangay' => $barangay,
            'purok' => $this->createPurok($barangay),
        ], 'admin', 'Barangay Admin');
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if (! str_contains($query->sql, '"staff_assignments"') && ! str_contains($query->sql, '"users"')) {
                $queries[] = $query->sql;
            }
        });

        $response = $this->actingAs($admin)->get($url)->assertOk();
        $this->assertCount(0, $queries, 'Initial navigation must not execute the deferred table or aggregate queries.');
        $response->assertInertia(function ($page) use ($component, $props) {
            $page->component($component);
            foreach ($props as $prop) {
                $page->missing($prop);
            }
            $page->loadDeferredProps(function ($loaded) use ($props) {
                foreach ($props as $prop) {
                    $loaded->has($prop);
                }
            });
        });
    }

    public static function residentPages(): array
    {
        return [['/resident'], ['/resident/profile'], ['/resident/certificates'], ['/resident/lupon'],
            ['/resident/citizen-charter'], ['/resident/disbursement'], ['/resident/org-chart']];
    }

    #[DataProvider('residentPages')]
    public function test_resident_navigation_reuses_the_authenticated_record_and_revalidates_the_next_request(string $url): void
    {
        $this->withoutVite();
        config(['inertia.defer_heavy_props' => true]);
        $barangay = $this->createBarangay('Navigation', 'NAV-1');
        $resident = $this->createResident($barangay, $this->createPurok($barangay));
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->withSession(['resident_id' => $resident->id])->get($url)->assertOk();
        $this->assertCount(1, $queries, 'Resident identity and active-account checks should share one query.');

        $resident->account()->update(['is_active' => false]);
        $this->get($url)->assertRedirect(route('resident.login'));
    }

    public function test_postgresql_connections_remain_persistent(): void
    {
        $this->assertTrue(config('database.connections.pgsql.options')[\PDO::ATTR_PERSISTENT]);
        $this->assertTrue(config('database.connections.pgsql.options')[\PDO::ATTR_EMULATE_PREPARES]);
    }
}
