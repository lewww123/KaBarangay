<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Barangay;
use App\Models\LoginAttempt;
use App\Models\StaffAssignment;
use App\Support\AuditLogger;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;
use Throwable;

class AdminAuditLogTest extends TestCase
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

    private function insertLog(?Barangay $barangay, array $attributes = []): AuditLog
    {
        return AuditLog::create(array_merge([
            'barangay_id' => $barangay?->id,
            'event' => 'login.success',
        ], $attributes));
    }

    public function test_actor_position_is_specific_and_foreign_assignments_are_not_exposed(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $clerk->staffAssignments()->update(['position' => 'Barangay Clerk']);
        StaffAssignment::create(['user_id' => $clerk->id, 'resident_id' => $clerk->resident_id,
            'barangay_id' => $this->other->id, 'department' => 'lupon', 'position' => 'Foreign position']);
        $this->insertLog($this->barangay, ['actor_id' => $clerk->id]);
        LoginAttempt::create(['barangay_id' => $this->barangay->id, 'user_id' => $clerk->id,
            'actor_type' => 'staff', 'channel' => 'staff', 'status' => 'success', 'attempted_at' => now()]);
        $this->actingAs($admin)->get('/admin/audit-log')->assertOk()->assertInertia(fn ($page) => $page
            ->where('logs.data.0.actor.position', 'Barangay Clerk')
            ->where('logs.data.0.actor.department_label', 'Records and Certificates')
            ->where('loginAttempts.data.0.actor_label', 'Barangay Clerk')
            ->missing('loginAttempts.data.0.user')
            ->missing('logs.data.0.actor.email'));
    }

    /*
    |--------------------------------------------------------------------------
    | Access control
    |--------------------------------------------------------------------------
    */

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/audit-log')->assertRedirect(route('login'));
    }

    public function test_non_admin_roles_are_denied(): void
    {
        foreach (['clerk', 'senior', 'pwd', 'lupon'] as $role) {
            $user = $this->createStaff($this->barangay, $role);

            $this->actingAs($user)->get('/admin/audit-log')->assertStatus(403);
        }
    }

    public function test_super_admin_and_inactive_admin_are_denied(): void
    {
        $superAdmin = $this->createStaff($this->barangay, 'super_admin');

        $this->actingAs($superAdmin)->get('/admin/audit-log')->assertStatus(403);

        $inactive = $this->createStaff($this->barangay, 'admin', ['status' => 'inactive']);

        $this->actingAs($inactive)->get('/admin/audit-log')->assertStatus(403);
    }

    public function test_admin_can_view_the_audit_log_page(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->assertSame(
            'https://staff.kabarangay.com/admin/audit-log',
            route('admin.audit-log')
        );

        $this->actingAs($admin)->get('/admin/audit-log')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/AuditLog')
                ->where('filters.event', '')
                ->where('events', fn ($events) => collect($events)->contains('login.success'))
                ->where('logs.total', 0)
                ->where('logs.data', []));
    }

    /*
    |--------------------------------------------------------------------------
    | Barangay isolation
    |--------------------------------------------------------------------------
    */

    public function test_admin_sees_only_records_from_their_own_barangay(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $own = $this->insertLog($this->barangay, ['event' => 'staff.created']);
        $this->insertLog($this->other, ['event' => 'staff.created']);

        $this->actingAs($admin)->get('/admin/audit-log')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('logs.total', 1)
                ->has('logs.data', 1)
                ->where('logs.data.0.id', $own->id));
    }

    public function test_client_supplied_barangay_parameter_cannot_broaden_scope(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $own = $this->insertLog($this->barangay);
        $this->insertLog($this->other);

        $this->actingAs($admin)
            ->get('/admin/audit-log?barangay_id='.$this->other->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('logs.total', 1)
                ->has('logs.data', 1)
                ->where('logs.data.0.id', $own->id));
    }

    public function test_actor_from_another_barangay_is_not_exposed(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $foreignAdmin = $this->createStaff($this->other, 'admin');

        $this->insertLog($this->barangay, [
            'event' => 'staff.updated',
            'actor_id' => $foreignAdmin->id,
        ]);

        $this->actingAs($admin)->get('/admin/audit-log')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('logs.total', 1)
                ->where('logs.data.0.actor', null));
    }

    public function test_actor_display_data_is_limited_to_safe_fields(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->insertLog($this->barangay, [
            'event' => 'staff.updated',
            'actor_id' => $admin->id,
        ]);

        $this->actingAs($admin)->get('/admin/audit-log')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('logs.data.0.actor.id', $admin->id)
                ->where('logs.data.0.actor.name', $admin->name)
                ->where('logs.data.0.actor.department', 'admin')
                ->missing('logs.data.0.actor.role')
                ->missing('logs.data.0.actor.email')
                ->missing('logs.data.0.actor.staff_id')
                ->missing('logs.data.0.actor.password'));
    }

    public function test_activity_payload_exposes_only_safe_fields(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $log = $this->insertLog($this->barangay, [
            'event' => 'staff.updated',
            'actor_id' => $admin->id,
            'entity_type' => 'staff',
            'entity_id' => 7,
            'context' => ['department' => 'Clerk'],
            'ip_address' => '203.0.113.55',
        ]);

        $response = $this->actingAs($admin)->get('/admin/audit-log');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->has('logs.data', 1)
            ->where('logs.data.0.id', $log->id)
            ->where('logs.data.0.event', 'staff.updated')
            ->missing('logs.data.0.actor.email')
            ->missing('logs.data.0.entity_type')
            ->missing('logs.data.0.entity_id')
            ->missing('logs.data.0.context')
            ->missing('logs.data.0.ip_address'));

        $payload = json_encode($response->inertiaProps('logs.data'));

        $this->assertStringNotContainsString('203.0.113.55', $payload);
    }

    public function test_raw_audit_ip_address_is_never_sent_to_the_browser(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->insertLog($this->barangay, [
            'event' => 'login.success',
            'ip_address' => '198.51.100.24',
        ]);

        $response = $this->actingAs($admin)->get('/admin/audit-log');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->has('logs.data', 1)
            ->missing('logs.data.0.ip_address'));

        $this->assertStringNotContainsString('198.51.100.24', (string) $response->getContent());
    }

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */

    public function test_records_are_paginated_newest_first(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $newestId = null;

        for ($i = 0; $i < 20; $i++) {
            $newestId = $this->insertLog($this->barangay, ['event' => 'login.failed'])->id;
        }

        // Foreign rows must not inflate the administrator's pagination.
        $this->insertLog($this->other, ['event' => 'login.failed']);
        $this->insertLog($this->other, ['event' => 'login.failed']);

        $this->actingAs($admin)->get('/admin/audit-log')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('logs.current_page', 1)
                ->where('logs.per_page', 15)
                ->where('logs.total', 20)
                ->has('logs.data', 15)
                ->where('logs.data.0.id', $newestId));

        $this->actingAs($admin)->get('/admin/audit-log?page=2')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('logs.current_page', 2)
                ->where('logs.total', 20)
                ->has('logs.data', 5));
    }

    public function test_event_filter_is_preserved_in_pagination_links(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        for ($i = 0; $i < 20; $i++) {
            $this->insertLog($this->barangay, ['event' => 'login.failed']);
        }

        $response = $this->actingAs($admin)
            ->get('/admin/audit-log?event=login.failed&page=2');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('logs.current_page', 2)
            ->where('filters.event', 'login.failed')
            ->has('logs.data', 5));

        $previousPageUrl = (string) $response->inertiaProps('logs.prev_page_url');

        $this->assertStringContainsString('event=login.failed', $previousPageUrl);
        $this->assertStringContainsString('page=1', $previousPageUrl);
        $this->assertNull($response->inertiaProps('logs.next_page_url'));
    }

    /*
    |--------------------------------------------------------------------------
    | Timestamp display
    |--------------------------------------------------------------------------
    */

    public function test_created_at_is_rendered_in_philippine_time(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        // A raw UTC timestamp bypasses the model's automatic created_at so the
        // UTC -> Asia/Manila conversion and the exact display format are pinned
        // down by the listing payload rather than inferred from the database.
        DB::table('audit_logs')->insert([
            'barangay_id' => $this->barangay->id,
            'event' => 'login.success',
            'created_at' => '2026-01-15 03:30:00',
        ]);

        $this->actingAs($admin)->get('/admin/audit-log')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('logs.data', 1)
                ->where('logs.data.0.created_at', '2026-01-15 11:30:00 PHT'));
    }

    public function test_null_created_at_is_returned_as_null(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        // The created_at column is nullable; a row without a timestamp must
        // surface as null (the UI renders its own placeholder) rather than
        // being dropped or coerced by the listing payload.
        DB::table('audit_logs')->insert([
            'barangay_id' => $this->barangay->id,
            'event' => 'login.success',
            'created_at' => null,
        ]);

        $this->actingAs($admin)->get('/admin/audit-log')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('logs.total', 1)
                ->has('logs.data', 1)
                ->where('logs.data.0.created_at', null));
    }

    /*
    |--------------------------------------------------------------------------
    | Event filter
    |--------------------------------------------------------------------------
    */

    public function test_valid_event_filter_is_applied(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $failed = $this->insertLog($this->barangay, ['event' => 'login.failed']);
        $this->insertLog($this->barangay, ['event' => 'staff.created']);
        $this->insertLog($this->other, ['event' => 'login.failed']);

        $this->actingAs($admin)->get('/admin/audit-log?event=login.failed')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.event', 'login.failed')
                ->where('logs.total', 1)
                ->has('logs.data', 1)
                ->where('logs.data.0.id', $failed->id)
                ->where('logs.data.0.event', 'login.failed'));
    }

    public function test_invalid_event_filter_is_ignored_and_never_broadens_scope(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $own = $this->insertLog($this->barangay, ['event' => 'staff.created']);
        $this->insertLog($this->other, ['event' => 'staff.created']);
        $this->insertLog($this->other, ['event' => 'login.failed']);

        // Unknown event names are normalized to "no filter": the listing stays
        // barangay-scoped and never errors.
        $this->actingAs($admin)->get('/admin/audit-log?event=bogus.event')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.event', '')
                ->where('logs.total', 1)
                ->where('logs.data.0.id', $own->id));

        // Array-shaped query input is rejected as a filter value too.
        $this->actingAs($admin)->get('/admin/audit-log?event[]=login.failed')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.event', '')
                ->where('logs.total', 1));
    }

    public function test_empty_result_set_renders(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->insertLog($this->other);

        $this->actingAs($admin)->get('/admin/audit-log')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('logs.total', 0)
                ->where('logs.data', []));
    }

    /*
    |--------------------------------------------------------------------------
    | No write routes
    |--------------------------------------------------------------------------
    */

    public function test_no_audit_log_write_routes_exist(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            $this->actingAs($admin)->{$method}('/admin/audit-log')
                ->assertStatus(405);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Writer behaviour
    |--------------------------------------------------------------------------
    */

    public function test_writer_stores_nullable_barangay_and_actor(): void
    {
        AuditLogger::record('login.failed');

        AuditLogger::record(
            'resident_registration.submitted',
            barangayId: $this->barangay->id,
            actorId: null,
            entityType: 'resident_registration',
            entityId: 42,
        );

        $unscoped = DB::table('audit_logs')
            ->where('event', 'login.failed')
            ->first();

        $this->assertNotNull($unscoped);
        $this->assertNull($unscoped->barangay_id);
        $this->assertNull($unscoped->actor_id);

        $scoped = DB::table('audit_logs')
            ->where('event', 'resident_registration.submitted')
            ->first();

        $this->assertNotNull($scoped);
        $this->assertSame($this->barangay->id, (int) $scoped->barangay_id);
        $this->assertNull($scoped->actor_id);
        $this->assertSame('resident_registration', $scoped->entity_type);
        $this->assertSame(42, (int) $scoped->entity_id);
        $this->assertNotNull($scoped->created_at);
    }

    public function test_writer_allowlists_context_and_drops_forbidden_keys(): void
    {
        AuditLogger::record(
            'certificate_request.status_changed',
            barangayId: $this->barangay->id,
            entityType: 'certificate_request',
            entityId: 7,
            context: [
                'previous_status' => 'Pending',
                'new_status' => 'Processing',
                'department' => 'Clerk',
                'password' => 'super-secret',
                'token' => 'abc123',
                'session_id' => 'sess-1',
                'admin_note' => 'free text note',
                'rejection_reason' => 'resident rejected',
                'payload' => ['nested' => 'request body'],
                'ratio' => 1.5,
            ],
        );

        $row = DB::table('audit_logs')->first();

        $this->assertNotNull($row);
        $this->assertSame(
            [
                'previous_status' => 'Pending',
                'new_status' => 'Processing',
                'department' => 'Clerk',
            ],
            json_decode($row->context, true)
        );

        // A context holding no allowlisted key is stored as NULL, not as the
        // forbidden payload.
        AuditLogger::record('login.failed', context: ['password' => 'another-secret']);

        $this->assertNull(
            DB::table('audit_logs')
                ->where('event', 'login.failed')
                ->value('context')
        );
    }

    public function test_writer_stores_only_valid_ip_addresses(): void
    {
        AuditLogger::record('login.success', ipAddress: '2001:db8::1');
        AuditLogger::record('login.failed', ipAddress: 'not-an-ip');

        $this->assertSame(
            '2001:db8::1',
            DB::table('audit_logs')
                ->where('event', 'login.success')
                ->value('ip_address')
        );

        $this->assertNull(
            DB::table('audit_logs')
                ->where('event', 'login.failed')
                ->value('ip_address')
        );
    }

    public function test_writer_reports_sanitization_failure_without_breaking_the_caller(): void
    {
        $handler = Mockery::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')
            ->once()
            ->with(Mockery::type(Throwable::class));
        $this->app->instance(ExceptionHandler::class, $handler);

        // Over-length event name: the writer must swallow and report it.
        AuditLogger::record(str_repeat('e', 61));

        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_writer_reports_database_failure_without_breaking_the_caller(): void
    {
        $handler = Mockery::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')
            ->once()
            ->with(Mockery::type(Throwable::class));
        $this->app->instance(ExceptionHandler::class, $handler);

        Schema::drop('audit_logs');

        $this->assertFalse(Schema::hasTable('audit_logs'));

        // Insert failure: the writer must swallow and report it.
        AuditLogger::record('login.success', barangayId: $this->barangay->id);
    }

    /*
    |--------------------------------------------------------------------------
    | Append-only model guard
    |--------------------------------------------------------------------------
    */

    public function test_model_rejects_update_attempts(): void
    {
        $log = $this->insertLog($this->barangay);

        $this->expectException(RuntimeException::class);

        $log->update(['event' => 'tampered.event']);
    }

    public function test_model_rejects_delete_attempts(): void
    {
        $log = $this->insertLog($this->barangay);

        $this->expectException(RuntimeException::class);

        $log->delete();
    }
}
