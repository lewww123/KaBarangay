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

    /**
     * Verify the login page loads.
     */
    public function test_unified_staff_login_page_loads(): void
    {
        $this->createAssignedStaff([
            'barangay' => $this->barangay,
            'purok' => $this->purok,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Auth/StaffLogin')
                ->has('barangays'));
    }

    /**
     * Successful login with correct resident password.
     */
    public function test_assigned_staff_can_log_in_with_resident_password(): void
    {
        $user = $this->createAssignedStaff([
            'barangay' => $this->barangay,
            'purok' => $this->purok,
        ], 'lupon', 'Lupon Secretary');

        $this->post('/login', [
            'barangay_id' => $this->barangay->id,
            'department' => 'lupon',
            'position' => 'Lupon Secretary',
            'full_name' => $user->resident->full_name,
            'password' => 'password',
        ])->assertRedirect(route('lupon.dashboard'));

        $this->assertAuthenticatedAs($user);
        $assignment = $user->staffAssignments()->where('department', 'lupon')->firstOrFail();
        $this->assertSame($assignment->id, session('staff_assignment_id'));
    }

    /**
     * Wrong resident password should be rejected.
     */
    public function test_login_rejects_an_incorrect_resident_password(): void
    {
        $user = $this->createAssignedStaff([
            'barangay' => $this->barangay,
            'purok' => $this->purok,
        ], 'clerk', 'Barangay Clerk');

        $this->from('/')
            ->post('/login', [
                'barangay_id' => $this->barangay->id,
                'department' => 'clerk',
                'position' => 'Barangay Clerk',
                'full_name' => $user->resident->full_name,
                'password' => 'wrong',
            ])->assertSessionHasErrors('password');
    }

    /**
     * Mismatched assignment should be rejected.
     */
    public function test_login_rejects_a_mismatched_assignment(): void
    {
        $user = $this->createAssignedStaff([
            'barangay' => $this->barangay,
            'purok' => $this->purok,
        ]);

        $this->from('/')
            ->post('/login', [
                'barangay_id' => $this->barangay->id,
                'department' => 'admin',
                'position' => 'Barangay Treasurer',
                'full_name' => $user->resident->full_name,
                'password' => 'password',
            ])->assertSessionHasErrors('password');
    }

    /**
     * Authenticated staff should be redirected to their active assignment dashboard.
     */
    public function test_authenticated_staff_is_redirected_to_their_active_assignment_dashboard(): void
    {
        $user = $this->createAssignedStaff([
            'barangay' => $this->barangay,
            'purok' => $this->purok,
        ], 'clerk', 'Barangay Clerk');

        $this->actingAs($user)
            ->get('/')
            ->assertRedirect(route('clerk.dashboard'));
    }

    // ---------- Regression tests for name normalization ----------

    public function test_login_ignores_case_insensitivity(): void
    {
        $user = $this->createAssignedStaff([
            'barangay' => $this->barangay,
            'purok' => $this->purok,
        ], 'clerk', 'Barangay Clerk');
        $messyName = strtoupper($user->resident->full_name);
        $this->post('/login', [
            'barangay_id' => $this->barangay->id,
            'department' => 'clerk',
            'position' => 'Barangay Clerk',
            'full_name' => $messyName,
            'password' => 'password',
        ])->assertRedirect(route('clerk.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_ignores_leading_and_trailing_spaces(): void
    {
        $user = $this->createAssignedStaff([
            'barangay' => $this->barangay,
            'purok' => $this->purok,
        ], 'clerk', 'Barangay Clerk');
        $messyName = "  " . $user->resident->full_name . "  ";
        $this->post('/login', [
            'barangay_id' => $this->barangay->id,
            'department' => 'clerk',
            'position' => 'Barangay Clerk',
            'full_name' => $messyName,
            'password' => 'password',
        ])->assertRedirect(route('clerk.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_ignores_multiple_internal_spaces(): void
    {
        $user = $this->createAssignedStaff([
            'barangay' => $this->barangay,
            'purok' => $this->purok,
        ], 'clerk', 'Barangay Clerk');
        $parts = explode(' ', $user->resident->full_name, 2);
        $messyName = $parts[0] . "   " . $parts[1];
        $this->post('/login', [
            'barangay_id' => $this->barangay->id,
            'department' => 'clerk',
            'position' => 'Barangay Clerk',
            'full_name' => $messyName,
            'password' => 'password',
        ])->assertRedirect(route('clerk.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_ignores_tabs_and_multiple_spaces(): void
    {
        $user = $this->createAssignedStaff([
            'barangay' => $this->barangay,
            'purok' => $this->purok,
        ], 'clerk', 'Barangay Clerk');
        $parts = explode(' ', $user->resident->full_name, 2);
        $messyName = $parts[0] . "  \t  " . $parts[1];
        $this->post('/login', [
            'barangay_id' => $this->barangay->id,
            'department' => 'clerk',
            'position' => 'Barangay Clerk',
            'full_name' => $messyName,
            'password' => 'password',
        ])->assertRedirect(route('clerk.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_fails_with_inactive_assignment(): void
    {
        $user = $this->createAssignedStaff([
            'barangay' => $this->barangay,
            'purok' => $this->purok,
        ], 'clerk', 'Barangay Clerk');
        $assignment = $user->staffAssignments()->first();
        $assignment->update(['is_active' => false]);
        $this->post('/login', [
            'barangay_id' => $this->barangay->id,
            'department' => 'clerk',
            'position' => 'Barangay Clerk',
            'full_name' => $user->resident->full_name,
            'password' => 'password',
        ])->assertSessionHasErrors('password');
    }

    public function test_login_fails_with_inactive_resident(): void
    {
        $user = $this->createAssignedStaff([
            'barangay' => $this->barangay,
            'purok' => $this->purok,
        ], 'clerk', 'Barangay Clerk');
        $user->resident->update(['status' => 'inactive']);
        $this->post('/login', [
            'barangay_id' => $this->barangay->id,
            'department' => 'clerk',
            'position' => 'Barangay Clerk',
            'full_name' => $user->resident->full_name,
            'password' => 'password',
        ])->assertSessionHasErrors('password');
    }

    public function test_login_fails_with_inactive_user(): void
    {
        $user = $this->createAssignedStaff([
            'barangay' => $this->barangay,
            'purok' => $this->purok,
        ], 'clerk', 'Barangay Clerk');
        $user->update(['status' => 'inactive']);
        $this->post('/login', [
            'barangay_id' => $this->barangay->id,
            'department' => 'clerk',
            'position' => 'Barangay Clerk',
            'full_name' => $user->resident->full_name,
            'password' => 'password',
        ])->assertSessionHasErrors('password');
    }

    // ---------- Regression tests for middle name / suffix in full name ----------

    public function test_login_succeeds_with_middle_name_in_full_name(): void
    {
        $user = $this->createAssignedStaff([
            'barangay' => $this->barangay,
            'purok' => $this->purok,
            'first_name' => 'Maricel',
            'middle_name' => 'Aballe',
            'last_name' => 'Lucero',
        ], 'admin', 'Barangay Treasurer');

        $this->assertSame('Maricel Aballe Lucero', $user->resident->full_name);
        $this->assertSame('Maricel Aballe Lucero', $user->name);

        $this->post('/login', [
            'barangay_id' => $this->barangay->id,
            'department' => 'admin',
            'position' => 'Barangay Treasurer',
            'full_name' => 'Maricel Aballe Lucero',
            'password' => 'password',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_succeeds_with_suffix_in_full_name(): void
    {
        $user = $this->createAssignedStaff([
            'barangay' => $this->barangay,
            'purok' => $this->purok,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'suffix' => 'Jr.',
        ], 'clerk', 'Barangay Clerk');

        $this->assertSame('Juan Dela Cruz Jr.', $user->resident->full_name);
        $this->assertSame('Juan Dela Cruz Jr.', $user->name);

        $this->post('/login', [
            'barangay_id' => $this->barangay->id,
            'department' => 'clerk',
            'position' => 'Barangay Clerk',
            'full_name' => 'Juan Dela Cruz Jr.',
            'password' => 'password',
        ])->assertRedirect(route('clerk.dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_succeeds_with_middle_name_and_suffix(): void
    {
        $user = $this->createAssignedStaff([
            'barangay' => $this->barangay,
            'purok' => $this->purok,
            'first_name' => 'Roberto',
            'middle_name' => 'Santos',
            'last_name' => 'Garcia',
            'suffix' => 'Sr.',
        ], 'lupon', 'Lupon Secretary');

        $this->assertSame('Roberto Santos Garcia Sr.', $user->resident->full_name);
        $this->assertSame('Roberto Santos Garcia Sr.', $user->name);

        $this->post('/login', [
            'barangay_id' => $this->barangay->id,
            'department' => 'lupon',
            'position' => 'Lupon Secretary',
            'full_name' => 'Roberto Santos Garcia Sr.',
            'password' => 'password',
        ])->assertRedirect(route('lupon.dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_rejects_partial_name_without_middle_name(): void
    {
        $this->createAssignedStaff([
            'barangay' => $this->barangay,
            'purok' => $this->purok,
            'first_name' => 'Maricel',
            'middle_name' => 'Aballe',
            'last_name' => 'Lucero',
        ], 'admin', 'Barangay Treasurer');

        $this->from('/')
            ->post('/login', [
                'barangay_id' => $this->barangay->id,
                'department' => 'admin',
                'position' => 'Barangay Treasurer',
                'full_name' => 'Maricel Lucero',
                'password' => 'password',
            ])->assertSessionHasErrors('password');
    }

    // ---------- Regression tests for legacy accounts with first+last User.name ----------

    public function test_login_succeeds_for_legacy_account_with_truncated_user_name(): void
    {
        $user = $this->createAssignedStaff([
            'barangay' => $this->barangay,
            'purok' => $this->purok,
            'first_name' => 'Maricel',
            'middle_name' => 'Aballe',
            'last_name' => 'Lucero',
        ], 'admin', 'Barangay Treasurer');

        // Simulate legacy account where User.name was created without middle name
        $user->update(['name' => 'Maricel Lucero']);

        $this->assertSame('Maricel Aballe Lucero', $user->resident->full_name);
        $this->assertSame('Maricel Lucero', $user->fresh()->name);

        $this->post('/login', [
            'barangay_id' => $this->barangay->id,
            'department' => 'admin',
            'position' => 'Barangay Treasurer',
            'full_name' => 'Maricel Aballe Lucero',
            'password' => 'password',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($user);
        $assignment = $user->staffAssignments()->where('department', 'admin')->firstOrFail();
        $this->assertSame($assignment->id, session('staff_assignment_id'));
    }

    public function test_legacy_account_rejects_incorrect_password(): void
    {
        $user = $this->createAssignedStaff([
            'barangay' => $this->barangay,
            'purok' => $this->purok,
            'first_name' => 'Maricel',
            'middle_name' => 'Aballe',
            'last_name' => 'Lucero',
        ], 'admin', 'Barangay Treasurer');

        $user->update(['name' => 'Maricel Lucero']);

        $this->from('/')
            ->post('/login', [
                'barangay_id' => $this->barangay->id,
                'department' => 'admin',
                'position' => 'Barangay Treasurer',
                'full_name' => 'Maricel Aballe Lucero',
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('password');
    }

    public function test_legacy_account_rejects_mismatched_department(): void
    {
        $user = $this->createAssignedStaff([
            'barangay' => $this->barangay,
            'purok' => $this->purok,
            'first_name' => 'Maricel',
            'middle_name' => 'Aballe',
            'last_name' => 'Lucero',
        ], 'admin', 'Barangay Treasurer');

        $user->update(['name' => 'Maricel Lucero']);

        $this->from('/')
            ->post('/login', [
                'barangay_id' => $this->barangay->id,
                'department' => 'clerk',
                'position' => 'Barangay Treasurer',
                'full_name' => 'Maricel Aballe Lucero',
                'password' => 'password',
            ])->assertSessionHasErrors('password');
    }

    public function test_legacy_account_rejects_partial_name_submission(): void
    {
        $user = $this->createAssignedStaff([
            'barangay' => $this->barangay,
            'purok' => $this->purok,
            'first_name' => 'Maricel',
            'middle_name' => 'Aballe',
            'last_name' => 'Lucero',
        ], 'admin', 'Barangay Treasurer');

        $user->update(['name' => 'Maricel Lucero']);

        $this->from('/')
            ->post('/login', [
                'barangay_id' => $this->barangay->id,
                'department' => 'admin',
                'position' => 'Barangay Treasurer',
                'full_name' => 'Maricel Lucero',
                'password' => 'password',
            ])->assertSessionHasErrors('password');
    }
}
