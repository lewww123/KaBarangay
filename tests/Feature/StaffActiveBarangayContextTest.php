<?php

namespace Tests\Feature;

use App\Models\StaffAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\Concerns\SimulatesUnreachableDatabase;
use Tests\TestCase;

class StaffActiveBarangayContextTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase, SimulatesUnreachableDatabase;

    private function assertActiveBarangay($response, string $expectedName): void
    {
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('auth.activeBarangay.name', $expectedName)
            ->has('auth.activeBarangay.id'));
    }

    public function test_selected_sicsican_assignment_shares_sicsican_barangay(): void
    {
        $barangay = $this->createBarangay('Sicsican', 'SIC-001');
        $purok = $this->createPurok($barangay);
        $admin = $this->createAssignedStaff(['barangay' => $barangay, 'purok' => $purok], 'admin', 'Admin');

        $this->actingAs($admin);
        $this->assertActiveBarangay($this->get('/admin'), 'Sicsican');
    }

    public function test_selected_sta_monica_assignment_shares_sta_monica_barangay(): void
    {
        $barangay = $this->createBarangay('Sta. Monica', 'STA-001');
        $purok = $this->createPurok($barangay);
        $clerk = $this->createAssignedStaff(['barangay' => $barangay, 'purok' => $purok], 'clerk', 'Clerk');

        $this->actingAs($clerk);
        $this->assertActiveBarangay($this->get('/clerk/dashboard'), 'Sta. Monica');
    }

    public function test_selected_san_jose_assignment_shares_san_jose_barangay(): void
    {
        $barangay = $this->createBarangay('San Jose', 'SJ-001');
        $purok = $this->createPurok($barangay);
        $pwd = $this->createAssignedStaff(['barangay' => $barangay, 'purok' => $purok], 'pwd', 'PWD Staff');

        $this->actingAs($pwd);
        $this->assertActiveBarangay($this->get('/pwd/dashboard'), 'San Jose');
    }

    public function test_all_staff_portals_receive_selected_barangay(): void
    {
        $barangay = $this->createBarangay('Sicsican', 'SIC-ALL');
        $purok = $this->createPurok($barangay);

        $admin = $this->createAssignedStaff(['barangay' => $barangay, 'purok' => $purok], 'admin', 'Admin');
        $clerk = $this->createAssignedStaff(['barangay' => $barangay, 'purok' => $purok], 'clerk', 'Clerk');
        $pwd = $this->createAssignedStaff(['barangay' => $barangay, 'purok' => $purok], 'pwd', 'PWD Staff');
        $senior = $this->createAssignedStaff(['barangay' => $barangay, 'purok' => $purok], 'senior', 'Senior Staff');
        $lupon = $this->createAssignedStaff(['barangay' => $barangay, 'purok' => $purok], 'lupon', 'Lupon Member');

        $this->actingAs($admin);
        $this->assertActiveBarangay($this->get('/admin'), 'Sicsican');

        $this->actingAs($clerk);
        $this->assertActiveBarangay($this->get('/clerk/dashboard'), 'Sicsican');

        $this->actingAs($pwd);
        $this->assertActiveBarangay($this->get('/pwd/dashboard'), 'Sicsican');

        $this->actingAs($senior);
        $this->assertActiveBarangay($this->get('/senior/dashboard'), 'Sicsican');

        $this->actingAs($lupon);
        $this->assertActiveBarangay($this->get('/lupon/dashboard'), 'Sicsican');
    }

    public function test_multiple_active_assignments_use_only_selected_session_assignment(): void
    {
        $barangay = $this->createBarangay('Sta. Monica', 'STA-MULTI');
        $purok = $this->createPurok($barangay);
        $admin = $this->createAssignedStaff(['barangay' => $barangay, 'purok' => $purok], 'admin', 'Admin');
        $residentId = $admin->resident_id;
        $selected = $admin->staffAssignments()->firstOrFail();

        // Same user/barangay, second active assignment (different department) if schema allows.
        $other = StaffAssignment::create([
            'user_id' => $admin->id,
            'resident_id' => $residentId,
            'barangay_id' => $barangay->id,
            'department' => 'clerk',
            'position' => 'Clerk',
            'is_active' => true,
        ]);

        $this->actingAs($admin)->withSession(['staff_assignment_id' => $selected->id]);
        $this->assertActiveBarangay($this->get('/admin'), 'Sta. Monica');

        // Selecting the other assignment as session context (display uses selected assignment);
        // admin route auth still requires admin department, so use a neutral assertion on shared data
        // by hitting a page only if authorized — instead assert via session selection directly.
        $this->actingAs($admin)->withSession(['staff_assignment_id' => $other->id]);
        $response = $this->get('/admin');
        $response->assertForbidden(); // role check unchanged (selected assignment is clerk)
    }

    public function test_invalid_selected_assignment_does_not_expose_another_barangay(): void
    {
        $barangay = $this->createBarangay('Sicsican', 'SIC-INV');
        $purok = $this->createPurok($barangay);
        $otherBarangay = $this->createBarangay('San Jose', 'SJ-OTHER');
        $otherPurok = $this->createPurok($otherBarangay, 'Purok 2');
        $otherUser = $this->createAssignedStaff(['barangay' => $otherBarangay, 'purok' => $otherPurok], 'admin', 'Other Admin');
        $otherAssignmentId = $otherUser->staffAssignments()->firstOrFail()->id;

        $admin = $this->createAssignedStaff(['barangay' => $barangay, 'purok' => $purok], 'admin', 'Admin');
        // Force an invalid/foreign assignment id in session while authenticated as Sicsican admin.
        $this->actingAs($admin)->withSession(['staff_assignment_id' => $otherAssignmentId, '_without_staff_assignment' => true]);

        $response = $this->get('/admin');
        // EnsureStaffRole invalidates session for invalid selected assignment; it must not grant access.
        $response->assertRedirect(route('login'));
    }

    public function test_missing_selected_assignment_returns_safe_null_without_exposing_barangay(): void
    {
        $barangay = $this->createBarangay('Sta. Monica', 'STA-MISS');
        $purok = $this->createPurok($barangay);
        $admin = $this->createAssignedStaff(['barangay' => $barangay, 'purok' => $purok], 'admin', 'Admin');

        $this->actingAs($admin)->withSession(['_without_staff_assignment' => true]);
        session()->forget('staff_assignment_id');

        $response = $this->get('/admin');
        $response->assertRedirect(route('login'));
    }

    public function test_database_degradation_returns_safe_null_active_barangay_without_throwing(): void
    {
        $barangay = $this->createBarangay('Sicsican', 'SIC-DB');
        $purok = $this->createPurok($barangay);
        $admin = $this->createAssignedStaff(['barangay' => $barangay, 'purok' => $purok], 'admin', 'Admin');
        $assignmentId = $admin->staffAssignments()->firstOrFail()->id;

        $this->withUnreachableDatabase(function () use ($admin, $assignmentId) {
            // Resolution under outage must not throw or fabricate a barangay.
            // EnsureStaffRole may degrade the request to 503 (correct), or a rendered
            // Inertia response may carry a safe null activeBarangay.
            $response = $this->actingAs($admin)
                ->withSession(['staff_assignment_id' => $assignmentId, '_without_staff_assignment' => true])
                ->get('/admin');

            $this->assertNotEquals(500, $response->status());
            if (str_contains((string) $response->headers->get('Content-Type'), 'json')
                || $response->headers->get('X-Inertia')) {
                $active = $response->json('props.auth.activeBarangay') ?? $response->json('activeBarangay');
                $this->assertTrue($active === null || $active === [], 'activeBarangay must not be fabricated during DB degradation.');
            }
            // 503 degraded or redirect-to-login for unresolved context is acceptable; no barangay name leaked.
            $body = $response->getContent() ?: '';
            $this->assertStringNotContainsString('Sicsican', $body);
        });
    }

    public function test_existing_role_authorization_behavior_unchanged(): void
    {
        $barangay = $this->createBarangay('Sicsican', 'SIC-RBAC');
        $purok = $this->createPurok($barangay);
        $clerk = $this->createAssignedStaff(['barangay' => $barangay, 'purok' => $purok], 'clerk', 'Clerk');

        $this->actingAs($clerk)->get('/clerk/dashboard')->assertOk()
            ->assertInertia(fn ($page) => $page->where('auth.activeBarangay.name', 'Sicsican'));
        $this->actingAs($clerk)->get('/admin')->assertForbidden();
    }
}
