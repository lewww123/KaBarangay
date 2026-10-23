<?php

namespace Tests\Feature;

use App\Models\Barangay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class LuponPortalAuthorizationTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Barangay $otherBarangay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $this->otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
    }

    public function test_guest_is_redirected_to_login_from_lupon_portal(): void
    {
        $this->get('/lupon/dashboard')->assertRedirect(route('login'));
    }

    public function test_active_lupon_staff_can_access_the_dashboard(): void
    {
        $lupon = $this->createStaff($this->barangay, 'lupon', [
            'staff_id' => 'LUP-001',
            'name' => 'Lupon User',
        ]);

        $this->actingAs($lupon)
            ->get('/lupon/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Lupon/Dashboard'));
    }

    public function test_inactive_lupon_staff_is_denied(): void
    {
        $inactive = $this->createStaff($this->barangay, 'lupon', ['status' => 'inactive']);

        $this->actingAs($inactive)->get('/lupon/dashboard')->assertStatus(403);
    }

    public function test_admin_is_denied_on_the_lupon_portal(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)->get('/lupon/dashboard')->assertStatus(403);
    }

    public function test_super_admin_is_denied_on_the_lupon_portal(): void
    {
        $superAdmin = $this->createStaff($this->barangay, 'super_admin');

        $this->actingAs($superAdmin)->get('/lupon/dashboard')->assertStatus(403);
    }

    public function test_other_staff_roles_are_denied_on_the_lupon_portal(): void
    {
        foreach (['clerk', 'senior', 'senior_staff', 'pwd', 'pwd_staff'] as $role) {
            $staff = $this->createStaff($this->barangay, $role);

            $this->actingAs($staff)->get('/lupon/dashboard')->assertStatus(403);
        }
    }

    public function test_lupon_staff_cannot_access_other_portals(): void
    {
        $lupon = $this->createStaff($this->barangay, 'lupon');

        foreach (['/admin', '/clerk/dashboard', '/senior/dashboard', '/pwd/dashboard'] as $path) {
            $this->actingAs($lupon)->get($path)->assertStatus(403);
        }
    }

    public function test_lupon_staff_cannot_use_the_staff_qr_api(): void
    {
        $lupon = $this->createStaff($this->barangay, 'lupon');

        $this->actingAs($lupon)
            ->getJson('/api/qr/verify-token?token=TEST')
            ->assertStatus(403);
    }

    public function test_successful_lupon_login_redirects_to_the_lupon_dashboard(): void
    {
        $this->createStaff($this->barangay, 'lupon', [
            'staff_id' => 'LUP-002',
            'name' => 'Lupon User',
        ]);

        $response = $this
            ->withSession([
                'selected_barangay_id' => $this->barangay->id,
                'login_department' => 'lupon',
            ])
            ->post('/staff-login', [
                'staff_id' => 'LUP-002',
                'full_name' => 'Lupon User',
                'password' => 'password',
            ]);

        $response->assertRedirect(route('lupon.dashboard'));
        $this->assertAuthenticated();
    }

    public function test_other_roles_keep_their_existing_post_login_redirects(): void
    {
        $this->assertSame(
            route('clerk.dashboard'),
            $this->attemptStaffLogin('clerk', 'CLK-001', 'Clerk User')
        );
        $this->assertSame(
            route('senior.dashboard'),
            $this->attemptStaffLogin('senior', 'SNR-001', 'Senior User')
        );
        $this->assertSame(
            route('pwd.dashboard'),
            $this->attemptStaffLogin('pwd', 'PWD-001', 'Pwd User')
        );
        $this->assertSame(
            route('barangay.select'),
            $this->attemptStaffLogin('staff', 'STF-001', 'Legacy Staff')
        );
        $this->assertSame(
            route('admin.dashboard'),
            $this->attemptStaffLogin('admin', 'ADM-001', 'Admin User')
        );
    }

    public function test_dashboard_payload_contains_no_case_data_or_mock_metrics(): void
    {
        $lupon = $this->createStaff($this->barangay, 'lupon');

        $this->actingAs($lupon)
            ->get('/lupon/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Lupon/Dashboard')
                ->has('userName')
                ->missing('stats')
                ->missing('cases')
                ->missing('luponCases')
                ->missing('recentAnnouncements')
                ->missing('mockComplaints')
                ->missing('complaints')
            );
    }

    public function test_dashboard_exposes_only_the_authenticated_staff_own_barangay(): void
    {
        $mine = $this->createStaff($this->barangay, 'lupon', ['name' => 'Lupon One']);
        $this->createStaff($this->otherBarangay, 'lupon', ['name' => 'Lupon Two']);

        $this->actingAs($mine)
            ->get('/lupon/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Lupon/Dashboard')
                ->where('userName', 'Lupon One')
                ->where('barangayName', 'Barangay One')
                ->missing('cases')
            );
    }

    public function test_dashboard_page_source_shows_the_approved_empty_state(): void
    {
        $source = file_get_contents(resource_path('js/pages/Lupon/Dashboard.tsx'));

        $this->assertStringContainsString(
            'Lupon portal is ready for setup. Case management is not yet available.',
            $source
        );

        foreach (['initialComplaints', 'LUP-2026', 'Juan Dela Cruz'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }

    public function test_resident_lupon_prototype_still_renders_unchanged(): void
    {
        $purok = $this->createPurok($this->barangay);
        $resident = $this->createResident($this->barangay, $purok, [
            'mobile' => '09170000081',
        ]);

        $this->withSession(['resident_id' => $resident->id])
            ->get('/resident/lupon')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Resident/Lupon'));
    }

    private function attemptStaffLogin(string $department, string $staffId, string $name): string
    {
        $this->createStaff($this->barangay, $department, [
            'staff_id' => $staffId,
            'name' => $name,
        ]);

        $response = $this
            ->withSession([
                'selected_barangay_id' => $this->barangay->id,
                'login_department' => $department,
            ])
            ->post('/staff-login', [
                'staff_id' => $staffId,
                'full_name' => $name,
                'password' => 'password',
            ]);

        return $response->headers->get('Location') ?? '';
    }
}
