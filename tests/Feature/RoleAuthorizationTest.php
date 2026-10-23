<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\ResidentRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class RoleAuthorizationTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
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
        $pwd = $this->createStaff($this->barangay, 'pwd');

        $this->actingAs($pwd)
            ->get('/pwd/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Pwd/Dashboard'));
    }

    public function test_pwd_staff_and_senior_staff_can_access_their_portals(): void
    {
        $pwdStaff = $this->createStaff($this->barangay, 'pwd_staff');
        $seniorStaff = $this->createStaff($this->barangay, 'senior_staff');

        $this->actingAs($pwdStaff)->get('/pwd/dashboard')->assertOk();
        $this->actingAs($seniorStaff)->get('/senior/dashboard')->assertOk();
    }

    public function test_senior_and_clerk_can_access_their_portals(): void
    {
        $senior = $this->createStaff($this->barangay, 'senior');
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($senior)->get('/senior/dashboard')->assertOk();
        $this->actingAs($clerk)->get('/clerk/dashboard')->assertOk();
    }

    public function test_admin_can_access_every_portal(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)->get('/admin')->assertOk();
        $this->actingAs($admin)->get('/clerk/dashboard')->assertOk();
        $this->actingAs($admin)->get('/senior/dashboard')->assertOk();
        $this->actingAs($admin)->get('/pwd/dashboard')->assertOk();
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
        $this->createStaff($this->barangay, 'lupon', [
            'staff_id' => 'LUP-001',
            'name' => 'Lupon User',
        ]);

        $response = $this
            ->withSession([
                'selected_barangay_id' => $this->barangay->id,
                'login_department' => 'lupon',
            ])
            ->post('/staff-login', [
                'staff_id' => 'LUP-001',
                'full_name' => 'Lupon User',
                'password' => 'password',
            ]);

        $response->assertRedirect(route('lupon.dashboard'));
        $this->assertAuthenticated();
    }

    public function test_unknown_role_login_redirects_to_barangay_selection(): void
    {
        $this->createStaff($this->barangay, 'staff', [
            'staff_id' => 'STF-001',
            'name' => 'Legacy Staff',
        ]);

        $response = $this
            ->withSession([
                'selected_barangay_id' => $this->barangay->id,
                'login_department' => 'staff',
            ])
            ->post('/staff-login', [
                'staff_id' => 'STF-001',
                'full_name' => 'Legacy Staff',
                'password' => 'password',
            ]);

        $response->assertRedirect(route('barangay.select'));
        $response->assertSessionHas('info');
    }

    public function test_pwd_role_login_redirects_to_pwd_dashboard(): void
    {
        $this->createStaff($this->barangay, 'pwd', [
            'staff_id' => 'PWD-001',
            'name' => 'Pwd User',
        ]);

        $this
            ->withSession([
                'selected_barangay_id' => $this->barangay->id,
                'login_department' => 'pwd',
            ])
            ->post('/staff-login', [
                'staff_id' => 'PWD-001',
                'full_name' => 'Pwd User',
                'password' => 'password',
            ])
            ->assertRedirect(route('pwd.dashboard'));
    }

    public function test_admin_login_redirects_to_admin_dashboard(): void
    {
        $this->createStaff($this->barangay, 'admin', [
            'staff_id' => 'ADM-001',
            'name' => 'Admin User',
        ]);

        $this
            ->withSession([
                'selected_barangay_id' => $this->barangay->id,
                'login_department' => 'admin',
            ])
            ->post('/staff-login', [
                'staff_id' => 'ADM-001',
                'full_name' => 'Admin User',
                'password' => 'password',
            ])
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_any_authenticated_role_can_log_out(): void
    {
        $lupon = $this->createStaff($this->barangay, 'lupon');

        $this->actingAs($lupon)
            ->post('/logout')
            ->assertRedirect(route('barangay.select'));
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
