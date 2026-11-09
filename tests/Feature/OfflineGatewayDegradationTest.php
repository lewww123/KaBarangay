<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Support\OfflineDegraded;
use App\Support\OfflineIdentity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PDOException;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\Concerns\SimulatesUnreachableDatabase;
use Tests\TestCase;

/**
 * The reported outage: the device keeps its Wi-Fi connection and this application server
 * keeps answering, but Supabase PostgreSQL is unreachable. Every authenticated route used
 * to fail inside its authentication gateway with a raw 500, so the browser never learned
 * the app had degraded. These tests pin the gateway contract: unavailable, not broken;
 * session kept, scope untouched, controller never executed.
 */
class OfflineGatewayDegradationTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase, SimulatesUnreachableDatabase;

    private const PROTECTED_MARKER = 'PROTECTED CONTROLLER RAN';

    public function test_resident_route_degrades_instead_of_returning_an_unhandled_server_error(): void
    {
        $barangay = $this->createBarangay('Outage Barangay', 'OUT');
        $resident = $this->createResident($barangay, $this->createPurok($barangay));

        $this->withUnreachableDatabase(function () use ($resident) {
            $this->withSession(['resident_id' => $resident->id])
                ->get('/resident/notifications')
                ->assertStatus(503)
                ->assertHeader(OfflineDegraded::HEADER, OfflineDegraded::UNAVAILABLE)
                ->assertHeaderContains('Cache-Control', 'no-store');
        });
    }

    public function test_resident_session_survives_the_outage_without_logging_anyone_out(): void
    {
        $barangay = $this->createBarangay('Outage Barangay', 'OUT');
        $resident = $this->createResident($barangay, $this->createPurok($barangay));

        // An unreadable database must not be read as "this resident is gone": the
        // old code answered null and fell into the branch that forgets the session.
        $this->withUnreachableDatabase(function () use ($resident) {
            $this->withSession(['resident_id' => $resident->id])
                ->get('/resident/notifications')
                ->assertStatus(503)
                ->assertSessionHas('resident_id', $resident->id);
        });
    }

    public function test_resident_gateway_does_not_reach_the_protected_controller_during_the_outage(): void
    {
        $this->protectedRoute('resident');
        $barangay = $this->createBarangay('Outage Barangay', 'OUT');
        $resident = $this->createResident($barangay, $this->createPurok($barangay));

        $this->withUnreachableDatabase(function () use ($resident) {
            $this->withSession(['resident_id' => $resident->id])
                ->get('/kb-outage-probe')
                ->assertStatus(503)
                ->assertDontSee(self::PROTECTED_MARKER);
        });
    }

    public function test_staff_route_degrades_instead_of_falsely_aborting_as_unauthorized(): void
    {
        $barangay = $this->createBarangay('Outage Barangay', 'OUT');
        $clerk = $this->createStaff($barangay, 'clerk');

        $this->withUnreachableDatabase(function () use ($clerk) {
            $this->actingAs($clerk)
                ->get('/clerk/dashboard')
                ->assertStatus(503)
                ->assertHeader(OfflineDegraded::HEADER, OfflineDegraded::UNAVAILABLE)
                ->assertDontSee('Clerk/Dashboard');
            $this->assertAuthenticatedAs($clerk);
        });
    }

    public function test_staff_gateway_does_not_reach_the_protected_controller_during_the_outage(): void
    {
        $this->protectedRoute('staff');
        $barangay = $this->createBarangay('Outage Barangay', 'OUT');
        $clerk = $this->createStaff($barangay, 'clerk');

        $this->withUnreachableDatabase(function () use ($clerk) {
            $this->actingAs($clerk)
                ->post('/kb-outage-probe')
                ->assertStatus(503)
                ->assertDontSee(self::PROTECTED_MARKER);
        });
    }

    public function test_json_clients_receive_the_degraded_contract_with_unresolved_identity(): void
    {
        $barangay = $this->createBarangay('Outage Barangay', 'OUT');
        $resident = $this->createResident($barangay, $this->createPurok($barangay));

        // X-Inertia visits take this same branch; the body, not the status, is what
        // tells the browser that the server answered without its data.
        $this->withUnreachableDatabase(function () use ($resident) {
            $this->withSession(['resident_id' => $resident->id])
                ->getJson('/resident/notifications')
                ->assertStatus(503)
                ->assertHeader(OfflineDegraded::HEADER, OfflineDegraded::UNAVAILABLE)
                ->assertJsonPath('error', OfflineDegraded::UNAVAILABLE)
                ->assertJsonPath('identity', OfflineIdentity::UNRESOLVED);
        });
    }

    public function test_degraded_response_neither_issues_nor_revokes_an_offline_scope(): void
    {
        $barangay = $this->createBarangay('Outage Barangay', 'OUT');
        $resident = $this->createResident($barangay, $this->createPurok($barangay));

        // The service worker reads an absent scope header as "say nothing"; an empty
        // value would delete this account's saved pages.
        $this->withUnreachableDatabase(function () use ($resident) {
            $this->withSession(['resident_id' => $resident->id])
                ->get('/resident/notifications')
                ->assertStatus(503)
                ->assertHeaderMissing('X-KaBarangay-Offline-Scope');
        });
    }

    public function test_probe_endpoint_keeps_communicating_unresolved_identity_during_the_outage(): void
    {
        $barangay = $this->createBarangay('Outage Barangay', 'OUT');
        $resident = $this->createResident($barangay, $this->createPurok($barangay));

        // The reachability probe must still answer 200 with an identity, because the
        // client distinguishes a dead server from a live server with unreadable data.
        $this->withUnreachableDatabase(function () use ($resident) {
            $this->withSession(['resident_id' => $resident->id])
                ->getJson('/offline/session')
                ->assertOk()
                ->assertJsonPath('identity', OfflineIdentity::UNRESOLVED)
                ->assertJsonPath('scope', null)
                ->assertJsonPath('owner', null)
                ->assertHeaderMissing(OfflineDegraded::HEADER);
        });
    }

    public function test_degraded_response_leaks_no_query_or_connection_details(): void
    {
        $barangay = $this->createBarangay('Outage Barangay', 'OUT');
        $resident = $this->createResident($barangay, $this->createPurok($barangay), ['mobile' => '09170000001']);
        $host = (string) config('database.connections.'.config('database.default').'.host');

        $this->withUnreachableDatabase(function () use ($resident, $host) {
            $content = $this->withSession(['resident_id' => $resident->id])
                ->get('/resident/notifications')
                ->assertStatus(503)
                ->getContent();

            foreach (array_filter([$host, self::UNREACHABLE_HOST, 'SQLSTATE', 'residents', '09170000001', 'stack trace', 'Stacktrace']) as $secret) {
                $this->assertStringNotContainsString($secret, $content);
            }
            $this->assertStringContainsString('temporarily unavailable', $content);
        });
    }

    public function test_a_genuine_database_defect_stays_a_server_error_and_is_not_offloaded_to_cache(): void
    {
        $barangay = $this->createBarangay('Outage Barangay', 'OUT');
        $resident = $this->createResident($barangay, $this->createPurok($barangay));

        // A missing relation is a bug in this application, not an outage. Serving a saved
        // copy for it would hide the defect and present stale data as current.
        $this->withFailedConnection(static function () {
            throw new QueryException(
                'pgsql',
                'select * from "residents"',
                [],
                new PDOException('SQLSTATE[42P01] base table or view not found: relation "residents" does not exist'),
            );
        }, function () use ($resident) {
            $this->withSession(['resident_id' => $resident->id])
                ->get('/resident/notifications')
                ->assertStatus(500)
                ->assertHeaderMissing(OfflineDegraded::HEADER);
        });
    }

    public function test_an_inertia_visit_receives_the_degraded_contract_instead_of_a_rendered_page(): void
    {
        $this->withoutVite();
        $barangay = $this->createBarangay('Outage Barangay', 'OUT');
        $resident = $this->createResident($barangay, $this->createPurok($barangay));

        // Inertia sends X-Inertia with an Accept header that prefers HTML, so
        // expectsJson() alone would hand this client a Blade page it cannot parse.
        // The version must match or Inertia short-circuits the visit with a 409 reload.
        $version = app(HandleInertiaRequests::class)->version(
            Request::create('/resident/notifications', 'GET'),
        );

        $this->withUnreachableDatabase(function () use ($resident, $version) {
            $this->withSession(['resident_id' => $resident->id])
                ->withHeaders([
                    'X-Inertia' => 'true',
                    'X-Inertia-Version' => $version,
                    'X-Inertia-Partial-Component' => 'Resident/Notifications',
                ])
                ->get('/resident/notifications')
                ->assertStatus(503)
                ->assertHeader(OfflineDegraded::HEADER, OfflineDegraded::UNAVAILABLE)
                ->assertHeaderMissing('X-KaBarangay-Offline-Scope')
                ->assertJsonPath('error', OfflineDegraded::UNAVAILABLE)
                ->assertJsonPath('identity', OfflineIdentity::UNRESOLVED);
        });
    }

    public function test_an_unreachable_database_never_upgrades_a_guest_to_a_resident_page(): void
    {
        $this->createBarangay('Outage Barangay', 'OUT');

        // Degradation is an availability answer, not an authorization one: a visitor
        // without a resident session still leaves through the normal login redirect.
        $this->withUnreachableDatabase(function () {
            $this->get('/resident/notifications')
                ->assertRedirect(route('resident.login'))
                ->assertHeaderMissing(OfflineDegraded::HEADER);
        });
    }

    public function test_a_guest_probe_says_the_same_thing_with_and_without_the_database(): void
    {
        $this->createBarangay('Outage Barangay', 'OUT');

        $healthy = $this->getJson('/offline/session')->assertOk()->json();
        $this->assertSame(OfflineIdentity::ANONYMOUS, $healthy['identity']);
        $this->assertNull($healthy['scope']);
        $this->assertNull($healthy['owner']);
        $this->assertNotEmpty($healthy['csrfToken']);

        // An unauthenticated probe never reads the account tables, so it cannot report
        // them as unreadable either. The response is identical while PostgreSQL is down,
        // which is why the client may treat anonymous as reachable but never as proven.
        $this->withUnreachableDatabase(function () {
            $degraded = $this->getJson('/offline/session')
                ->assertOk()
                ->assertHeaderMissing(OfflineDegraded::HEADER)
                ->json();

            $this->assertSame(OfflineIdentity::ANONYMOUS, $degraded['identity']);
            $this->assertNull($degraded['scope']);
            $this->assertNull($degraded['owner']);
        });
    }

    public function test_the_degraded_page_keeps_its_own_copy_when_debug_is_enabled(): void
    {
        $this->withoutVite();
        $barangay = $this->createBarangay('Outage Barangay', 'OUT');
        $resident = $this->createResident($barangay, $this->createPurok($barangay));

        // A developer seeing the outage should still get the honest unavailable page,
        // not a stack trace the browser would misread as a broken application.
        config(['app.debug' => true]);

        $this->withUnreachableDatabase(function () use ($resident) {
            $content = $this->withSession(['resident_id' => $resident->id])
                ->get('/resident/notifications')
                ->assertStatus(503)
                ->assertHeader(OfflineDegraded::HEADER, OfflineDegraded::UNAVAILABLE)
                ->getContent();

            $this->assertStringContainsString('temporarily unavailable', $content);
            $this->assertStringContainsString('/resident/notifications', $content);
            $this->assertStringNotContainsString('SQLSTATE', $content);
            $this->assertStringNotContainsString(self::UNREACHABLE_HOST, $content);
        });
    }

    /** A guarded route whose only job is to prove the gateway refused before executing it. */
    private function protectedRoute(string $kind): void
    {
        $middleware = $kind === 'resident' ? 'resident.auth' : ['auth', 'role:admin,clerk'];
        $method = $kind === 'resident' ? 'get' : 'post';
        Route::middleware(['web', ... (array) $middleware])->{$method}('/kb-outage-probe', fn () => self::PROTECTED_MARKER);
    }
}
