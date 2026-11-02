<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Purok;
use App\Models\ResidentRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class RoleAuthorizationTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Purok $purok;

    protected function setUp(): void
    {
        parent::setUp();

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $this->purok = $this->createPurok($this->barangay);
    }

    public function test_guests_are_redirected_to_login_from_protected_portals(): void
    {
        $this->get('/admin')->assertRedirect(route('login'));
        $this->get('/clerk/dashboard')->assertRedirect(route('login'));
        $this->get('/senior/dashboard')->assertRedirect(route('login'));
        $this->get('/pwd/dashboard')->assertRedirect(route('login'));
    }

    public function test_clerk_cannot_access_admin_portal(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)->get('/admin')->assertForbidden();
    }

    public function test_clerk_cannot_approve_announcements(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $announcement = $this->createAnnouncement($this->barangay, [
            'approval_status' => 'pending',
            'is_published' => false,
        ]);

        $this->actingAs($clerk)
            ->post("/admin/announcements/{$announcement->id}/approve")
            ->assertForbidden();
    }

    public function test_senior_cannot_approve_resident_registrations(): void
    {
        $senior = $this->createStaff($this->barangay, 'senior');
        $purok = $this->createPurok($this->barangay);
        $this->createResident($this->barangay, $purok);

        $registration = ResidentRegistration::query()->firstOrFail();

        $this->actingAs($senior)
            ->post('/admin/residents/'.$registration->id.'/approve')
            ->assertForbidden();
    }

    public function test_non_clerk_cannot_generate_certificates(): void
    {
        $senior = $this->createStaff($this->barangay, 'senior');

        $this->actingAs($senior)
            ->post('/clerk/generate-certificate')
            ->assertStatus(403);
    }

    public function test_pwd_role_can_access_pwd_portal(): void
    {
        $purok = $this->createPurok($this->barangay);
        $pwd = $this->createAssignedStaff(['barangay' => $this->barangay, 'purok' => $purok], 'pwd', 'PWD Staff');

        $this->actingAs($pwd)
            ->get('/pwd/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Pwd/Dashboard'));
    }

    public function test_pwd_staff_and_senior_staff_can_access_their_portals(): void
    {
        $purok = $this->createPurok($this->barangay);
        $pwdStaff = $this->createAssignedStaff(['barangay' => $this->barangay, 'purok' => $purok], 'pwd', 'PWD Staff');
        $seniorStaff = $this->createAssignedStaff(['barangay' => $this->barangay, 'purok' => $purok], 'senior', 'Senior Staff');

        $this->actingAs($pwdStaff)->get('/pwd/dashboard')->assertOk();
        $this->actingAs($seniorStaff)->get('/senior/dashboard')->assertOk();
    }

    public function test_senior_and_clerk_can_access_their_portals(): void
    {
        $purok = $this->createPurok($this->barangay);
        $senior = $this->createAssignedStaff(['barangay' => $this->barangay, 'purok' => $purok], 'senior', 'Senior Staff');
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($senior)->get('/senior/dashboard')->assertOk();
        $this->actingAs($clerk)->get('/clerk/dashboard')->assertOk();
    }

    public function test_assigned_admin_cannot_bypass_sector_assignment_checks(): void
    {
        $purok = $this->createPurok($this->barangay);
        $admin = $this->createAssignedStaff(['barangay' => $this->barangay, 'purok' => $purok], 'admin', 'Barangay Admin');

        $this->actingAs($admin)->get('/admin')->assertOk();
        $this->actingAs($admin)->get('/clerk/dashboard')->assertForbidden();
        $this->actingAs($admin)->get('/senior/dashboard')->assertForbidden();
        $this->actingAs($admin)->get('/pwd/dashboard')->assertForbidden();
    }

    public function test_admin_is_denied_every_clerk_workspace(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        foreach ([
            '/clerk/dashboard',
            '/clerk/certificates',
            '/clerk/disbursements',
            '/clerk/citizen-charter',
            '/clerk/org-chart',
            '/clerk/announcements',
            '/clerk/updates',
            '/clerk/programs',
        ] as $uri) {
            $this->actingAs($admin)->get($uri)->assertForbidden();
        }
    }

    public function test_clerk_is_denied_every_admin_workspace(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        foreach ([
            '/admin',
            '/admin/residents',
            '/admin/certificates',
            '/admin/staff',
            '/admin/settings',
            '/admin/reports',
            '/admin/audit-log',
        ] as $uri) {
            $this->actingAs($clerk)->get($uri)->assertForbidden();
        }
    }

    public function test_lupon_cannot_access_admin_portal(): void
    {
        $lupon = $this->createStaff($this->barangay, 'lupon');

        $this->actingAs($lupon)->get('/admin')->assertStatus(403);
    }

    public function test_super_admin_is_denied_on_every_portal(): void
    {
        $superAdmin = $this->createStaff($this->barangay, 'super_admin');

        $this->actingAs($superAdmin)->get('/admin')->assertStatus(403);
        $this->actingAs($superAdmin)->get('/clerk/dashboard')->assertStatus(403);
        $this->actingAs($superAdmin)->get('/senior/dashboard')->assertStatus(403);
        $this->actingAs($superAdmin)->get('/pwd/dashboard')->assertStatus(403);
    }

    public function test_inactive_staff_account_is_denied(): void
    {
        $inactive = $this->createStaff($this->barangay, 'clerk', ['status' => 'inactive']);

        $this->actingAs($inactive)->get('/clerk/dashboard')->assertStatus(403);
    }

    public function test_lupon_login_redirects_to_lupon_dashboard(): void
    {
        $user = $this->createAssignedStaff([
            'barangay' => $this->barangay,
            'purok' => $this->purok,
            'first_name' => 'Lupon',
            'last_name' => 'User',
        ], 'lupon', 'Lupon');

        $response = $this
            ->post('/login', [
                'barangay_id' => $this->barangay->id,
                'department' => 'lupon',
                'position' => 'Lupon',
                'full_name' => $user->resident->full_name,
                'password' => 'password',
            ]);

        $response->assertRedirect(route('lupon.dashboard'));
        $this->assertAuthenticated();
    }

    public function test_unknown_department_is_rejected(): void
    {
        $response = $this
            ->from('/')
            ->post('/login', [
                'barangay_id' => $this->barangay->id,
                'department' => 'staff',
                'position' => 'Staff',
                'full_name' => 'Legacy Staff',
                'password' => 'password',
            ]);

        $response->assertSessionHasErrors('department');
    }

    public function test_pwd_role_login_redirects_to_pwd_dashboard(): void
    {
        $user = $this->createAssignedStaff([
            'barangay' => $this->barangay,
            'purok' => $this->purok,
            'first_name' => 'Pwd',
            'last_name' => 'User',
        ], 'pwd', 'Pwd');

        $this
            ->post('/login', [
                'barangay_id' => $this->barangay->id,
                'department' => 'pwd',
                'position' => 'Pwd',
                'full_name' => $user->resident->full_name,
                'password' => 'password',
            ])
            ->assertRedirect(route('pwd.dashboard'));
    }

    public function test_admin_login_redirects_to_admin_dashboard(): void
    {
        $user = $this->createAssignedStaff([
            'barangay' => $this->barangay,
            'purok' => $this->purok,
            'first_name' => 'Admin',
            'last_name' => 'User',
        ], 'admin', 'Admin');

        $this
            ->post('/login', [
                'barangay_id' => $this->barangay->id,
                'department' => 'admin',
                'position' => 'Admin',
                'full_name' => $user->resident->full_name,
                'password' => 'password',
            ])
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_clerk_login_redirects_to_clerk_dashboard(): void
    {
        $user = $this->createAssignedStaff([
            'barangay' => $this->barangay,
            'purok' => $this->purok,
            'first_name' => 'Clerk',
            'last_name' => 'User',
        ], 'clerk', 'Clerk');

        $this
            ->post('https://staff.kabarangay.com/login', [
                'barangay_id' => $this->barangay->id,
                'department' => 'clerk',
                'position' => 'Clerk',
                'full_name' => $user->resident->full_name,
                'password' => 'password',
            ])
            ->assertRedirect(route('clerk.dashboard'));
    }

    public function test_any_authenticated_role_can_log_out(): void
    {
        $lupon = $this->createStaff($this->barangay, 'lupon');

        $this->actingAs($lupon)
            ->post('/logout')
            ->assertRedirect(route('login'));
    }

    public function test_staff_qr_api_requires_authentication(): void
    {
        $this->getJson('/api/qr/verify-token?token=TEST')->assertStatus(401);
    }

    public function test_lupon_cannot_use_staff_qr_api(): void
    {
        $lupon = $this->createStaff($this->barangay, 'lupon');

        $this->actingAs($lupon)
            ->getJson('/api/qr/verify-token?token=TEST')
            ->assertStatus(403);
    }

    public function test_certificate_generation_rejects_unsupported_type(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)
            ->from('/clerk/certificates')
            ->post('/clerk/generate-certificate', [
                'certificate_type' => 'Totally Unsupported',
                'resident_name' => 'Juan Dela Cruz',
                'purok' => 'Purok 1',
                'purpose' => 'Employment',
                'control_no' => 'CTRL-2026-0001',
                'fee' => '50',
            ]);

        $response->assertSessionHasErrors('certificate_type');
    }

    public function test_certificate_generation_rejects_missing_required_fields(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)
            ->from('/clerk/certificates')
            ->post('/clerk/generate-certificate', [
                // The Residency certificate has its own detail set: it takes
                // the resident identity from the linked resident profile and
                // collects no purpose, control number, or signatory, so the
                // generic required fields are asserted with a type that still
                // uses them.
                'certificate_type' => 'Indigency',
            ]);

        $response->assertSessionHasErrors(['resident_name', 'purpose', 'control_no', 'fee']);
    }
}
