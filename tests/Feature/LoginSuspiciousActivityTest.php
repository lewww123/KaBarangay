<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\SuspiciousLoginAlert;
use App\Support\LoginAnomalyDetector;
use App\Support\LoginAnomalyResult;
use App\Support\LoginAttemptRecorder;
use App\Support\LoginIdentifier;
use App\Support\SuspiciousAlertRecorder;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;
use Throwable;

/**
 * Batch E5.4: suspicious-login detection (E5.1) and alert writing (E5.2)
 * behavior, plus their integration with LoginAttemptRecorder (E5.2).
 *
 * Detection counts only 'failed' attempts within a rolling 30-minute window,
 * grouped by channel + identifier_hash (+ barangay when known). Alerts are
 * deduplicated by an atomic insert-or-ignore against the unique dedup_key.
 */
class LoginSuspiciousActivityTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private const ALERT_TYPE = 'failed_login_threshold';

    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
    }

    private function login(string $identifier, string $password = 'wrong-password')
    {
        return $this->post(route('resident.login.process'), [
            'barangay_id' => $this->barangay->id,
            'identifier' => $identifier,
            'password' => $password,
        ]);
    }

    public function test_five_failed_logins_write_exactly_one_threshold_alert(): void
    {
        $purok = $this->createPurok($this->barangay);
        $resident = $this->createResident($this->barangay, $purok, [
            'resident_number' => 'SUS-000001',
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->login('SUS-000001')->assertRedirect();
        }

        $alerts = DB::table('suspicious_login_alerts')->get();

        $this->assertCount(1, $alerts);

        $alert = $alerts->first();

        $this->assertSame('resident', $alert->channel);
        $this->assertSame(self::ALERT_TYPE, $alert->alert_type);
        $this->assertSame($this->barangay->id, (int) $alert->barangay_id);
        $this->assertSame(5, (int) $alert->attempt_count);
        $this->assertSame(1800, (int) $alert->window_seconds);
        $this->assertSame(
            LoginIdentifier::fingerprint('SUS-000001'),
            $alert->identifier_hash
        );
    }

    public function test_duplicate_threshold_crossings_deduplicate_to_one_alert(): void
    {
        $purok = $this->createPurok($this->barangay);
        $this->createResident($this->barangay, $purok, [
            'resident_number' => 'SUS-DEDUPE',
        ]);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->login('SUS-DEDUPE')->assertRedirect();
        }

        $this->assertSame(
            1,
            DB::table('suspicious_login_alerts')->count(),
            'All crossings in one bucket must collapse to a single alert.'
        );
    }

    public function test_alerts_below_threshold_are_not_written(): void
    {
        $purok = $this->createPurok($this->barangay);
        $this->createResident($this->barangay, $purok, [
            'resident_number' => 'SUS-BELOW',
        ]);

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->login('SUS-BELOW')->assertRedirect();
        }

        $this->assertSame(0, DB::table('suspicious_login_alerts')->count());
    }

    public function test_success_and_rate_limited_rows_are_excluded_from_counting(): void
    {
        $purok = $this->createPurok($this->barangay);
        $this->createResident($this->barangay, $purok, [
            'resident_number' => 'SUS-EXCLUDE',
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->login('SUS-EXCLUDE')->assertRedirect();
        }

        // E4's identifier limiter shares the 5/10-minute threshold and is
        // checked before the request is handled, so a rate_limited row only
        // appears once the limit is already reached: the sixth request is
        // rejected by the middleware before the controller runs.
        $this->login('SUS-EXCLUDE')->assertRedirect();

        $failed = DB::table('login_attempts')
            ->where('status', 'failed')
            ->where('channel', 'resident')
            ->count();
        $rateLimited = DB::table('login_attempts')
            ->where('status', 'rate_limited')
            ->where('channel', 'resident')
            ->count();

        $this->assertSame(5, $failed);
        $this->assertSame(1, $rateLimited);

        // Only the five 'failed' rows are counted by the detector; the
        // rate_limited row is excluded, so the threshold alert reflects five.
        $this->assertSame(1, DB::table('suspicious_login_alerts')->count());
        $this->assertSame(
            5,
            (int) DB::table('suspicious_login_alerts')->value('attempt_count')
        );
    }

    public function test_detector_counts_only_failed_within_the_rolling_window(): void
    {
        $hash = LoginIdentifier::fingerprint('WINDOW-TEST');

        $this->seedAttempt($hash, 'failed', now()->subMinutes(29), $this->barangay->id);
        $this->seedAttempt($hash, 'failed', now()->subMinutes(28), $this->barangay->id);
        $this->seedAttempt($hash, 'failed', now()->subMinutes(31), $this->barangay->id);
        $this->seedAttempt($hash, 'success', now()->subMinutes(5), $this->barangay->id);
        $this->seedAttempt($hash, 'rate_limited', now()->subMinutes(4), $this->barangay->id);

        $result = LoginAnomalyDetector::detect('resident', $hash, $this->barangay->id);

        $this->assertNotNull($result);
        $this->assertSame(2, $result->count);
        $this->assertFalse($result->thresholdReached);
    }

    public function test_detector_returns_null_for_a_null_identifier_hash(): void
    {
        $this->assertNull(
            LoginAnomalyDetector::detect('resident', null, $this->barangay->id)
        );
    }

    public function test_unknown_identifier_failures_still_form_a_threshold_alert(): void
    {
        // Approved E5 contract: detection groups by identifier_hash + channel
        // (+ barangay when known) and does NOT require a resolved resident. An
        // unknown identifier submitted against an active barangay is recorded
        // as a failed attempt carrying that barangay and the identifier's HMAC,
        // so five of them legitimately reach the threshold. This is a
        // brute-force signal, not a defect, and must not be suppressed.
        $this->createPurok($this->barangay);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->login('NO-SUCH-RESIDENT')->assertRedirect();
        }

        $alerts = DB::table('suspicious_login_alerts')->get();

        $this->assertCount(1, $alerts);

        $alert = $alerts->first();

        $this->assertSame('resident', $alert->channel);
        $this->assertSame(self::ALERT_TYPE, $alert->alert_type);
        $this->assertSame($this->barangay->id, (int) $alert->barangay_id);
        $this->assertSame(5, (int) $alert->attempt_count);
        $this->assertSame(
            LoginIdentifier::fingerprint('NO-SUCH-RESIDENT'),
            $alert->identifier_hash
        );
    }

    public function test_detection_groups_by_barangay(): void
    {
        $hash = LoginIdentifier::fingerprint('GROUPED');

        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');

        for ($i = 0; $i < 3; $i++) {
            $this->seedAttempt($hash, 'failed', now()->subMinutes($i), $this->barangay->id);
        }

        for ($i = 0; $i < 4; $i++) {
            $this->seedAttempt($hash, 'failed', now()->subMinutes($i), $otherBarangay->id);
        }

        $first = LoginAnomalyDetector::detect('resident', $hash, $this->barangay->id);
        $second = LoginAnomalyDetector::detect('resident', $hash, $otherBarangay->id);

        $this->assertSame(3, $first->count);
        $this->assertFalse($first->thresholdReached);
        $this->assertSame(4, $second->count);
        $this->assertFalse($second->thresholdReached);

        $combined = LoginAnomalyDetector::detect('resident', $hash, null);
        $this->assertSame(7, $combined->count);
    }

    public function test_dedup_key_is_stable_within_a_bucket_and_changes_across_buckets(): void
    {
        $hash = LoginIdentifier::fingerprint('BUCKET-TEST');

        $anchor = Carbon::create(2026, 10, 20, 12, 0, 0);

        $a = LoginAnomalyDetector::dedupKey('resident', $hash, $this->barangay->id, $anchor);
        $b = LoginAnomalyDetector::dedupKey('resident', $hash, $this->barangay->id, $anchor->copy()->addMinutes(10));
        $c = LoginAnomalyDetector::dedupKey('resident', $hash, $this->barangay->id, $anchor->copy()->addMinutes(40));

        $this->assertSame($a, $b, 'Same 30-minute bucket must share a dedup key.');
        $this->assertNotSame($a, $c, 'A different bucket must produce a different dedup key.');
    }

    public function test_alert_model_global_scope_hides_rows_from_other_barangays(): void
    {
        $purok = $this->createPurok($this->barangay);
        $this->createResident($this->barangay, $purok, [
            'resident_number' => 'SUS-SCOPE',
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->login('SUS-SCOPE')->assertRedirect();
        }

        $this->assertSame(1, DB::table('suspicious_login_alerts')->count());

        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $otherStaff = $this->createStaff($otherBarangay, 'admin');

        $this->actingAs($otherStaff);

        $this->assertSame(
            0,
            SuspiciousLoginAlert::query()->count(),
            'A different barangay must not see the alert.'
        );
    }

    public function test_unauthenticated_reads_return_no_alerts(): void
    {
        $purok = $this->createPurok($this->barangay);
        $this->createResident($this->barangay, $purok, [
            'resident_number' => 'SUS-ANON',
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->login('SUS-ANON')->assertRedirect();
        }

        $this->assertSame(
            0,
            SuspiciousLoginAlert::query()->count(),
            'The null-blocking scope must hide alerts from unauthenticated reads.'
        );
    }

    public function test_alert_writer_failure_does_not_change_the_login_outcome(): void
    {
        $purok = $this->createPurok($this->barangay);
        $resident = $this->createResident($this->barangay, $purok, [
            'resident_number' => 'SUS-FAILOPEN',
        ]);

        // A failing alert writer must be reported, not swallowed and not
        // allowed to escape into the authentication flow. The writer is
        // final/static and cannot be mocked, so the only deterministic way to
        // fail it without a persistent database is to remove its table inside
        // this isolated in-memory SQLite test. login_attempts stays intact.
        // Swap in a handler that asserts the writer failure is reported while
        // delegating every other method (including render) to the real handler,
        // so an escape is rendered faithfully instead of as a synthetic 200.
        $realHandler = $this->app->make(ExceptionHandler::class);

        $handler = Mockery::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')
            ->atLeast()->once()
            ->with(Mockery::type(Throwable::class));
        $handler->shouldReceive('render')
            ->andReturnUsing(fn ($request, Throwable $e) => $realHandler->render($request, $e));
        $handler->shouldReceive('shouldReport')
            ->andReturnUsing(fn (Throwable $e) => $realHandler->shouldReport($e));
        $handler->shouldReceive('renderForConsole')
            ->andReturnUsing(fn ($output, Throwable $e) => $realHandler->renderForConsole($output, $e));
        $this->app->instance(ExceptionHandler::class, $handler);

        Schema::drop('suspicious_login_alerts');

        // Only one live failed request is issued so the shared E4 limiter
        // (5 attempts / 10 minutes) still has budget for the valid login that
        // follows. The other four failures are seeded directly so the live
        // request crosses the threshold and exercises the failing alert-writer
        // path.
        $failedHash = LoginIdentifier::fingerprint('SUS-FAILOPEN');

        for ($seed = 0; $seed < 4; $seed++) {
            $this->seedAttempt(
                $failedHash,
                LoginAttemptRecorder::STATUS_FAILED,
                now()->subSeconds(30 + $seed),
                $this->barangay->id,
            );
        }

        $this->login('SUS-FAILOPEN', 'wrong-password')
            ->assertSessionHasErrors(['login' => 'Invalid resident credentials.']);

        // The five failed attempts are still recorded even though the alert
        // writer failed on every threshold-crossing evaluation.
        $this->assertSame(
            5,
            DB::table('login_attempts')
                ->where('status', 'failed')
                ->where('channel', 'resident')
                ->where('barangay_id', $this->barangay->id)
                ->count()
        );

        // A successful authentication still completes normally: the writer
        // failure never changed the login result.
        $this->login('SUS-FAILOPEN', 'password')
            ->assertRedirect(route('resident.dashboard'));

        $this->assertTrue((bool) $this->app['session']->get('resident_logged_in'));
        $this->assertSame(
            $resident->id,
            (int) $this->app['session']->get('resident_id')
        );
    }

    public function test_null_barangay_alert_is_hidden_from_a_barangay_admin(): void
    {
        $purok = $this->createPurok($this->barangay);
        $this->createResident($this->barangay, $purok, [
            'resident_number' => 'SUS-NULL-BRGY',
        ]);

        $evaluatedAt = now();

        // Build a null-barangay detection result and persist it through the
        // real trusted writer, exercising its global-scope bypass.
        $nullResult = new LoginAnomalyResult(
            channel: 'resident',
            identifierHash: LoginIdentifier::fingerprint('NULL-BRGY-IDENTITY'),
            barangayId: null,
            count: 5,
            thresholdReached: true,
            windowSeconds: 1800,
            evaluatedAt: $evaluatedAt,
            since: $evaluatedAt->copy()->subMinutes(30),
            firstAttemptAt: $evaluatedAt->copy()->subMinutes(5),
            lastAttemptAt: $evaluatedAt,
            ipAddress: '203.0.113.10',
        );

        SuspiciousAlertRecorder::record($nullResult);

        // Raw query (bypasses the model scope) proves the trusted write path
        // persisted the unresolved alert.
        $this->assertSame(
            1,
            DB::table('suspicious_login_alerts')->whereNull('barangay_id')->count()
        );

        // Also create a barangay-bound alert through the normal flow so the
        // admin has a legitimate alert to see.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->login('SUS-NULL-BRGY')->assertRedirect();
        }

        $this->assertSame(
            1,
            DB::table('suspicious_login_alerts')
                ->where('barangay_id', $this->barangay->id)
                ->count()
        );

        // Unauthenticated reads see nothing (null-blocking scope).
        $this->assertSame(0, SuspiciousLoginAlert::query()->count());

        $admin = $this->createStaff($this->barangay, 'admin');
        $this->actingAs($admin);

        // The admin sees exactly the barangay-bound alert, never the null one.
        $this->assertSame(1, SuspiciousLoginAlert::query()->count());
        $this->assertSame(
            0,
            SuspiciousLoginAlert::query()->whereNull('barangay_id')->count(),
            'A null-barangay alert must never be visible to a barangay admin.'
        );
    }

    private function seedAttempt(string $hash, string $status, CarbonInterface $at, ?int $barangayId = null): void
    {
        DB::table('login_attempts')->insert([
            'barangay_id' => $barangayId,
            'user_id' => null,
            'resident_id' => null,
            'actor_type' => 'resident',
            'channel' => 'resident',
            'identifier_hash' => $hash,
            'status' => $status,
            'ip_address' => '203.0.113.10',
            'attempted_at' => $at,
        ]);
    }
}
