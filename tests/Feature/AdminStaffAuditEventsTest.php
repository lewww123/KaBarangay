<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;
use Throwable;

class AdminStaffAuditEventsTest extends TestCase
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

    private function portalAdmin(): User
    {
        return $this->createStaff($this->barangay, 'admin', [
            'staff_id' => 'ADM-001',
            'name' => 'Portal Admin',
            'email' => 'portal.admin@example.test',
        ]);
    }

    private function rows(): Collection
    {
        return DB::table('audit_logs')->orderBy('id')->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Create: staff.created
    |--------------------------------------------------------------------------
    */

    public function test_store_records_one_staff_created_event_with_actor_barangay_entity_context_and_ip(): void
    {
        $admin = $this->portalAdmin();

        $response = $this->actingAs($admin)->post('/admin/staff', [
            'name' => 'New Clerk',
            'email' => 'new.clerk@example.test',
            'staff_id' => 'CLK-100',
            'role' => 'clerk',
            'department' => 'Health Office',
            'password' => 'secret-pass-123',
        ]);

        $response->assertRedirect();

        $created = User::query()->where('email', 'new.clerk@example.test')->firstOrFail();

        $rows = $this->rows();

        $this->assertCount(1, $rows);

        $row = $rows->first();

        $this->assertSame('staff.created', $row->event);
        $this->assertSame($admin->id, (int) $row->actor_id);
        $this->assertSame($this->barangay->id, (int) $row->barangay_id);
        $this->assertSame('user', $row->entity_type);
        $this->assertSame($created->id, (int) $row->entity_id);
        $this->assertSame(
            ['new_role' => 'clerk', 'department' => 'Health Office'],
            json_decode($row->context, true)
        );
        $this->assertSame('127.0.0.1', $row->ip_address);
        $this->assertNotNull($row->created_at);
    }

    public function test_create_with_blank_department_records_the_defaulted_department_in_context(): void
    {
        $admin = $this->portalAdmin();

        $this->actingAs($admin)->post('/admin/staff', [
            'name' => 'Senior Staffer',
            'email' => 'senior.staffer@example.test',
            'staff_id' => 'SEN-100',
            'role' => 'senior_staff',
            'department' => '',
            'password' => 'secret-pass-123',
        ])->assertRedirect();

        $rows = $this->rows();

        $this->assertCount(1, $rows);
        $this->assertSame('staff.created', $rows[0]->event);
        $this->assertSame(
            ['new_role' => 'senior_staff', 'department' => 'Senior Staff'],
            json_decode($rows[0]->context, true)
        );
    }

    public function test_create_validation_failure_records_nothing(): void
    {
        $admin = $this->portalAdmin();

        $response = $this->actingAs($admin)
            ->from('/admin/staff')
            ->post('/admin/staff', [
                'name' => '',
                'email' => 'not-an-email',
                'staff_id' => '',
                'role' => 'super_admin',
                'department' => '',
                'password' => 'short',
            ]);

        $response->assertSessionHasErrors(['name', 'email', 'staff_id', 'role', 'password']);

        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Update: staff.updated and staff.role_changed
    |--------------------------------------------------------------------------
    */

    public function test_profile_only_update_records_one_staff_updated_event_without_context(): void
    {
        $admin = $this->portalAdmin();
        $clerk = $this->createStaff($this->barangay, 'clerk', [
            'staff_id' => 'CLK-001',
            'name' => 'Original Clerk',
            'email' => 'clerk@example.test',
        ]);

        $this->actingAs($admin)->put("/admin/staff/{$clerk->id}", [
            'name' => 'Renamed Clerk',
            'email' => 'renamed.clerk@example.test',
            'staff_id' => 'CLK-002',
            'role' => 'clerk',
            'department' => '',
            'password' => '',
        ])->assertRedirect();

        $rows = $this->rows();

        $this->assertCount(1, $rows);

        $row = $rows->first();

        $this->assertSame('staff.updated', $row->event);
        $this->assertSame($admin->id, (int) $row->actor_id);
        $this->assertSame($this->barangay->id, (int) $row->barangay_id);
        $this->assertSame('user', $row->entity_type);
        $this->assertSame($clerk->id, (int) $row->entity_id);
        $this->assertNull($row->context);
        $this->assertSame('127.0.0.1', $row->ip_address);
    }

    public function test_profile_update_changing_department_includes_department_context(): void
    {
        $admin = $this->portalAdmin();
        $clerk = $this->createStaff($this->barangay, 'clerk', [
            'staff_id' => 'CLK-010',
            'name' => 'Records Clerk',
            'email' => 'records.clerk@example.test',
        ]);

        $this->actingAs($admin)->put("/admin/staff/{$clerk->id}", [
            'name' => $clerk->name,
            'email' => $clerk->email,
            'staff_id' => $clerk->staff_id,
            'role' => 'clerk',
            'department' => 'Records Office',
            'password' => '',
        ])->assertRedirect();

        $rows = $this->rows();

        $this->assertCount(1, $rows);
        $this->assertSame('staff.updated', $rows[0]->event);
        $this->assertSame(['department' => 'Records Office'], json_decode($rows[0]->context, true));
    }

    public function test_no_op_update_records_nothing(): void
    {
        $admin = $this->portalAdmin();
        $clerk = $this->createStaff($this->barangay, 'clerk', [
            'staff_id' => 'CLK-020',
            'name' => 'Steady Clerk',
            'email' => 'steady.clerk@example.test',
        ]);

        $this->actingAs($admin)->put("/admin/staff/{$clerk->id}", [
            'name' => $clerk->name,
            'email' => $clerk->email,
            'staff_id' => $clerk->staff_id,
            'role' => $clerk->role,
            'department' => '',
            'password' => '',
        ])->assertRedirect();

        $this->assertSame(0, DB::table('audit_logs')->count());
        $this->assertSame('Steady Clerk', $clerk->refresh()->name);
    }

    public function test_role_only_change_records_exactly_one_staff_role_changed_event(): void
    {
        $admin = $this->portalAdmin();
        $clerk = $this->createStaff($this->barangay, 'clerk', [
            'staff_id' => 'CLK-030',
            'name' => 'Promotable Clerk',
            'email' => 'promotable.clerk@example.test',
        ]);

        $this->actingAs($admin)->put("/admin/staff/{$clerk->id}", [
            'name' => $clerk->name,
            'email' => $clerk->email,
            'staff_id' => $clerk->staff_id,
            'role' => 'senior',
            'department' => '',
            'password' => '',
        ])->assertRedirect();

        $rows = $this->rows();

        $this->assertCount(1, $rows);

        $row = $rows->first();

        $this->assertSame('staff.role_changed', $row->event);
        $this->assertSame($admin->id, (int) $row->actor_id);
        $this->assertSame($clerk->id, (int) $row->entity_id);
        $this->assertSame(
            ['previous_role' => 'clerk', 'new_role' => 'senior'],
            json_decode($row->context, true)
        );
        $this->assertSame('senior', $clerk->refresh()->role);
    }

    public function test_combined_role_profile_and_department_change_records_exactly_one_role_event(): void
    {
        $admin = $this->portalAdmin();
        $clerk = $this->createStaff($this->barangay, 'clerk', [
            'staff_id' => 'CLK-040',
            'name' => 'Combined Clerk',
            'email' => 'combined.clerk@example.test',
        ]);

        $this->actingAs($admin)->put("/admin/staff/{$clerk->id}", [
            'name' => 'Combined Senior',
            'email' => 'combined.senior@example.test',
            'staff_id' => 'CLK-041',
            'role' => 'senior',
            'department' => 'Senior Affairs',
            'password' => '',
        ])->assertRedirect();

        $rows = $this->rows();

        // One event only: profile and department changes must not add a
        // second staff.updated row alongside the role change.
        $this->assertCount(1, $rows);
        $this->assertSame('staff.role_changed', $rows[0]->event);
        $this->assertSame(
            [
                'previous_role' => 'clerk',
                'new_role' => 'senior',
                'department' => 'Senior Affairs',
            ],
            json_decode($rows[0]->context, true)
        );
    }

    public function test_role_change_with_password_change_emits_one_role_event_without_password_signal(): void
    {
        $admin = $this->portalAdmin();
        $clerk = $this->createStaff($this->barangay, 'clerk', [
            'staff_id' => 'CLK-050',
            'name' => 'Rotated Clerk',
            'email' => 'rotated.clerk@example.test',
        ]);

        $this->actingAs($admin)->put("/admin/staff/{$clerk->id}", [
            'name' => $clerk->name,
            'email' => $clerk->email,
            'staff_id' => $clerk->staff_id,
            'role' => 'senior',
            'department' => '',
            'password' => 'role-plus-pass-1',
        ])->assertRedirect();

        $clerk->refresh();

        // The password really changed, yet the approved contract emits exactly
        // one role event with no action/password signal and no second row.
        $this->assertTrue(Hash::check('role-plus-pass-1', $clerk->password));

        $rows = $this->rows();

        $this->assertCount(1, $rows);

        $row = $rows->first();

        $this->assertSame('staff.role_changed', $row->event);

        $context = json_decode($row->context, true);

        $this->assertSame(['previous_role' => 'clerk', 'new_role' => 'senior'], $context);
        $this->assertArrayNotHasKey('action', $context);

        $serialized = json_encode((array) $row);

        $this->assertStringNotContainsString('role-plus-pass-1', $serialized);
        $this->assertStringNotContainsString($clerk->password, $serialized);
        $this->assertStringNotContainsString('"password"', $serialized);
    }

    public function test_password_only_change_records_staff_updated_with_password_action_and_no_secret_material(): void
    {
        $admin = $this->portalAdmin();
        $clerk = $this->createStaff($this->barangay, 'clerk', [
            'staff_id' => 'CLK-060',
            'name' => 'Password Clerk',
            'email' => 'password.clerk@example.test',
        ]);

        $this->actingAs($admin)->put("/admin/staff/{$clerk->id}", [
            'name' => $clerk->name,
            'email' => $clerk->email,
            'staff_id' => $clerk->staff_id,
            'role' => $clerk->role,
            'department' => '',
            'password' => 'brand-new-pass-1',
        ])->assertRedirect();

        $clerk->refresh();

        $this->assertTrue(Hash::check('brand-new-pass-1', $clerk->password));

        $rows = $this->rows();

        $this->assertCount(1, $rows);

        $row = $rows->first();

        $this->assertSame('staff.updated', $row->event);
        $this->assertSame(['action' => 'password_changed'], json_decode($row->context, true));

        $serialized = json_encode((array) $row);

        $this->assertStringNotContainsString('brand-new-pass-1', $serialized);
        $this->assertStringNotContainsString($clerk->password, $serialized);
        $this->assertStringNotContainsString('"password"', $serialized);
    }

    public function test_profile_and_password_change_records_one_staff_updated_event_with_password_action(): void
    {
        $admin = $this->portalAdmin();
        $clerk = $this->createStaff($this->barangay, 'clerk', [
            'staff_id' => 'CLK-070',
            'name' => 'Working Clerk',
            'email' => 'working.clerk@example.test',
        ]);

        $this->actingAs($admin)->put("/admin/staff/{$clerk->id}", [
            'name' => 'Working Clerk Renamed',
            'email' => $clerk->email,
            'staff_id' => $clerk->staff_id,
            'role' => 'clerk',
            'department' => '',
            'password' => 'another-pass-9',
        ])->assertRedirect();

        $clerk->refresh();

        $this->assertSame('Working Clerk Renamed', $clerk->name);
        $this->assertTrue(Hash::check('another-pass-9', $clerk->password));

        $rows = $this->rows();

        $this->assertCount(1, $rows);
        $this->assertSame('staff.updated', $rows[0]->event);
        $this->assertSame(['action' => 'password_changed'], json_decode($rows[0]->context, true));
    }

    public function test_update_validation_failure_records_nothing(): void
    {
        $admin = $this->portalAdmin();
        $clerk = $this->createStaff($this->barangay, 'clerk', [
            'staff_id' => 'CLK-080',
            'name' => 'Validated Clerk',
            'email' => 'validated.clerk@example.test',
        ]);

        $response = $this->actingAs($admin)
            ->from('/admin/staff')
            ->put("/admin/staff/{$clerk->id}", [
                'name' => $clerk->name,
                'email' => $clerk->email,
                'staff_id' => $clerk->staff_id,
                'role' => 'super_admin',
                'department' => '',
                'password' => '',
            ]);

        $response->assertSessionHasErrors('role');

        $this->assertSame(0, DB::table('audit_logs')->count());
        $this->assertSame('clerk', $clerk->refresh()->role);
    }

    /*
    |--------------------------------------------------------------------------
    | Status: staff.status_changed
    |--------------------------------------------------------------------------
    */

    public function test_status_deactivate_and_reactivate_each_record_one_status_event(): void
    {
        $admin = $this->portalAdmin();
        $clerk = $this->createStaff($this->barangay, 'clerk', [
            'staff_id' => 'CLK-090',
            'name' => 'Toggled Clerk',
            'email' => 'toggled.clerk@example.test',
        ]);

        $this->actingAs($admin)
            ->patch("/admin/staff/{$clerk->id}/status", ['status' => 'inactive'])
            ->assertRedirect();

        $this->actingAs($admin)
            ->patch("/admin/staff/{$clerk->id}/status", ['status' => 'active'])
            ->assertRedirect();

        $rows = $this->rows();

        $this->assertCount(2, $rows);

        $this->assertSame('staff.status_changed', $rows[0]->event);
        $this->assertSame($admin->id, (int) $rows[0]->actor_id);
        $this->assertSame($this->barangay->id, (int) $rows[0]->barangay_id);
        $this->assertSame('user', $rows[0]->entity_type);
        $this->assertSame($clerk->id, (int) $rows[0]->entity_id);
        $this->assertSame(
            ['previous_status' => 'active', 'new_status' => 'inactive'],
            json_decode($rows[0]->context, true)
        );
        $this->assertSame('127.0.0.1', $rows[0]->ip_address);

        $this->assertSame('staff.status_changed', $rows[1]->event);
        $this->assertSame(
            ['previous_status' => 'inactive', 'new_status' => 'active'],
            json_decode($rows[1]->context, true)
        );
    }

    public function test_no_op_status_change_records_nothing(): void
    {
        $admin = $this->portalAdmin();
        $clerk = $this->createStaff($this->barangay, 'clerk', [
            'staff_id' => 'CLK-095',
            'name' => 'Stable Clerk',
            'email' => 'stable.clerk@example.test',
        ]);

        $this->actingAs($admin)
            ->patch("/admin/staff/{$clerk->id}/status", ['status' => 'active'])
            ->assertRedirect();

        $this->assertSame(0, DB::table('audit_logs')->count());
        $this->assertSame('active', $clerk->refresh()->status);
    }

    /*
    |--------------------------------------------------------------------------
    | Blocked attempts record nothing
    |--------------------------------------------------------------------------
    */

    public function test_self_role_change_is_denied_without_audit_rows(): void
    {
        $admin = $this->portalAdmin();

        $this->actingAs($admin)->put("/admin/staff/{$admin->id}", [
            'name' => $admin->name,
            'email' => $admin->email,
            'staff_id' => $admin->staff_id,
            'role' => 'clerk',
            'department' => '',
            'password' => '',
        ])->assertStatus(403);

        $this->assertSame(0, DB::table('audit_logs')->count());
        $this->assertSame('admin', $admin->refresh()->role);
    }

    public function test_self_status_change_is_denied_without_audit_rows(): void
    {
        $admin = $this->portalAdmin();

        $this->actingAs($admin)
            ->patch("/admin/staff/{$admin->id}/status", ['status' => 'inactive'])
            ->assertStatus(403);

        $this->assertSame(0, DB::table('audit_logs')->count());
        $this->assertSame('active', $admin->refresh()->status);
    }

    public function test_cross_barangay_mutations_are_denied_without_audit_rows(): void
    {
        $admin = $this->portalAdmin();
        $foreign = $this->createStaff($this->other, 'clerk', [
            'staff_id' => 'FGN-001',
            'name' => 'Foreign Clerk',
            'email' => 'foreign.clerk@example.test',
        ]);

        $this->actingAs($admin)->put("/admin/staff/{$foreign->id}", [
            'name' => 'Hacked Name',
            'email' => $foreign->email,
            'staff_id' => $foreign->staff_id,
            'role' => 'senior',
            'department' => '',
            'password' => '',
        ])->assertStatus(403);

        $this->actingAs($admin)
            ->patch("/admin/staff/{$foreign->id}/status", ['status' => 'inactive'])
            ->assertStatus(403);

        $this->assertSame(0, DB::table('audit_logs')->count());
        $this->assertSame('Foreign Clerk', $foreign->refresh()->name);
        $this->assertSame('active', $foreign->refresh()->status);
    }

    public function test_super_admin_target_mutations_are_denied_without_audit_rows(): void
    {
        $admin = $this->portalAdmin();
        $superAdmin = $this->createStaff($this->barangay, 'super_admin', [
            'staff_id' => 'SUP-001',
            'name' => 'Dormant Super',
            'email' => 'dormant.super@example.test',
        ]);

        $this->actingAs($admin)->put("/admin/staff/{$superAdmin->id}", [
            'name' => 'Nope',
            'email' => $superAdmin->email,
            'staff_id' => $superAdmin->staff_id,
            'role' => 'clerk',
            'department' => '',
            'password' => '',
        ])->assertStatus(403);

        $this->actingAs($admin)
            ->patch("/admin/staff/{$superAdmin->id}/status", ['status' => 'inactive'])
            ->assertStatus(403);

        $this->assertSame(0, DB::table('audit_logs')->count());
        $this->assertSame('super_admin', $superAdmin->refresh()->role);
        $this->assertSame('active', $superAdmin->refresh()->status);
    }

    public function test_unauthorized_actors_record_no_audit_rows(): void
    {
        $target = $this->createStaff($this->barangay, 'clerk', [
            'staff_id' => 'CLK-100',
            'name' => 'Untouched Clerk',
            'email' => 'untouched.clerk@example.test',
        ]);

        $storePayload = [
            'name' => 'Blocked Creation',
            'email' => 'blocked.creation@example.test',
            'staff_id' => 'BLK-001',
            'role' => 'clerk',
            'department' => '',
            'password' => 'blocked-pass-123',
        ];

        $updatePayload = [
            'name' => 'Blocked Update',
            'email' => 'blocked.update@example.test',
            'staff_id' => 'BLK-002',
            'role' => 'senior',
            'department' => '',
            'password' => '',
        ];

        // Guest.
        $this->post('/admin/staff', $storePayload)
            ->assertRedirect(route('login'));

        // Clerk.
        $clerk = $this->createStaff($this->barangay, 'clerk', ['staff_id' => 'CLK-101']);

        $this->actingAs($clerk)->post('/admin/staff', $storePayload)->assertStatus(403);
        $this->actingAs($clerk)->put("/admin/staff/{$target->id}", $updatePayload)->assertStatus(403);
        $this->actingAs($clerk)
            ->patch("/admin/staff/{$target->id}/status", ['status' => 'inactive'])
            ->assertStatus(403);

        // Inactive admin.
        $inactive = $this->createStaff($this->barangay, 'admin', [
            'staff_id' => 'ADM-002',
            'status' => 'inactive',
        ]);

        $this->actingAs($inactive)->post('/admin/staff', $storePayload)->assertStatus(403);

        // Super admin (excluded from every role list).
        $superAdmin = $this->createStaff($this->barangay, 'super_admin', ['staff_id' => 'SUP-002']);

        $this->actingAs($superAdmin)->post('/admin/staff', $storePayload)->assertStatus(403);

        $this->assertSame(0, DB::table('audit_logs')->count());
        $this->assertSame('Untouched Clerk', $target->refresh()->name);
        $this->assertSame('active', $target->refresh()->status);
    }

    /*
    |--------------------------------------------------------------------------
    | Last-admin guard: observable positive paths
    |
    | `assertNotLastActiveAdmin` cannot be reached through HTTP: self-targeting
    | is rejected first and the acting active administrator always counts as a
    | remaining admin, so a demotion or deactivation that would leave zero
    | active admins is not constructible without editing unapproved files.
    | The constructible paths must each log exactly one event.
    |--------------------------------------------------------------------------
    */

    public function test_demoting_a_second_admin_records_exactly_one_role_event_when_an_active_admin_remains(): void
    {
        $admin = $this->portalAdmin();
        $second = $this->createStaff($this->barangay, 'admin', [
            'staff_id' => 'ADM-002',
            'name' => 'Second Admin',
            'email' => 'second.admin@example.test',
        ]);

        $this->actingAs($admin)->put("/admin/staff/{$second->id}", [
            'name' => $second->name,
            'email' => $second->email,
            'staff_id' => $second->staff_id,
            'role' => 'clerk',
            'department' => '',
            'password' => '',
        ])->assertRedirect();

        $rows = $this->rows();

        $this->assertCount(1, $rows);
        $this->assertSame('staff.role_changed', $rows[0]->event);
        $this->assertSame(
            ['previous_role' => 'admin', 'new_role' => 'clerk'],
            json_decode($rows[0]->context, true)
        );
        $this->assertSame('clerk', $second->refresh()->role);
    }

    public function test_deactivating_a_second_admin_records_exactly_one_status_event_when_an_active_admin_remains(): void
    {
        $admin = $this->portalAdmin();
        $second = $this->createStaff($this->barangay, 'admin', [
            'staff_id' => 'ADM-002',
            'name' => 'Second Admin',
            'email' => 'second.admin@example.test',
        ]);

        $this->actingAs($admin)
            ->patch("/admin/staff/{$second->id}/status", ['status' => 'inactive'])
            ->assertRedirect();

        $rows = $this->rows();

        $this->assertCount(1, $rows);
        $this->assertSame('staff.status_changed', $rows[0]->event);
        $this->assertSame(
            ['previous_status' => 'active', 'new_status' => 'inactive'],
            json_decode($rows[0]->context, true)
        );
        $this->assertSame('inactive', $second->refresh()->status);
    }

    /*
    |--------------------------------------------------------------------------
    | Privacy: allowlisted context, no identifiers or secrets
    |--------------------------------------------------------------------------
    */

    public function test_stored_contexts_contain_only_allowlisted_keys_and_no_identifiers_or_secrets(): void
    {
        $admin = $this->portalAdmin();
        $target = $this->createStaff($this->barangay, 'clerk', [
            'staff_id' => 'SCAN-001',
            'name' => 'Scan Target',
            'email' => 'scan.target@example.test',
        ]);

        $this->actingAs($admin)->post('/admin/staff', [
            'name' => 'Scan Clerk',
            'email' => 'scan.clerk@example.test',
            'staff_id' => 'SCAN-002',
            'role' => 'clerk',
            'department' => 'Records Office',
            'password' => 'ScanPass-Not-Logged!',
        ])->assertRedirect();

        $created = User::query()->where('email', 'scan.clerk@example.test')->firstOrFail();

        $this->actingAs($admin)->put("/admin/staff/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'staff_id' => $target->staff_id,
            'role' => 'senior',
            'department' => 'Senior Affairs',
            'password' => '',
        ])->assertRedirect();

        $this->actingAs($admin)
            ->patch("/admin/staff/{$target->id}/status", ['status' => 'inactive'])
            ->assertRedirect();

        $rows = $this->rows();

        $this->assertCount(3, $rows);
        $this->assertSame('staff.created', $rows[0]->event);
        $this->assertSame('staff.role_changed', $rows[1]->event);
        $this->assertSame('staff.status_changed', $rows[2]->event);

        $allowlist = [
            'action',
            'department',
            'entity_reference',
            'new_role',
            'new_status',
            'previous_role',
            'previous_status',
            'reason_code',
        ];

        $serialized = '';

        foreach ($rows as $row) {
            $context = $row->context === null ? null : json_decode($row->context, true);

            if ($context !== null) {
                $this->assertSame([], array_diff(array_keys($context), $allowlist));
            }

            $serialized .= json_encode((array) $row);
        }

        $forbidden = [
            'scan.target@example.test',
            'scan.clerk@example.test',
            'SCAN-001',
            'SCAN-002',
            'Scan Target',
            'Scan Clerk',
            'ScanPass-Not-Logged!',
            $created->password,
            '"password"',
        ];

        foreach ($forbidden as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Writer behaviour: fail-open on the business operation
    |--------------------------------------------------------------------------
    */

    public function test_writer_failure_does_not_block_staff_creation_or_update(): void
    {
        $admin = $this->portalAdmin();
        $target = $this->createStaff($this->barangay, 'clerk', [
            'staff_id' => 'CLK-900',
            'name' => 'Resilient Target',
            'email' => 'resilient.target@example.test',
        ]);

        $handler = Mockery::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')
            ->twice()
            ->with(Mockery::type(Throwable::class));
        $this->app->instance(ExceptionHandler::class, $handler);

        Schema::drop('audit_logs');

        $this->assertFalse(Schema::hasTable('audit_logs'));

        // Creation survives a failed audit write.
        $this->actingAs($admin)->post('/admin/staff', [
            'name' => 'Resilient Clerk',
            'email' => 'resilient.clerk@example.test',
            'staff_id' => 'CLK-901',
            'role' => 'clerk',
            'department' => '',
            'password' => 'resilient-pass-1',
        ])->assertRedirect();

        $created = User::query()->where('email', 'resilient.clerk@example.test')->firstOrFail();

        $this->assertSame('clerk', $created->role);
        $this->assertTrue(Hash::check('resilient-pass-1', $created->password));

        // Role change survives a failed audit write.
        $this->actingAs($admin)->put("/admin/staff/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'staff_id' => $target->staff_id,
            'role' => 'senior',
            'department' => '',
            'password' => '',
        ])->assertRedirect();

        $this->assertSame('senior', $target->refresh()->role);
    }
}
