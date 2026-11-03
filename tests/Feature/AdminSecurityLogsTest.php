<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\AuditLogController;
use App\Models\AuditLog;
use App\Models\Barangay;
use App\Models\LoginAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class AdminSecurityLogsTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Barangay $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $this->other = $this->createBarangay('Barangay Two', 'BRGY-TWO');
    }

    private function insertAttempt(?Barangay $barangay, array $attributes = []): LoginAttempt
    {
        return LoginAttempt::create(array_merge([
            'barangay_id' => $barangay?->id,
            'actor_type' => 'resident',
            'channel' => 'resident',
            'identifier_hash' => str_repeat('a', 64),
            'status' => 'failed',
            'ip_address' => '203.0.113.10',
            'attempted_at' => now(),
        ], $attributes));
    }

    private function insertAlert(?Barangay $barangay, array $attributes = []): void
    {
        DB::table('suspicious_login_alerts')->insert(array_merge([
            'barangay_id' => $barangay?->id,
            'channel' => 'resident',
            'identifier_hash' => str_repeat('b', 64),
            'alert_type' => 'failed_login_threshold',
            'attempt_count' => 5,
            'window_seconds' => 1800,
            'first_attempt_at' => now(),
            'last_attempt_at' => now(),
            'ip_address' => '203.0.113.10',
            'dedup_key' => hash('sha256', uniqid('', true).random_int(1, PHP_INT_MAX)),
            'created_at' => now(),
        ], $attributes));
    }

    /*
    |--------------------------------------------------------------------------
    | Login attempts: barangay isolation
    |--------------------------------------------------------------------------
    */

    public function test_security_tab_lists_only_own_barangay_login_attempts(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $own = $this->insertAttempt($this->barangay);
        $this->insertAttempt($this->other);

        $this->actingAs($admin)->get('/admin/audit-log?tab=security')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/AuditLog')
                ->where('tab', 'security')
                ->where('loginAttempts.total', 1)
                ->has('loginAttempts.data', 1)
                ->where('loginAttempts.data.0.id', $own->id));
    }

    public function test_null_barangay_login_attempts_are_excluded(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->insertAttempt($this->barangay);
        $this->insertAttempt(null);

        $this->actingAs($admin)->get('/admin/audit-log?tab=security')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('loginAttempts.total', 1));
    }

    public function test_admin_without_barangay_sees_no_login_attempts(): void
    {
        $orphan = $this->createStaff($this->barangay, 'admin', ['barangay_id' => null]);

        $this->insertAttempt($this->barangay);

        $this->actingAs($orphan)->get('/admin/audit-log?tab=security')
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Suspicious alerts: barangay isolation
    |--------------------------------------------------------------------------
    */

    public function test_security_tab_lists_only_own_barangay_alerts(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->insertAlert($this->barangay);
        $this->insertAlert($this->other);

        $this->actingAs($admin)->get('/admin/audit-log?tab=security')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('alerts.total', 1)
                ->has('alerts.data', 1)
                ->where('alerts.data.0.channel', 'resident')
                ->where('alerts.data.0.alert_type', 'failed_login_threshold')
                ->where('alerts.data.0.attempt_count', 5)
                ->where('alerts.data.0.window_seconds', 1800));
    }

    public function test_null_barangay_alerts_remain_hidden(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->insertAlert($this->barangay);
        $this->insertAlert(null);

        $this->actingAs($admin)->get('/admin/audit-log?tab=security')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('alerts.total', 1));
    }

    /*
    |--------------------------------------------------------------------------
    | Safe field projection
    |--------------------------------------------------------------------------
    */

    public function test_security_props_never_expose_identifier_hashes(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $hash = str_repeat('a', 64);
        $this->insertAttempt($this->barangay, ['identifier_hash' => $hash]);
        $this->insertAlert($this->barangay, ['identifier_hash' => str_repeat('b', 64)]);

        $this->actingAs($admin)->get('/admin/audit-log?tab=security')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->missing('loginAttempts.data.0.identifier_hash')
                ->missing('alerts.data.0.identifier_hash')
                ->missing('loginAttempts.data.0.dedup_key')
                ->missing('alerts.data.0.dedup_key'));
    }

    public function test_login_attempt_payload_omits_ip_and_exposes_actor_type(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $attempt = $this->insertAttempt($this->barangay, [
            'actor_type' => 'resident',
            'channel' => 'resident',
            'ip_address' => '203.0.113.10',
        ]);

        $response = $this->actingAs($admin)->get('/admin/audit-log?tab=security');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('loginAttempts.data.0.id', $attempt->id)
            ->where('loginAttempts.data.0.actor_type', 'resident')
            ->where('loginAttempts.data.0.channel', 'resident')
            ->missing('loginAttempts.data.0.ip_address'));

        // The IP column is dropped entirely, so neither the raw nor the masked
        // form may appear anywhere in the response.
        $body = (string) $response->getContent();

        $this->assertStringNotContainsString('203.0.113.10', $body);
        $this->assertStringNotContainsString('203.0.113.xxx', $body);
    }

    /*
    |--------------------------------------------------------------------------
    | Tab selection and dataset isolation
    |--------------------------------------------------------------------------
    */

    public function test_activity_log_and_security_datasets_are_supplied_together_on_one_page(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->insertAttempt($this->barangay);
        $this->insertAlert($this->barangay);

        $this->actingAs($admin)->get('/admin/audit-log')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tab', 'activity')
                ->has('logs')
                ->has('loginAttempts')
                ->has('alerts'));
    }

    public function test_security_tab_parameter_still_supplies_the_activity_log(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)->get('/admin/audit-log?tab=security')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tab', 'security')
                ->has('logs')
                ->has('loginAttempts')
                ->has('alerts'));
    }

    public function test_invalid_tab_falls_back_and_still_supplies_all_datasets_without_broadening_scope(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->insertAttempt($this->other);
        $this->insertAlert($this->other);

        $this->actingAs($admin)->get('/admin/audit-log?tab=../security')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tab', 'activity')
                ->where('loginAttempts.total', 0)
                ->where('alerts.total', 0));
    }

    public function test_security_tab_preserves_tab_in_pagination_links(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        for ($i = 0; $i < 20; $i++) {
            $this->insertAttempt($this->barangay, [
                'attempted_at' => now()->subSeconds($i),
            ]);
        }

        $this->actingAs($admin)->get('/admin/audit-log?tab=security')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('loginAttempts.total', 20)
                ->where('loginAttempts.last_page', 2)
                ->where('loginAttempts.data.0.id', fn ($id) => $id !== null));

        $this->actingAs($admin)->get('/admin/audit-log?tab=security&page=2')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tab', 'security')
                ->where('loginAttempts.current_page', 2));
    }

    /*
    |--------------------------------------------------------------------------
    | Access control for the security tab
    |--------------------------------------------------------------------------
    */

    public function test_guest_is_redirected_from_the_security_tab(): void
    {
        $this->get('/admin/audit-log?tab=security')->assertRedirect(route('login'));
    }

    public function test_non_admin_roles_are_denied_the_security_tab(): void
    {
        foreach (['clerk', 'senior', 'pwd', 'lupon'] as $role) {
            $user = $this->createStaff($this->barangay, $role);

            $this->actingAs($user)
                ->get('/admin/audit-log?tab=security')
                ->assertStatus(403);
        }
    }

    public function test_inactive_admin_is_denied_the_security_tab(): void
    {
        $inactive = $this->createStaff($this->barangay, 'admin', ['status' => 'inactive']);

        $this->actingAs($inactive)
            ->get('/admin/audit-log?tab=security')
            ->assertStatus(403);
    }

    public function test_client_supplied_barangay_cannot_broaden_security_scope(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $own = $this->insertAttempt($this->barangay);
        $this->insertAttempt($this->other);

        $this->actingAs($admin)
            ->get('/admin/audit-log?tab=security&barangay_id='.$this->other->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('loginAttempts.total', 1)
                ->where('loginAttempts.data.0.id', $own->id));
    }

    /*
    |--------------------------------------------------------------------------
    | Existing Audit Trail behavior is preserved
    |--------------------------------------------------------------------------
    */

    public function test_activity_trail_behavior_is_unchanged(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        AuditLog::create([
            'barangay_id' => $this->barangay->id,
            'event' => 'login.success',
        ]);

        $this->actingAs($admin)->get('/admin/audit-log')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/AuditLog')
                ->where('tab', 'activity')
                ->where('filters.event', '')
                ->where('events', fn ($events) => collect($events)->contains('login.success'))
                ->where('logs.total', 1));
    }

    /*
    |--------------------------------------------------------------------------
    | Per-section error resilience
    |--------------------------------------------------------------------------
    |
    | A missing or unavailable Security Logs table must never take down the
    | whole Audit Log page. Each section fails independently: the failing
    | section is flagged unavailable (not silently shown as empty) and the
    | other sections still load. Query failures are simulated with test-only
    | controller mocks; no real table is dropped or altered.
    |
    */

    public function test_login_attempt_failure_marks_only_that_section_unavailable(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        AuditLog::create([
            'barangay_id' => $this->barangay->id,
            'event' => 'login.success',
        ]);

        $this->insertAlert($this->barangay);
        $this->insertAttempt($this->barangay);

        $this->partialMock(
            AuditLogController::class,
            function ($mock) {
                $mock->shouldAllowMockingProtectedMethods();
                $mock->shouldReceive('loginAttempts')
                    ->andThrow(new \RuntimeException(
                        'SQLSTATE[42P01] relation "login_attempts" does not exist on db.example.internal'
                    ));
            },
        );

        $response = $this->actingAs($admin)->get('/admin/audit-log');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('Admin/AuditLog')
            ->where('logs.total', 1)
            ->where('loginAttemptsAvailable', false)
            ->where('loginAttempts.total', 0)
            ->where('loginAttempts.data', [])
            ->where('alertsAvailable', true)
            ->where('alerts.total', 1));

        $body = (string) $response->getContent();

        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('login_attempts', $body);
        $this->assertStringNotContainsString('db.example.internal', $body);
        $this->assertStringNotContainsString('RuntimeException', $body);
        $this->assertStringNotContainsString('42P01', $body);
    }

    public function test_suspicious_alert_failure_marks_only_that_section_unavailable(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        AuditLog::create([
            'barangay_id' => $this->barangay->id,
            'event' => 'login.success',
        ]);

        $this->insertAttempt($this->barangay);
        $this->insertAlert($this->barangay);

        $this->partialMock(
            AuditLogController::class,
            function ($mock) {
                $mock->shouldAllowMockingProtectedMethods();
                $mock->shouldReceive('suspiciousAlerts')
                    ->andThrow(new \RuntimeException(
                        'SQLSTATE[42P01] relation "suspicious_login_alerts" does not exist'
                    ));
            },
        );

        $response = $this->actingAs($admin)->get('/admin/audit-log');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('Admin/AuditLog')
            ->where('logs.total', 1)
            ->where('loginAttemptsAvailable', true)
            ->where('loginAttempts.total', 1)
            ->where('alertsAvailable', false)
            ->where('alerts.total', 0)
            ->where('alerts.data', []));

        $body = (string) $response->getContent();

        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('suspicious_login_alerts', $body);
        $this->assertStringNotContainsString('RuntimeException', $body);
    }

    public function test_both_security_failures_still_render_the_activity_log(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        AuditLog::create([
            'barangay_id' => $this->barangay->id,
            'event' => 'staff.created',
        ]);

        $this->partialMock(
            AuditLogController::class,
            function ($mock) {
                $mock->shouldAllowMockingProtectedMethods();
                $mock->shouldReceive('loginAttempts')
                    ->andThrow(new \RuntimeException('boom-login'));
                $mock->shouldReceive('suspiciousAlerts')
                    ->andThrow(new \RuntimeException('boom-alerts'));
            },
        );

        $this->actingAs($admin)->get('/admin/audit-log')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/AuditLog')
                ->where('logs.total', 1)
                ->where('loginAttemptsAvailable', false)
                ->where('alertsAvailable', false));
    }

    public function test_sections_report_available_when_queries_succeed(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->insertAttempt($this->barangay);

        $this->actingAs($admin)->get('/admin/audit-log')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('loginAttemptsAvailable', true)
                ->where('alertsAvailable', true));
    }
}
