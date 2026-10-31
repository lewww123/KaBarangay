<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Purok;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class StaffLoginFeatureTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Purok $purok;

    protected function setUp(): void
    {
        parent::setUp();
        $this->barangay = $this->createBarangay('Staff Login Barangay', 'STAFF-LOGIN');
        $this->purok = $this->createPurok($this->barangay);
    }

    public function test_unified_staff_login_page_loads(): void
    {
        $this->createAssignedStaff(['barangay' => $this->barangay, 'purok' => $this->purok]);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Auth/StaffLogin')
                ->has('barangays'));
    }

    public function test_assigned_staff_can_log_in_with_resident_password(): void
    {
        $user = $this->createAssignedStaff(['barangay' => $this->barangay, 'purok' => $this->purok], 'lupon', 'Lupon Secretary');

        $this->post('/login', [
            'barangay_id' => $this->barangay->id,
            'department' => 'lupon',
            'position' => 'Lupon Secretary',
            'full_name' => $user->resident->full_name,
            'password' => 'password',
        ])->assertRedirect(route('lupon.dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_rejects_an_incorrect_resident_password(): void
    {
        $user = $this->createAssignedStaff(['barangay' => $this->barangay, 'purok' => $this->purok], 'clerk', 'Barangay Clerk');

        $this->from('/')->post('/login', [
            'barangay_id' => $this->barangay->id,
            'department' => 'clerk',
            'position' => 'Barangay Clerk',
            'full_name' => $user->resident->full_name,
            'password' => 'wrong',
        ])->assertSessionHasErrors('password');
    }

    public function test_login_rejects_a_mismatched_assignment(): void
    {
        $user = $this->createAssignedStaff(['barangay' => $this->barangay, 'purok' => $this->purok]);

        $this->from('/')->post('/login', [
            'barangay_id' => $this->barangay->id,
            'department' => 'admin',
            'position' => 'Barangay Treasurer',
            'full_name' => $user->resident->full_name,
            'password' => 'password',
        ])->assertSessionHasErrors('password');
    }

    public function test_authenticated_staff_is_redirected_to_their_active_assignment_dashboard(): void
    {
        $user = $this->createAssignedStaff(['barangay' => $this->barangay, 'purok' => $this->purok], 'clerk', 'Barangay Clerk');

        $this->actingAs($user)->get('/')
            ->assertRedirect(route('clerk.dashboard'));
    }
}
