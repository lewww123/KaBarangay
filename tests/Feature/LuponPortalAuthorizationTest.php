<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\LuponCase;
use App\Models\LuponCaseEvent;
use App\Models\LuponComplaint;
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

        $this->actingAs($inactive)->get('/lupon/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_admin_is_denied_on_the_lupon_portal(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)->get('/lupon/dashboard')->assertStatus(403);
        $this->actingAs($admin)->get('/lupon/hearings')->assertStatus(403);
    }

    public function test_super_admin_is_denied_on_the_lupon_portal(): void
    {
        $superAdmin = $this->createStaff($this->barangay, 'super_admin');
        $assignment = \App\Models\StaffAssignment::create([
            'user_id' => $superAdmin->id,
            'resident_id' => $superAdmin->resident_id,
            'barangay_id' => $this->barangay->id,
            'department' => 'super_admin',
            'position' => 'Super Admin',
            'is_active' => true,
        ]);

        $this->actingAs($superAdmin)
            ->withSession(['staff_assignment_id' => $assignment->id])
            ->get('/lupon/dashboard')
            ->assertStatus(403);
    }

    public function test_other_staff_roles_are_denied_on_the_lupon_portal(): void
    {
        foreach (['clerk', 'senior', 'senior_staff', 'pwd', 'pwd_staff'] as $role) {
            $staff = $this->createStaff($this->barangay, $role);

            $this->actingAs($staff)->get('/lupon/dashboard')->assertStatus(403);
            $this->actingAs($staff)->get('/lupon/hearings')->assertStatus(403);
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
            'name' => 'Lupon User',
        ]);

        $response = $this
            ->post('/login', [
                'barangay_id' => $this->barangay->id,
                'department' => 'lupon',
                'position' => 'Lupon',
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
            $this->attemptStaffLogin('clerk', 'Clerk User')
        );
        $this->assertSame(
            route('senior.dashboard'),
            $this->attemptStaffLogin('senior', 'Senior User')
        );
        $this->assertSame(
            route('pwd.dashboard'),
            $this->attemptStaffLogin('pwd', 'Pwd User')
        );
        $this->assertSame(
            route('admin.dashboard'),
            $this->attemptStaffLogin('admin', 'Admin User')
        );
    }

    public function test_dashboard_exposes_only_barangay_scoped_operational_metrics(): void
    {
        $lupon = $this->createStaff($this->barangay, 'lupon');

        $this->actingAs($lupon)
            ->get('/lupon/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Lupon/Dashboard')
                ->has('userName')
                ->has('metrics')
                ->has('deadlines')
                ->has('schedule')
                ->has('recentActivity')
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

    public function test_dashboard_page_links_to_existing_lupon_work_queues_without_mock_data(): void
    {
        $source = file_get_contents(resource_path('js/pages/Lupon/Dashboard.tsx'));

        foreach (['/lupon/complaints', '/lupon/cases', '/lupon/hearings', 'Case Desk Overview'] as $expected) {
            $this->assertStringContainsString($expected, $source);
        }

        $this->assertStringNotContainsString('Case management is not yet available.', $source);

        foreach (['initialComplaints', 'LUP-2026', 'Juan Dela Cruz'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }

    public function test_lupon_navigation_matches_the_restructured_portal_and_keeps_legacy_routes(): void
    {
        $layout = file_get_contents(resource_path('js/layouts/LuponLayout.tsx'));
        $cases = file_get_contents(resource_path('js/pages/Lupon/Cases/Index.tsx'));

        foreach ([
            'Overview',
            'Complaint Submissions',
            'All Cases',
            'Mediation',
            'Conciliation',
            'Arbitration',
            'Resolved',
            'Lupon Tagapamayapa',
            '/lupon/cases?stage=mediation',
            '/lupon/cases?stage=conciliation',
            '/lupon/cases?stage=arbitration',
            '/lupon/cases?stage=resolved',
            'activeStage === item.id',
            'aria-current',
        ] as $expected) {
            $this->assertStringContainsString($expected, $layout);
        }

        foreach ([
            'Payment Verification',
            'Pangkat & Conciliation',
            'Arbitration & Awards',
            'Summons & Documents',
            'Case Records',
            'My Profile',
        ] as $removed) {
            $this->assertStringNotContainsString($removed, $layout);
        }

        foreach ([
            'All Cases',
            'Mediation',
            'Conciliation',
            'Arbitration',
            'Resolved',
            'caseStage={stage}',
            '/lupon/cases/${c.id}',
        ] as $expected) {
            $this->assertStringContainsString($expected, $cases);
        }

        $lupon = $this->createStaff($this->barangay, 'lupon');

        foreach ([
            '/lupon/payments',
            '/lupon/hearings',
            '/lupon/pangkat',
            '/lupon/arbitration',
            '/lupon/documents',
            '/lupon/records',
            '/lupon/reports',
            '/lupon/profile',
        ] as $legacyRoute) {
            $this->actingAs($lupon)->get($legacyRoute)->assertOk();
        }

        $this->actingAs($lupon)
            ->get('/lupon/tagapamayapa')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Lupon/Tagapamayapa'));
    }

    public function test_dashboard_metrics_cover_the_new_case_stages_and_recent_activity(): void
    {
        $lupon = $this->createStaff($this->barangay, 'lupon');
        $cases = [
            ['KP-METRIC-MED', 'Kapitan', 'mediation_active'],
            ['KP-METRIC-CON', 'Lupon', 'conciliation_active'],
            ['KP-METRIC-ARB', 'Arbitration', 'arbitration_active'],
            ['KP-METRIC-RES', 'Closed', 'settled'],
            ['KP-METRIC-FILED', 'Kapitan', 'filed'],
        ];

        foreach ($cases as [$caseNumber, $phase, $status]) {
            LuponCase::create([
                'payment_status' => 'paid',
                'barangay_id' => $this->barangay->id,
                'origin' => 'manual',
                'case_number' => $caseNumber,
                'title' => $caseNumber.' title',
                'case_type' => 'Debt / Money Dispute',
                'phase' => $phase,
                'status' => $status,
                'created_by' => $lupon->id,
                'assigned_to' => $lupon->id,
                'filed_date' => now()->toDateString(),
            ]);
        }

        $currentCase = LuponCase::where('case_number', 'KP-METRIC-MED')->firstOrFail();

        foreach ([
            ['LUP-METRIC-PENDING', 'Pending Payment', null],
            ['LUP-METRIC-FOR-PAYMENT', 'For Payment', 100],
            ['LUP-METRIC-SUBMITTED', 'Submitted', null],
            ['LUP-METRIC-REJECTED', 'Rejected', null],
        ] as [$reference, $status, $fee]) {
            LuponComplaint::create([
                'barangay_id' => $this->barangay->id,
                'resident_id' => null,
                'reference' => $reference,
                'respondent_name' => 'Metric Respondent',
                'respondent_resident_status' => 'Unknown',
                'complaint_type' => 'Debt / Money Dispute',
                'incident_date' => now()->subDay()->toDateString(),
                'incident_location' => 'Purok One',
                'narrative' => 'Metric test complaint.',
                'requested_action' => 'Mediation.',
                'status' => $status,
                'assessed_fee' => $fee,
                'submitted_at' => now(),
            ]);
        }

        LuponCaseEvent::create([
            'case_id' => $currentCase->id,
            'from_status' => 'filed',
            'to_status' => 'mediation_active',
            'occurred_at' => now(),
            'notes' => 'Current barangay activity.',
        ]);

        $foreignBarangay = $this->createBarangay('Metric Foreign', 'METRIC-FOREIGN');
        $foreignStaff = $this->createStaff($foreignBarangay, 'lupon');
        $foreignCase = LuponCase::create([
            'payment_status' => 'paid',
            'barangay_id' => $foreignBarangay->id,
            'origin' => 'manual',
            'case_number' => 'KP-METRIC-FOREIGN',
            'title' => 'Foreign metric case',
            'case_type' => 'Debt / Money Dispute',
            'phase' => 'Kapitan',
            'status' => 'mediation_active',
            'created_by' => $foreignStaff->id,
            'assigned_to' => $foreignStaff->id,
            'filed_date' => now()->toDateString(),
        ]);
        LuponComplaint::create([
            'barangay_id' => $foreignBarangay->id,
            'resident_id' => null,
            'reference' => 'LUP-METRIC-FOREIGN',
            'respondent_name' => 'Foreign Respondent',
            'respondent_resident_status' => 'Unknown',
            'complaint_type' => 'Debt / Money Dispute',
            'incident_date' => now()->subDay()->toDateString(),
            'incident_location' => 'Foreign Purok',
            'narrative' => 'Foreign complaint.',
            'requested_action' => 'Mediation.',
            'status' => 'For Payment',
            'assessed_fee' => 100,
            'submitted_at' => now(),
        ]);
        LuponCaseEvent::create([
            'case_id' => $foreignCase->id,
            'from_status' => 'filed',
            'to_status' => 'mediation_active',
            'occurred_at' => now()->addMinute(),
            'notes' => 'Foreign activity.',
        ]);

        $this->actingAs($lupon)
            ->get('/lupon/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Lupon/Dashboard')
                ->where('metrics.filed', 5)
                ->where('metrics.activeMediation', 1)
                ->where('metrics.activeConciliation', 1)
                ->where('metrics.arbitration', 1)
                ->where('metrics.resolved', 1)
                ->where('metrics.pendingComplaints', 3)
                ->where('metrics.awaitingPayment', 2)
                ->has('recentActivity', 1)
                ->where('recentActivity.0.caseNumber', 'KP-METRIC-MED'));
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

    private function attemptStaffLogin(string $department, string $name): string
    {
        $this->app['auth']->logout();
        $this->createStaff($this->barangay, $department, [
            'name' => $name,
        ]);

        $response = $this
            ->post('/login', [
                'barangay_id' => $this->barangay->id,
                'department' => $department,
                'position' => ucfirst($department),
                'full_name' => $name,
                'password' => 'password',
            ]);

        return $response->headers->get('Location') ?? '';
    }
}
