<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Purok;
use App\Models\StaffAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class AdminStaffAuditEventsTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Barangay $otherBarangay;

    private Purok $purok;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $this->otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $this->purok = $this->createPurok($this->barangay);
    }

    public function test_admin_can_create_a_staff_assignment_for_an_eligible_resident(): void
    {
        $admin = $this->portalAdmin();
        $resident = $this->createResident($this->barangay, $this->purok);
        $user = User::factory()->create([
            'barangay_id' => $this->barangay->id,
            'resident_id' => $resident->id,
        ]);

        $this->actingAs($admin)->post('/admin/staff', [
            'resident_id' => $resident->id,
            'department' => 'Health Office',
            'position' => 'Health Clerk',
        ])->assertRedirect();

        $this->assertDatabaseHas('staff_assignments', [
            'user_id' => $user->id,
            'resident_id' => $resident->id,
            'barangay_id' => $this->barangay->id,
            'department' => 'Health Office',
            'position' => 'Health Clerk',
            'is_active' => true,
        ]);
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_create_validation_failure_creates_no_assignment(): void
    {
        $response = $this->actingAs($this->portalAdmin())
            ->from('/admin/staff')
            ->post('/admin/staff', []);

        $response->assertSessionHasErrors(['resident_id', 'department', 'position']);
        $this->assertDatabaseCount('staff_assignments', 1);
    }

    public function test_admin_can_update_an_assignment_department_and_position(): void
    {
        $admin = $this->portalAdmin();
        $assignment = $this->assignment($this->barangay, $this->purok, 'clerk', 'Records Clerk');

        $this->actingAs($admin)->put("/admin/staff/{$assignment->id}", [
            'department' => 'Records Office',
            'position' => 'Senior Records Clerk',
        ])->assertRedirect();

        $this->assertDatabaseHas('staff_assignments', [
            'id' => $assignment->id,
            'department' => 'Records Office',
            'position' => 'Senior Records Clerk',
        ]);
    }

    public function test_update_validation_failure_leaves_assignment_unchanged(): void
    {
        $admin = $this->portalAdmin();
        $assignment = $this->assignment($this->barangay, $this->purok, 'clerk', 'Records Clerk');

        $this->actingAs($admin)->from('/admin/staff')->put("/admin/staff/{$assignment->id}", [])
            ->assertSessionHasErrors(['department', 'position']);

        $this->assertDatabaseHas('staff_assignments', [
            'id' => $assignment->id,
            'department' => 'clerk',
            'position' => 'Records Clerk',
        ]);
    }

    public function test_admin_can_deactivate_an_assignment(): void
    {
        $admin = $this->portalAdmin();
        $assignment = $this->assignment($this->barangay, $this->purok, 'clerk', 'Records Clerk');

        $this->actingAs($admin)->patch("/admin/staff/{$assignment->id}/deactivate")
            ->assertRedirect();

        $this->assertDatabaseHas('staff_assignments', ['id' => $assignment->id, 'is_active' => false]);
    }

    public function test_admin_can_activate_an_assignment(): void
    {
        $admin = $this->portalAdmin();
        $assignment = $this->assignment($this->barangay, $this->purok, 'clerk', 'Records Clerk');
        $assignment->update(['is_active' => false]);

        $this->actingAs($admin)->patch("/admin/staff/{$assignment->id}/activate")
            ->assertRedirect();

        $this->assertDatabaseHas('staff_assignments', ['id' => $assignment->id, 'is_active' => true]);
    }

    public function test_cross_barangay_assignment_mutations_are_forbidden(): void
    {
        $admin = $this->portalAdmin();
        $otherPurok = $this->createPurok($this->otherBarangay);
        $assignment = $this->assignment($this->otherBarangay, $otherPurok, 'clerk', 'Foreign Clerk');

        $this->actingAs($admin)->put("/admin/staff/{$assignment->id}", [
            'department' => 'Records Office',
            'position' => 'Records Clerk',
        ])->assertForbidden();
        $this->actingAs($admin)->patch("/admin/staff/{$assignment->id}/deactivate")->assertForbidden();
        $this->actingAs($admin)->patch("/admin/staff/{$assignment->id}/activate")->assertForbidden();
    }

    private function portalAdmin(): User
    {
        return $this->createAssignedStaff(
            ['barangay' => $this->barangay, 'purok' => $this->purok],
            'admin',
            'Barangay Admin',
        );
    }

    private function assignment(Barangay $barangay, Purok $purok, string $department, string $position): StaffAssignment
    {
        $user = $this->createAssignedStaff(
            ['barangay' => $barangay, 'purok' => $purok],
            $department,
            $position,
        );

        return $user->staffAssignments()->firstOrFail();
    }
}
