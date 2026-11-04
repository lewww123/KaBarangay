<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\ResidentAccount;
use App\Models\StaffAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_search_residents_returns_only_eligible_residents_in_the_admin_barangay(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $purok = $this->createPurok($this->barangay);
        $eligible = $this->createResident($this->barangay, $purok, ['first_name' => 'Maria', 'last_name' => 'Santos']);
        User::factory()->create(['barangay_id' => $this->barangay->id, 'resident_id' => $eligible->id]);
        $missingUser = $this->createResident($this->barangay, $purok, ['first_name' => 'Maria', 'last_name' => 'No User', 'mobile' => '09170000002']);
        $foreignPurok = $this->createPurok($this->other);
        $foreign = $this->createResident($this->other, $foreignPurok, ['first_name' => 'Maria', 'last_name' => 'Foreign']);
        User::factory()->create(['barangay_id' => $this->other->id, 'resident_id' => $foreign->id]);

        $this->actingAs($admin)->getJson('/admin/staff/residents?search=Maria')
            ->assertOk()
            ->assertJsonPath('residents.0.id', $eligible->id)
            ->assertJsonCount(1, 'residents');

        $this->assertNotSame($eligible->id, $missingUser->id);
    }

    public function test_admin_can_create_an_assignment_for_a_resident_with_an_account_and_user(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $resident = $this->createResident($this->barangay, $this->createPurok($this->barangay));
        $user = User::factory()->create(['barangay_id' => $this->barangay->id, 'resident_id' => $resident->id]);

        $this->actingAs($admin)->post('/admin/staff', [
            'resident_id' => $resident->id,
            'department' => 'Records',
            'position' => 'Clerk',
        ])->assertRedirect();

        $this->assertDatabaseHas('staff_assignments', [
            'user_id' => $user->id,
            'resident_id' => $resident->id,
            'barangay_id' => $this->barangay->id,
            'department' => 'Records',
            'position' => 'Clerk',
            'is_active' => true,
        ]);
    }

    public function test_assignment_rejects_a_resident_without_an_account_or_linked_user(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $purok = $this->createPurok($this->barangay);
        $withoutUser = $this->createResident($this->barangay, $purok);
        $withoutAccount = $this->createResident($this->barangay, $purok, ['mobile' => '09170000002']);
        ResidentAccount::query()->where('resident_id', $withoutAccount->id)->delete();
        User::factory()->create(['barangay_id' => $this->barangay->id, 'resident_id' => $withoutAccount->id]);

        foreach ([$withoutUser, $withoutAccount] as $resident) {
            $this->actingAs($admin)->from('/admin/staff')->post('/admin/staff', [
                'resident_id' => $resident->id,
                'department' => 'Records',
                'position' => 'Clerk',
            ])->assertSessionHasErrors('resident_id');
        }

        $this->assertSame(1, StaffAssignment::count());
    }

    public function test_admin_can_update_and_deactivate_its_barangay_assignment(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $resident = $this->createResident($this->barangay, $this->createPurok($this->barangay));
        $user = User::factory()->create(['barangay_id' => $this->barangay->id, 'resident_id' => $resident->id]);
        $assignment = StaffAssignment::create(['user_id' => $user->id, 'resident_id' => $resident->id, 'barangay_id' => $this->barangay->id, 'department' => 'Records', 'position' => 'Clerk']);

        $this->actingAs($admin)->put("/admin/staff/{$assignment->id}", ['department' => 'Health', 'position' => 'Officer'])->assertRedirect();
        $this->actingAs($admin)->patch("/admin/staff/{$assignment->id}/deactivate")->assertRedirect();

        $this->assertDatabaseHas('staff_assignments', ['id' => $assignment->id, 'department' => 'Health', 'position' => 'Officer', 'is_active' => false]);
    }

    public function test_staff_listing_includes_position_department_and_navigation_for_all_assignments(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        foreach (range(1, 16) as $number) {
            StaffAssignment::create(['user_id' => $admin->id, 'resident_id' => $admin->resident_id,
                'barangay_id' => $this->barangay->id, 'department' => 'clerk', 'position' => 'Barangay Clerk '.$number]);
        }
        $this->createStaff($this->other, 'clerk');
        $this->actingAs($admin)->get('/admin/staff')->assertOk()->assertInertia(fn ($page) => $page
            ->where('assignments.total', 17)->has('assignments.data', 15)
            ->has('assignments.data.0.position')->where('assignments.data.0.department_label', 'Records and Certificates')
            ->where('departments.1.value', 'clerk'));
        $this->get('/admin/staff?page=2')->assertOk()->assertInertia(fn ($page) => $page->has('assignments.data', 2));
    }

    public function test_full_name_autocomplete_and_scanned_resident_selection_are_scoped(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $resident = $this->createResident($this->barangay, $this->createPurok($this->barangay), ['first_name' => 'Maria', 'last_name' => 'Santos']);
        User::factory()->create(['barangay_id' => $this->barangay->id, 'resident_id' => $resident->id]);
        $foreign = $this->createStaff($this->other, 'clerk');
        $this->actingAs($admin)->getJson('/admin/staff/residents?search=maria%20santos')
            ->assertOk()->assertJsonCount(1, 'residents')->assertJsonPath('residents.0.id', $resident->id);
        $this->getJson('/admin/staff/residents?resident_id='.$resident->id)
            ->assertOk()->assertJsonPath('residents.0.id', $resident->id);
        $this->getJson('/admin/staff/residents?resident_id='.$foreign->resident_id)
            ->assertOk()->assertJsonCount(0, 'residents');
    }

    public function test_duplicate_assignment_reuses_and_reactivates_the_existing_record(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $payload = ['resident_id' => $admin->resident_id, 'department' => 'clerk', 'position' => 'Barangay Clerk'];
        $this->actingAs($admin)->post('/admin/staff', $payload)->assertSessionHasNoErrors();
        $assignment = StaffAssignment::where('department', 'clerk')->firstOrFail();
        $assignment->update(['is_active' => false]);
        $this->post('/admin/staff', $payload)->assertSessionHasNoErrors();
        $this->post('/admin/staff', $payload)->assertSessionHasNoErrors();
        $this->assertSame(1, StaffAssignment::where('department', 'clerk')->count());
        $this->assertTrue($assignment->fresh()->is_active);
    }

    public function test_editing_to_an_existing_assignment_returns_a_validation_error(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $assignment = StaffAssignment::create(['user_id' => $admin->id, 'resident_id' => $admin->resident_id,
            'barangay_id' => $this->barangay->id, 'department' => 'clerk', 'position' => 'Barangay Clerk']);
        $this->actingAs($admin)->from('/admin/staff')->put('/admin/staff/'.$assignment->id, [
            'department' => 'admin', 'position' => $admin->staffAssignments()->where('department', 'admin')->firstOrFail()->position,
        ])->assertSessionHasErrors('position');
        $this->assertSame('clerk', $assignment->fresh()->department);
    }
}
