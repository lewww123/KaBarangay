<?php

namespace Tests\Feature;

use App\Models\ResidentRegistration;
use App\Models\User;
use App\Support\OfflineDegraded;
use App\Support\OfflineIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\Concerns\SimulatesUnreachableDatabase;
use Tests\TestCase;

/**
 * The Supabase host can be unreachable while this application server still answers.
 * An unreadable account state must never destroy an already-generated response, and it
 * must never be mistaken for a logout, an account switch or a newly granted identity.
 */
class OfflineIdentityDegradationTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase, SimulatesUnreachableDatabase;

    // Store accepts only 40 alphanumeric session ids.
    private const SHARED_SESSION_ID = 'sharedsessionaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const ROTATED_SESSION_ID = 'rotatedsessionbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function test_resident_response_is_still_generated_when_the_database_is_unreachable(): void
    {
        $barangay = $this->createBarangay('Degraded Barangay', 'DEG');
        $resident = $this->createResident($barangay, $this->createPurok($barangay));

        $this->withUnreachableDatabase(function () use ($resident) {
            $response = $this->withSession(['resident_id' => $resident->id])
                ->get('/offline/session')
                ->assertOk()
                ->assertJsonPath('scope', null)
                ->assertJsonPath('owner', null)
                ->assertJsonPath('identity', OfflineIdentity::UNRESOLVED);

            $this->assertNotEmpty($response->json('csrfToken'));
        });
    }

    public function test_security_headers_survive_degraded_identity_resolution(): void
    {
        $this->withoutVite();
        $barangay = $this->createBarangay('Degraded Barangay', 'DEG');
        $resident = $this->createResident($barangay, $this->createPurok($barangay));

        $this->withUnreachableDatabase(fn () => $this->withSession(['resident_id' => $resident->id])
            ->get('/terms-and-conditions')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'same-origin')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private'));
    }

    public function test_degraded_identity_never_signals_a_scope_revocation(): void
    {
        $barangay = $this->createBarangay('Degraded Barangay', 'DEG');
        $resident = $this->createResident($barangay, $this->createPurok($barangay));

        // A verified identity advertises its scope in the same response, so a later real
        // logout can still revoke it. Feature tests start a new session per request, so
        // only one response's own scope is comparable, never two responses' scopes.
        $verified = $this->withSession(['resident_id' => $resident->id])->get('/offline/session');
        $this->assertNotNull($verified->json('scope'));
        $verified->assertHeader('X-KaBarangay-Offline-Scope', $verified->json('scope'));

        $this->withUnreachableDatabase(fn () => $this->withSession(['resident_id' => $resident->id])
            ->get('/offline/session')
            ->assertOk()
            ->assertHeaderMissing('X-KaBarangay-Offline-Scope'));
    }

    public function test_no_shared_degraded_cache_scope_is_ever_issued(): void
    {
        $barangay = $this->createBarangay('Degraded Barangay', 'DEG');
        $resident = $this->createResident($barangay, $this->createPurok($barangay));

        $this->withUnreachableDatabase(function () use ($resident) {
            $degraded = $this->withSession(['resident_id' => $resident->id])->get('/offline/session')->json('scope');
            $guest = $this->get('/offline/session')->json('scope');

            $this->assertNull($degraded);
            $this->assertNull($guest);
            $this->assertNotSame('offline_degraded', $degraded);
            $this->assertNotSame('offline_degraded', $guest);
        });
    }

    public function test_degraded_identity_never_falls_back_to_session_values_for_authorization(): void
    {
        $barangay = $this->createBarangay('Degraded Barangay', 'DEG');
        $resident = $this->createResident($barangay, $this->createPurok($barangay));
        $registration = ResidentRegistration::findOrFail($resident->resident_registration_id);
        $registration->update(['status' => 'pending']);

        // An unapproved registration has no scope while the database answers, and still none while it does not.
        $this->withSession(['resident_id' => $resident->id, 'resident_barangay_id' => $barangay->id])
            ->get('/offline/session')
            ->assertOk()
            ->assertJsonPath('scope', null)
            ->assertJsonPath('identity', OfflineIdentity::ANONYMOUS);

        $this->withUnreachableDatabase(fn () => $this->withSession([
            'resident_id' => $resident->id,
            'resident_barangay_id' => $barangay->id,
        ])->get('/offline/session')
            ->assertOk()
            ->assertJsonPath('scope', null)
            ->assertJsonPath('owner', null)
            ->assertJsonPath('identity', OfflineIdentity::UNRESOLVED));
    }

    public function test_deactivated_staff_cannot_obtain_a_newly_validated_scope_while_degraded(): void
    {
        $inactive = User::factory()->create(['status' => 'inactive']);
        $this->actingAs($inactive)->get('/offline/session')
            ->assertJsonPath('scope', null)
            ->assertJsonPath('identity', OfflineIdentity::ANONYMOUS);

        $this->withUnreachableDatabase(fn () => $this->actingAs($inactive)->get('/offline/session')
            ->assertOk()
            ->assertJsonPath('scope', null)
            ->assertJsonPath('identity', OfflineIdentity::ANONYMOUS)
            ->assertHeader('X-KaBarangay-Offline-Scope', ''));
    }

    public function test_staff_authorized_earlier_in_the_request_still_receives_a_verified_scope(): void
    {
        $staff = User::factory()->create(['status' => 'active']);
        $this->actingAs($staff);

        $this->withUnreachableDatabase(function () {
            $response = $this->get('/offline/session')
                ->assertOk()
                ->assertJsonPath('identity', OfflineIdentity::VERIFIED);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $response->json('scope'));
            $response->assertHeader('X-KaBarangay-Offline-Scope', $response->json('scope'));
        });
    }

    public function test_degraded_session_response_leaks_no_database_information(): void
    {
        $barangay = $this->createBarangay('Degraded Barangay', 'DEG');
        $resident = $this->createResident($barangay, $this->createPurok($barangay));
        $host = (string) config('database.connections.'.config('database.default').'.host');

        $this->withUnreachableDatabase(function () use ($resident, $host) {
            $response = $this->withSession(['resident_id' => $resident->id])
                ->get('/offline/session')
                ->assertOk()
                ->assertJsonPath('scope', null)
                ->assertJsonPath('owner', null);
            $content = $response->getContent();

            // The whole contract is four keys: nothing about the failing query may ride along.
            $this->assertSame(['scope', 'owner', 'identity', 'csrfToken'], array_keys(json_decode($content, true)));
            foreach (array_filter([$host, self::UNREACHABLE_HOST, 'SQLSTATE', 'pgsql', 'pooler', '09170000001']) as $secret) {
                $this->assertStringNotContainsString($secret, $content);
            }
        });
    }

    public function test_degraded_identity_logs_only_that_account_state_was_unreadable(): void
    {
        $source = (string) file_get_contents(app_path('Support/OfflineIdentity.php'));
        $this->assertSame(1, preg_match('/Log::warning\((?<call>.*?)\]\);/s', $source, $matches));

        // A QueryException message repeats the SQL, the bindings and the connection host; none of it may be recorded.
        foreach (['getMessage', 'getBindings', 'getSql', 'getDriverName', '$resident', 'session()'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $matches['call']);
        }
        $this->assertStringContainsString('get_class($exception)', $matches['call']);
    }

    public function test_offline_certificate_context_is_not_unlocked_while_the_database_is_unreachable(): void
    {
        $barangay = $this->createBarangay('Degraded Barangay', 'DEG');
        $resident = $this->createResident($barangay, $this->createPurok($barangay));

        // The gateway answers the outage before OfflineCertificateController can run, so the
        // stored encryption key is never derived. 503 instead of 500 is the same refusal,
        // reported as a degraded state the browser can act on.
        $this->withUnreachableDatabase(function () use ($resident) {
            $response = $this->withSession(['resident_id' => $resident->id])
                ->get('/resident/certificates/offline-context')
                ->assertStatus(503)
                ->assertHeader(OfflineDegraded::HEADER, OfflineDegraded::UNAVAILABLE);
            $this->assertStringNotContainsString('owner', $response->getContent());
            $this->assertStringNotContainsString('key', $response->getContent());
        });
    }

    public function test_a_blip_revokes_nothing_and_the_scope_still_follows_account_and_session(): void
    {
        $barangay = $this->createBarangay('Degraded Barangay', 'DEG');
        $purok = $this->createPurok($barangay);
        $first = $this->createResident($barangay, $purok, ['mobile' => '09170000001']);
        $second = $this->createResident($barangay, $purok, ['mobile' => '09170000002']);
        $asFirst = ['resident_id' => $first->id, 'resident_barangay_id' => $barangay->id];
        $asSecond = ['resident_id' => $second->id, 'resident_barangay_id' => $barangay->id];

        // The scope is the account and its session id, and nothing random: a fixed session
        // therefore keeps one partition per account and separates two accounts in it.
        $firstScope = $this->scopeFor(self::SHARED_SESSION_ID, $asFirst);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $firstScope);
        $this->assertSame($firstScope, $this->scopeFor(self::SHARED_SESSION_ID, $asFirst));
        $this->assertNotSame($firstScope, $this->scopeFor(self::SHARED_SESSION_ID, $asSecond));
        $this->assertNotSame($firstScope, $this->scopeFor(self::ROTATED_SESSION_ID, $asFirst));

        $verified = $this->withSession($asFirst)->get('/offline/session')->assertOk();
        $scope = $verified->json('scope');
        $this->assertNotNull($scope);

        // Keep that very session id so only the identity resolution can move the scope.
        $this->withCookie(config('session.cookie'), $this->app['session']->getId());
        $this->assertSame($scope, $this->get('/offline/session')->json('scope'));

        // The blip issues no scope and, more importantly, sends no revocation for this account.
        $this->withUnreachableDatabase(fn () => $this->get('/offline/session')->assertOk()
            ->assertJsonPath('identity', OfflineIdentity::UNRESOLVED)
            ->assertJsonPath('scope', null)
            ->assertHeaderMissing('X-KaBarangay-Offline-Scope'));

        // Recovery re-issues the very same partition, so nothing cached was ever orphaned.
        $this->assertSame($scope, $this->get('/offline/session')->json('scope'));

        // A rotated session id is a new partition even for the same account.
        $this->withCookie(config('session.cookie'), self::ROTATED_SESSION_ID);
        $this->assertNotSame($scope, $this->get('/offline/session')->json('scope'));
    }

    public function test_generic_throwable_with_database_loss_marker_fails_closed_as_unresolved(): void
    {
        $barangay = $this->createBarangay('Degraded Barangay', 'DEG');
        $resident = $this->createResident($barangay, $this->createPurok($barangay));

        $this->withFailedConnection(static function () {
            throw new \RuntimeException('could not translate host name "'.static::UNREACHABLE_HOST.'" to address');
        }, function () use ($resident) {
            $this->withSession(['resident_id' => $resident->id])
                ->get('/offline/session')
                ->assertOk()
                ->assertJsonPath('identity', OfflineIdentity::UNRESOLVED)
                ->assertJsonPath('scope', null)
                ->assertHeaderMissing('X-KaBarangay-Offline-Scope');
        });
    }

    public function test_security_headers_preserve_response_when_identity_resolution_fails_closed(): void
    {
        $this->withoutVite();
        $barangay = $this->createBarangay('Degraded Barangay', 'DEG');
        $resident = $this->createResident($barangay, $this->createPurok($barangay));

        $this->withFailedConnection(static function () {
            throw new \RuntimeException('unexpected identity resolution failure');
        }, function () use ($resident) {
            $this->withSession(['resident_id' => $resident->id])
                ->get('/terms-and-conditions')
                ->assertOk()
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
                ->assertHeaderMissing('X-KaBarangay-Offline-Scope');
        });
    }

    /**
     * Resolve the scope for a session id we control, because feature requests rotate it.
     * Store silently replaces any id that is not exactly 40 alphanumeric characters.
     */
    private function scopeFor(string $sessionId, array $sessionData): ?string
    {
        $session = new Store('array', new ArraySessionHandler(5), $sessionId);

        foreach ($sessionData as $key => $value) {
            $session->put($key, $value);
        }

        $request = Request::create('/offline/session');
        $request->setLaravelSession($session);
        $this->app->instance('request', $request);

        return OfflineIdentity::scope($request);
    }
}
