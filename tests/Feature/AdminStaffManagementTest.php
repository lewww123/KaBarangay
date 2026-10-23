<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class AdminStaffManagementTest extends TestCase
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

    /*
    |--------------------------------------------------------------------------
    | Access control
    |--------------------------------------------------------------------------
    */

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/admin/staff')->assertRedirect(route('login'));
    }

    public function test_non_admin_roles_cannot_access_staff_management(): void
    {
        foreach (['clerk', 'senior', 'pwd', 'lupon'] as $role) {
            $user = $this->createStaff($this->barangay, $role);

            $this->actingAs($user)->get('/admin/staff')->assertStatus(403);
        }
    }

    public function test_super_admin_cannot_access_staff_management(): void
    {
        $superAdmin = $this->createStaff($this->barangay, 'super_admin');

        $this->actingAs($superAdmin)->get('/admin/staff')->assertStatus(403);
    }

    public function test_inactive_admin_cannot_access_staff_management(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin', ['status' => 'inactive']);

        $this->actingAs($admin)->get('/admin/staff')->assertStatus(403);
    }

    /*
    |--------------------------------------------------------------------------
    | Index scoping
    |--------------------------------------------------------------------------
    */

    public function test_index_lists_only_own_barangay_staff_and_hides_super_admin(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin', ['name' => 'Alpha Admin']);
        $clerk = $this->createStaff($this->barangay, 'clerk', ['name' => 'Bravo Clerk']);
        $superAdmin = $this->createStaff($this->barangay, 'super_admin', ['name' => 'Aardvark Super']);
        $foreign = $this->createStaff($this->other, 'clerk', ['name' => 'Aardvark Foreign']);

        $this->actingAs($admin)
            ->get('/admin/staff')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Staff')
                ->has('staff.data', 2)
                ->where('staff.data.0.email', $admin->email)
                ->where('staff.data.0.is_self', true)
                ->where('staff.data.1.email', $clerk->email)
                ->where('staff.data.1.is_self', false)
                ->where('stats.active', 2)
                ->where('stats.total', 2)
                ->has('filters')
                ->where('staff.current_page', 1)
                ->where('staff.per_page', 15));
    }

    /*
    |--------------------------------------------------------------------------
    | Create
    |--------------------------------------------------------------------------
    */

    public function test_admin_can_create_staff_account_with_hashed_password(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $response = $this->actingAs($admin)->post('/admin/staff', [
            'name' => 'New Clerk',
            'email' => 'new.clerk@example.test',
            'staff_id' => 'CLK-100',
            'role' => 'clerk',
            'department' => '',
            'password' => 'secret-pass-123',
        ]);

        $response->assertRedirect();

        $created = User::query()->where('email', 'new.clerk@example.test')->firstOrFail();

        $this->assertSame($this->barangay->id, $created->barangay_id);
        $this->assertSame('clerk', $created->role);
        $this->assertSame('Clerk', $created->department);
        $this->assertSame('active', $created->status);
        $this->assertNotSame('secret-pass-123', $created->password);
        $this->assertTrue(Hash::check('secret-pass-123', $created->password));
    }

    public function test_create_defaults_department_from_role_when_blank(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)->post('/admin/staff', [
            'name' => 'Senior Staffer',
            'email' => 'senior.staff@example.test',
            'staff_id' => 'SEN-100',
            'role' => 'senior_staff',
            'password' => 'secret-pass-123',
        ]);

        $created = User::query()->where('email', 'senior.staff@example.test')->firstOrFail();

        $this->assertSame('Senior Staff', $created->department);
    }

    public function test_create_rejects_privileged_and_legacy_roles(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        foreach (['super_admin', 'staff'] as $role) {
            $response = $this->actingAs($admin)
                ->from('/admin/staff')
                ->post('/admin/staff', [
                    'name' => 'Nope',
                    'email' => "nope.{$role}@example.test",
                    'staff_id' => "NOPE-{$role}",
                    'role' => $role,
                    'password' => 'secret-pass-123',
                ]);

            $response->assertSessionHasErrors('role');
        }
    }

    public function test_create_rejects_duplicate_email_and_staff_id(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $existing = $this->createStaff($this->barangay, 'clerk', [
            'email' => 'taken@example.test',
            'staff_id' => 'TAKEN-1',
        ]);

        $response = $this->actingAs($admin)
            ->from('/admin/staff')
            ->post('/admin/staff', [
                'name' => 'Duplicate',
                'email' => $existing->email,
                'staff_id' => $existing->staff_id,
                'role' => 'clerk',
                'password' => 'secret-pass-123',
            ]);

        $response->assertSessionHasErrors(['email', 'staff_id']);
    }

    public function test_create_validates_required_fields_and_password_length(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $response = $this->actingAs($admin)
            ->from('/admin/staff')
            ->post('/admin/staff', [
                'name' => '',
                'email' => 'not-an-email',
                'staff_id' => '',
                'role' => '',
                'password' => 'short',
            ]);

        $response->assertSessionHasErrors(['name', 'email', 'staff_id', 'role', 'password']);
    }

    /*
    |--------------------------------------------------------------------------
    | Update
    |--------------------------------------------------------------------------
    */

    public function test_admin_can_update_staff_details_without_touching_password(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $originalPassword = $clerk->password;

        $response = $this->actingAs($admin)->put("/admin/staff/{$clerk->id}", [
            'name' => 'Renamed Clerk',
            'email' => 'renamed.clerk@example.test',
            'staff_id' => 'CLK-200',
            'role' => 'senior',
            'department' => 'Senior Affairs',
            'password' => '',
        ]);

        $response->assertRedirect();

        $clerk->refresh();

        $this->assertSame('Renamed Clerk', $clerk->name);
        $this->assertSame('renamed.clerk@example.test', $clerk->email);
        $this->assertSame('CLK-200', $clerk->staff_id);
        $this->assertSame('senior', $clerk->role);
        $this->assertSame('Senior Affairs', $clerk->department);
        $this->assertSame($originalPassword, $clerk->password);
    }

    public function test_admin_can_change_staff_password_when_provided(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($admin)->put("/admin/staff/{$clerk->id}", [
            'name' => $clerk->name,
            'email' => $clerk->email,
            'staff_id' => $clerk->staff_id ?? 'CLK-300',
            'role' => 'clerk',
            'department' => '',
            'password' => 'brand-new-pass-1',
        ]);

        $clerk->refresh();

        $this->assertTrue(Hash::check('brand-new-pass-1', $clerk->password));
    }

    public function test_admin_can_demote_another_admin_when_an_active_admin_remains(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin', ['name' => 'First Admin']);
        $second = $this->createStaff($this->barangay, 'admin', ['name' => 'Second Admin']);

        $this->actingAs($admin)->put("/admin/staff/{$second->id}", [
            'name' => $second->name,
            'email' => $second->email,
            'staff_id' => $second->staff_id ?? 'ADM-2',
            'role' => 'clerk',
            'department' => '',
            'password' => '',
        ])->assertRedirect();

        $this->assertSame('clerk', $second->refresh()->role);
    }

    public function test_admin_cannot_change_own_role(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)->put("/admin/staff/{$admin->id}", [
            'name' => $admin->name,
            'email' => $admin->email,
            'staff_id' => $admin->staff_id ?? 'ADM-1',
            'role' => 'clerk',
            'department' => '',
            'password' => '',
        ])->assertStatus(403);

        $this->assertSame('admin', $admin->refresh()->role);
    }

    public function test_admin_cannot_update_staff_from_another_barangay(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $foreign = $this->createStaff($this->other, 'clerk');

        $this->actingAs($admin)->put("/admin/staff/{$foreign->id}", [
            'name' => 'Hacked',
            'email' => $foreign->email,
            'staff_id' => $foreign->staff_id ?? 'FGN-1',
            'role' => 'clerk',
            'department' => '',
            'password' => '',
        ])->assertStatus(403);

        $this->assertNotSame('Hacked', $foreign->refresh()->name);
    }

    public function test_admin_cannot_update_super_admin_account(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $superAdmin = $this->createStaff($this->barangay, 'super_admin');

        $this->actingAs($admin)->put("/admin/staff/{$superAdmin->id}", [
            'name' => 'Nope',
            'email' => $superAdmin->email,
            'staff_id' => $superAdmin->staff_id ?? 'SUP-1',
            'role' => 'clerk',
            'department' => '',
            'password' => '',
        ])->assertStatus(403);
    }

    /*
    |--------------------------------------------------------------------------
    | Status toggling
    |--------------------------------------------------------------------------
    */

    public function test_admin_can_disable_and_reenable_staff(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($admin)
            ->patch("/admin/staff/{$clerk->id}/status", ['status' => 'inactive'])
            ->assertRedirect();
        $this->assertSame('inactive', $clerk->refresh()->status);

        $this->actingAs($admin)
            ->patch("/admin/staff/{$clerk->id}/status", ['status' => 'active'])
            ->assertRedirect();
        $this->assertSame('active', $clerk->refresh()->status);
    }

    public function test_status_update_validates_status_value(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($admin)
            ->from('/admin/staff')
            ->patch("/admin/staff/{$clerk->id}/status", ['status' => 'deleted'])
            ->assertSessionHasErrors('status');
    }

    public function test_admin_cannot_disable_own_account(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->patch("/admin/staff/{$admin->id}/status", ['status' => 'inactive'])
            ->assertStatus(403);

        $this->assertSame('active', $admin->refresh()->status);
    }

    public function test_admin_cannot_change_status_of_another_barangay_staff(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $foreign = $this->createStaff($this->other, 'clerk');

        $this->actingAs($admin)
            ->patch("/admin/staff/{$foreign->id}/status", ['status' => 'inactive'])
            ->assertStatus(403);

        $this->assertSame('active', $foreign->refresh()->status);
    }

    public function test_search_by_name_email_staff_id_department_role(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->createStaff($this->barangay, 'clerk', ['name' => 'Maria Santos']);
        $this->createStaff($this->barangay, 'clerk', ['email' => 'unique@example.test']);
        $this->createStaff($this->barangay, 'senior', ['staff_id' => 'SEN-555']);
        $this->createStaff($this->barangay, 'pwd', ['department' => 'PWD Affairs']);
        $this->createStaff($this->barangay, 'lupon', ['role' => 'lupon']);

        $this->actingAs($admin)
            ->get('/admin/staff?search=Santos')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('staff.data', 1));

        $this->actingAs($admin)
            ->get('/admin/staff?search=unique@example.test')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('staff.data', 1));

        $this->actingAs($admin)
            ->get('/admin/staff?search=SEN-555')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('staff.data', 1));

        $this->actingAs($admin)
            ->get('/admin/staff?search=PWD Affairs')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('staff.data', 1));

        $this->actingAs($admin)
            ->get('/admin/staff?search=lupon')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('staff.data', 1));
    }

    public function test_role_and_status_filters_and_combination(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->createStaff($this->barangay, 'clerk');
        $this->createStaff($this->barangay, 'clerk', ['status' => 'inactive']);
        $this->createStaff($this->barangay, 'senior');

        $this->actingAs($admin)
            ->get('/admin/staff?role=clerk')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('staff.data', 2));

        $this->actingAs($admin)
            ->get('/admin/staff?status=inactive')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('staff.data', 1));

        $this->actingAs($admin)
            ->get('/admin/staff?role=clerk&status=inactive')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('staff.data', 1));

        $this->actingAs($admin)
            ->get('/admin/staff?role=clerk&status=active')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('staff.data', 1));
    }

    public function test_pagination_and_filters_persist(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        for ($i = 0; $i < 20; $i++) {
            $this->createStaff($this->barangay, 'clerk', ['name' => "Clerk {$i}"]);
        }

        $this->actingAs($admin)
            ->get('/admin/staff')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('staff.current_page', 1)
                ->where('staff.per_page', 15)
                ->where('staff.total', 21)
                ->has('staff.data', 15));

        $this->actingAs($admin)
            ->get('/admin/staff?page=2')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('staff.current_page', 2)
                ->has('staff.data', 6));

        $this->actingAs($admin)
            ->get('/admin/staff?search=Clerk&role=clerk')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('staff.current_page', 1)
                ->where('filters.search', 'Clerk')
                ->where('filters.role', 'clerk'));
    }

    public function test_filters_do_not_expose_other_barangay_or_super_admin(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->createStaff($this->other, 'clerk', ['name' => 'Foreign Clerk']);
        $this->createStaff($this->barangay, 'super_admin', ['name' => 'Hidden Super']);

        $this->actingAs($admin)
            ->get('/admin/staff?search=Foreign')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('staff.data', 0));

        $this->actingAs($admin)
            ->get('/admin/staff?search=Super')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('staff.data', 0));
    }

    public function test_invalid_filters_are_ignored_safely(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($admin)
            ->get('/admin/staff?role=super_admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('staff.data', 2)
                ->where('filters.role', ''));

        $this->actingAs($admin)
            ->get('/admin/staff?status=deleted')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('staff.data', 2)
                ->where('filters.status', ''));
    }
}
