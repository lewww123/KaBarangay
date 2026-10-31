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
}
