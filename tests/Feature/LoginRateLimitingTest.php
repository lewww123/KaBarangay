<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Support\LoginIdentifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class LoginRateLimitingTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private const MESSAGE = 'Too many login attempts. Please wait before trying again.';

    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
    }

    private function staffSession(): array
    {
        return [
            'selected_barangay_id' => $this->barangay->id,
            'login_department' => 'admin',
        ];
    }

    private function departmentAttempt(string $username, string $password = 'wrong-password'): \Illuminate\Testing\TestResponse
    {
        return $this
            ->withSession(['selected_barangay_id' => $this->barangay->id])
            ->post('/department-login', [
                'department' => 'admin',
                'username' => $username,
                'password' => $password,
            ]);
    }

    private function staffAttempt(string $staffId, string $password = 'wrong-password'): \Illuminate\Testing\TestResponse
    {
        return $this
            ->withSession($this->staffSession())
            ->post('/staff-login', [
                'staff_id' => $staffId,
                'full_name' => 'Admin User',
                'password' => $password,
            ]);
    }

    private function residentAttempt(string $identifier, string $password = 'wrong-password'): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('resident.login.process'), [
            'barangay_id' => $this->barangay->id,
            'identifier' => $identifier,
            'password' => $password,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Department login
    |--------------------------------------------------------------------------
    */

    public function test_department_identifier_limit_rejects_the_sixth_attempt_with_redirect_and_errors(): void
    {
        $this->createStaff($this->barangay, 'admin', [
            'staff_id' => 'ADM-001',
            'name' => 'Admin User',
            'email' => 'admin@example.test',
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->departmentAttempt('admin@example.test')->assertRedirect();
        }

        $response = $this->departmentAttempt('admin@example.test');

        $response->assertRedirect();
        $response->assertSessionHasErrors(['login' => self::MESSAGE]);

        $rows = DB::table('login_attempts')
            ->where('status', 'rate_limited')
            ->get();

        $this->assertCount(1, $rows);

        $row = $rows->first();

        $this->assertSame('staff', $row->actor_type);
        $this->assertSame('department', $row->channel);
        $this->assertSame(
            LoginIdentifier::fingerprint('admin@example.test'),
            $row->identifier_hash
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Staff login
    |--------------------------------------------------------------------------
    */

    public function test_staff_identifier_limit_rejects_the_sixth_attempt_and_records_rate_limited(): void
    {
        $this->createStaff($this->barangay, 'admin', [
            'staff_id' => 'ADM-001',
            'name' => 'Admin User',
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->staffAttempt('ADM-001')->assertRedirect();
        }

        $response = $this->staffAttempt('ADM-001');

        $response->assertRedirect();
        $response->assertSessionHasErrors(['login' => self::MESSAGE]);

        $rows = DB::table('login_attempts')
            ->where('status', 'rate_limited')
            ->get();

        $this->assertCount(1, $rows);

        $row = $rows->first();

        $this->assertSame('staff', $row->actor_type);
        $this->assertSame('staff', $row->channel);
        $this->assertSame(
            LoginIdentifier::fingerprint('ADM-001'),
            $row->identifier_hash
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Resident login
    |--------------------------------------------------------------------------
    */

    public function test_resident_identifier_limit_records_rate_limited_with_null_barangay_for_bogus_barangay(): void
    {
        $purok = $this->createPurok($this->barangay);
        $this->createResident($this->barangay, $purok);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('resident.login.process'), [
                'barangay_id' => 987654,
                'identifier' => 'BSJ-RES-000001',
                'password' => 'wrong-password',
            ])->assertRedirect();
        }

        $response = $this->post(route('resident.login.process'), [
            'barangay_id' => 987654,
            'identifier' => 'BSJ-RES-000001',
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors(['login' => self::MESSAGE]);

        $rows = DB::table('login_attempts')
            ->where('status', 'rate_limited')
            ->get();

        $this->assertCount(1, $rows);

        $row = $rows->first();

        $this->assertSame('resident', $row->actor_type);
        $this->assertSame('resident', $row->channel);
        $this->assertNull($row->barangay_id);
    }

    /*
    |--------------------------------------------------------------------------
    | JSON response format
    |--------------------------------------------------------------------------
    */

    public function test_json_requests_receive_429_with_the_same_message(): void
    {
        $this->createStaff($this->barangay, 'admin', [
            'staff_id' => 'ADM-001',
            'name' => 'Admin User',
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/staff-login', [
                'staff_id' => 'ADM-001',
                'full_name' => 'Admin User',
                'password' => 'wrong-password',
            ]);
        }

        $response = $this->postJson('/staff-login', [
            'staff_id' => 'ADM-001',
            'full_name' => 'Admin User',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(429);
        $response->assertJson(['message' => self::MESSAGE]);
    }

    /*
    |--------------------------------------------------------------------------
    | Empty identifiers
    |--------------------------------------------------------------------------
    */

    public function test_empty_identifier_skips_identifier_limit_and_records_null_hash(): void
    {
        $fingerprint = LoginIdentifier::fingerprint('   ');
        $this->assertNull($fingerprint);

        $identifierKey = 'login:staff:uid:'.$fingerprint;

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->staffAttempt('')->assertRedirect();
        }

        $this->assertSame(
            0,
            RateLimiter::attempts(
                LoginIdentifier::middlewareKey('login-staff', $identifierKey)
            ),
            'No identifier bucket should be created for empty identifiers.'
        );

        $ipKey = LoginIdentifier::middlewareKey(
            'login-staff',
            LoginIdentifier::ipKey('staff', LoginIdentifier::LOCAL_ADDRESS)
        );

        $this->assertSame(
            6,
            RateLimiter::attempts($ipKey),
            'The per-IP limit must still count attempts with empty identifiers.'
        );

        \App\Support\LoginAttemptRecorder::record(
            \App\Support\LoginAttemptRecorder::ACTOR_STAFF,
            \App\Support\LoginAttemptRecorder::CHANNEL_STAFF,
            \App\Support\LoginAttemptRecorder::STATUS_FAILED,
            identifier: '   ',
            ipAddress: '127.0.0.1',
        );

        $row = DB::table('login_attempts')->latest('id')->first();

        $this->assertNotNull($row);
        $this->assertNull($row->identifier_hash);
    }

    /*
    |--------------------------------------------------------------------------
    | Identifier-only clearing + IP retention
    |--------------------------------------------------------------------------
    */

    public function test_successful_login_clears_the_identifier_limit_but_keeps_the_ip_counter(): void
    {
        $this->createStaff($this->barangay, 'admin', [
            'staff_id' => 'ADM-001',
            'name' => 'Admin User',
        ]);

        $identifierKey = LoginIdentifier::middlewareKey(
            'login-staff',
            LoginIdentifier::identifierKey('staff', 'ADM-001')
        );
        $ipKey = LoginIdentifier::middlewareKey(
            'login-staff',
            LoginIdentifier::ipKey('staff', LoginIdentifier::LOCAL_ADDRESS)
        );

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->staffAttempt('ADM-001')->assertRedirect();
        }

        $this->assertSame(4, RateLimiter::attempts($identifierKey));

        $this->staffAttempt('ADM-001', 'password')
            ->assertRedirect(route('admin.dashboard'));

        $this->assertSame(
            0,
            RateLimiter::attempts($identifierKey),
            'Successful authentication must clear the identifier counter.'
        );

        $this->assertGreaterThan(
            0,
            RateLimiter::attempts($ipKey),
            'The shared IP counter must never be cleared by a single success.'
        );

        $this->staffAttempt('ADM-001')->assertRedirect();
        $this->assertSame(1, RateLimiter::attempts($identifierKey));
    }

    /*
    |--------------------------------------------------------------------------
    | Per-IP limit with distinct identifiers
    |--------------------------------------------------------------------------
    */

    public function test_ip_limit_trips_with_distinct_identifiers_so_the_identifier_limit_does_not_mask_it(): void
    {
        $ipKey = LoginIdentifier::middlewareKey(
            'login-staff',
            LoginIdentifier::ipKey('staff', LoginIdentifier::LOCAL_ADDRESS)
        );

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $this->staffAttempt('ADM-'.$attempt)->assertRedirect();
        }

        $this->assertTrue(RateLimiter::tooManyAttempts($ipKey, 20));

        $response = $this->staffAttempt('ADM-FINAL');

        $response->assertRedirect();
        $response->assertSessionHasErrors(['login' => self::MESSAGE]);

        $this->assertSame(
            1,
            DB::table('login_attempts')->where('status', 'rate_limited')->count()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Detector separation
    |--------------------------------------------------------------------------
    */

    public function test_rate_limited_rows_are_excluded_from_failed_counting(): void
    {
        $this->createStaff($this->barangay, 'admin', [
            'staff_id' => 'ADM-001',
            'name' => 'Admin User',
        ]);

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->staffAttempt('ADM-001')->assertRedirect();
        }

        $failed = DB::table('login_attempts')
            ->where('status', 'failed')
            ->where('attempted_at', '>=', now()->subMinutes(30))
            ->count();

        $rateLimited = DB::table('login_attempts')
            ->where('status', 'rate_limited')
            ->count();

        $this->assertSame(5, $failed);
        $this->assertSame(1, $rateLimited);
    }

    /*
    |--------------------------------------------------------------------------
    | Existing audit regressions
    |--------------------------------------------------------------------------
    */

    public function test_failed_login_still_records_login_failed_audit_row(): void
    {
        $this->createStaff($this->barangay, 'admin', [
            'staff_id' => 'ADM-001',
            'name' => 'Admin User',
        ]);

        $this->staffAttempt('ADM-001');

        $rows = DB::table('audit_logs')->get();

        $this->assertCount(1, $rows);
        $this->assertSame('login.failed', $rows->first()->event);
    }

    public function test_successful_login_still_records_login_success_audit_row(): void
    {
        $staff = $this->createStaff($this->barangay, 'admin', [
            'staff_id' => 'ADM-001',
            'name' => 'Admin User',
        ]);

        $this->staffAttempt('ADM-001', 'password')
            ->assertRedirect(route('admin.dashboard'));

        $rows = DB::table('audit_logs')->get();

        $this->assertCount(1, $rows);
        $this->assertSame('login.success', $rows->first()->event);
        $this->assertSame($staff->id, (int) $rows->first()->actor_id);
    }
}
