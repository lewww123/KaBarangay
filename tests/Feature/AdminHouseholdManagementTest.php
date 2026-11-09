<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Barangay;
use App\Models\Household;
use App\Models\Purok;
use App\Models\Resident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class AdminHouseholdManagementTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;
    private Barangay $otherBarangay;
    private Purok $purok;
    private Purok $otherPurok;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $this->otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $this->purok = $this->createPurok($this->barangay, 'Purok 1');
        $this->otherPurok = $this->createPurok($this->otherBarangay, 'Purok 2');
    }

    public function test_admin_can_create_a_household_head_and_attach_a_resident_to_it(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)->post('/admin/residents', [
            'purok_id' => $this->purok->id,
            'first_name' => 'Maria', 'last_name' => 'Santos',
            'birthdate' => '1990-01-01', 'sex' => 'Female',
            'civil_status' => 'Single', 'address_line' => '1 Test Street',
            'mobile' => '09175550101', 'household_mode' => 'new',
            'household_relationship' => 'Head',
        ])->assertSessionHasNoErrors();

        $head = Resident::query()->where('mobile', '09175550101')->sole();
        $household = Household::query()->where('head_resident_id', $head->id)->sole();
        $this->assertSame('Head', $head->household_relationship);

        $this->actingAs($admin)->post('/admin/residents', [
            'purok_id' => $this->purok->id,
            'first_name' => 'Jose', 'last_name' => 'Santos',
            'birthdate' => '1992-01-01', 'sex' => 'Male',
            'civil_status' => 'Single', 'address_line' => '1 Test Street',
            'mobile' => '09175550102', 'household_mode' => 'existing',
            'household_id' => $household->id, 'household_relationship' => 'Sibling',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('residents', [
            'mobile' => '09175550102',
            'household_id' => $household->id,
            'household_relationship' => 'Sibling',
        ]);
    }

    public function test_households_are_scoped_and_exposed_in_admin_directory_and_details(): void
    {
        $head = $this->createResident($this->barangay, $this->purok, ['mobile' => '09175550111']);
        $household = Household::create([
            'barangay_id' => $this->barangay->id, 'purok_id' => $this->purok->id,
            'household_code' => 'HH-TEST001', 'head_resident_id' => $head->id,
        ]);
        $head->update(['household_id' => $household->id, 'household_relationship' => 'Head']);
        $admin = $this->createStaff($this->barangay, 'admin');

        $headers = [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()) ?? '',
            'X-Inertia-Partial-Component' => 'Admin/Residents',
            'X-Inertia-Partial-Data' => 'stats,households,puroks',
        ];
        $this->actingAs($admin)->get('/admin/residents?tab=households', $headers)
            ->assertJsonPath('props.stats.totalHouseholds', 1)
            ->assertJsonPath('props.households.data.0.code', 'HH-TEST001')
            ->assertJsonPath('props.puroks.0.resident_count', 2)
            ->assertJsonPath('props.puroks.0.household_count', 1);

        $this->actingAs($admin)->getJson('/admin/residents/'.$head->id.'/details')
            ->assertJsonPath('data.household.code', 'HH-TEST001')
            ->assertJsonPath('data.household.members.0.relationship', 'Head');

        $this->actingAs($admin)->getJson('/admin/residents/households/search?q=TEST')
            ->assertJsonPath('data.0.code', 'HH-TEST001');

        $this->actingAs($admin)->patch('/admin/residents/'.$head->id.'/household', [
            'household_mode' => 'none',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('residents', [
            'id' => $head->id,
            'household_id' => null,
            'household_relationship' => null,
        ]);
    }

    public function test_admin_can_create_household_with_auto_generated_code(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $response = $this->actingAs($admin)->post('/admin/households', [
            'purok_id' => $this->purok->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $household = Household::query()->where('barangay_id', $this->barangay->id)->firstOrFail();
        $this->assertStringStartsWith('HH-', $household->household_code);
        $this->assertSame($this->purok->id, $household->purok_id);
        $this->assertNull($household->head_resident_id);
    }

    public function test_admin_can_create_household_with_custom_code_and_members(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $resident1 = $this->createResident($this->barangay, $this->purok, ['mobile' => '09176000001']);
        $resident2 = $this->createResident($this->barangay, $this->purok, ['mobile' => '09176000002']);

        $response = $this->actingAs($admin)->post('/admin/households', [
            'purok_id' => $this->purok->id,
            'household_code' => 'HH-CUSTOM-01',
            'head_resident_id' => $resident1->id,
            'members' => [
                ['resident_id' => $resident1->id, 'household_relationship' => 'Head'],
                ['resident_id' => $resident2->id, 'household_relationship' => 'Child'],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $household = Household::query()->where('household_code', 'HH-CUSTOM-01')->firstOrFail();
        $this->assertSame($resident1->id, $household->head_resident_id);

        $resident1->refresh();
        $resident2->refresh();
        $this->assertSame($household->id, $resident1->household_id);
        $this->assertSame('Head', $resident1->household_relationship);
        $this->assertSame($household->id, $resident2->household_id);
        $this->assertSame('Child', $resident2->household_relationship);
    }

    public function test_creating_household_rejects_duplicate_code_in_same_barangay(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        Household::create([
            'barangay_id' => $this->barangay->id,
            'purok_id' => $this->purok->id,
            'household_code' => 'HH-EXISTING',
        ]);

        $response = $this->actingAs($admin)->post('/admin/households', [
            'purok_id' => $this->purok->id,
            'household_code' => 'HH-EXISTING',
        ]);

        $response->assertSessionHasErrors(['household_code']);
    }

    public function test_creating_household_allows_same_code_in_different_barangay(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        Household::create([
            'barangay_id' => $this->otherBarangay->id,
            'purok_id' => $this->otherPurok->id,
            'household_code' => 'HH-DUPLICATE-OK',
        ]);

        $response = $this->actingAs($admin)->post('/admin/households', [
            'purok_id' => $this->purok->id,
            'household_code' => 'HH-DUPLICATE-OK',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('households', [
            'barangay_id' => $this->barangay->id,
            'household_code' => 'HH-DUPLICATE-OK',
        ]);
    }

    public function test_creating_household_rejects_head_that_is_not_a_member(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $resident1 = $this->createResident($this->barangay, $this->purok, ['mobile' => '09176000010']);
        $resident2 = $this->createResident($this->barangay, $this->purok, ['mobile' => '09176000011']);

        $response = $this->actingAs($admin)->post('/admin/households', [
            'purok_id' => $this->purok->id,
            'household_code' => 'HH-REJECT-HEAD',
            'head_resident_id' => $resident1->id,
            'members' => [
                ['resident_id' => $resident2->id, 'household_relationship' => 'Child'],
            ],
        ]);

        $response->assertSessionHasErrors(['head_resident_id']);
    }

    public function test_admin_can_view_household_details_and_members(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $head = $this->createResident($this->barangay, $this->purok, ['first_name' => 'John', 'last_name' => 'Doe', 'mobile' => '09176000020']);
        $member = $this->createResident($this->barangay, $this->purok, ['first_name' => 'Jane', 'last_name' => 'Doe', 'mobile' => '09176000021']);

        $household = Household::create([
            'barangay_id' => $this->barangay->id,
            'purok_id' => $this->purok->id,
            'household_code' => 'HH-SHOW-01',
            'head_resident_id' => $head->id,
        ]);
        $head->update(['household_id' => $household->id, 'household_relationship' => 'Head']);
        $member->update(['household_id' => $household->id, 'household_relationship' => 'Spouse']);

        $response = $this->actingAs($admin)->getJson("/admin/households/{$household->id}");

        $response->assertOk()
            ->assertJsonPath('data.code', 'HH-SHOW-01')
            ->assertJsonPath('data.purok_name', 'Purok 1')
            ->assertJsonPath('data.head.name', 'John Doe')
            ->assertJsonPath('data.members_count', 2)
            ->assertJsonPath('data.members.0.is_head', true)
            ->assertJsonPath('data.members.0.relationship', 'Head')
            ->assertJsonPath('data.members.1.relationship', 'Spouse');
    }

    public function test_admin_can_update_household_details(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $household = Household::create([
            'barangay_id' => $this->barangay->id,
            'purok_id' => $this->purok->id,
            'household_code' => 'HH-ORIGINAL',
        ]);
        $newPurok = $this->createPurok($this->barangay, 'Purok New');

        $response = $this->actingAs($admin)->put("/admin/households/{$household->id}", [
            'purok_id' => $newPurok->id,
            // A tampered payload must not be able to rewrite the household code.
            'household_code' => 'HH-UPDATED',
            'head_resident_id' => null,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $household->refresh();
        $this->assertSame('HH-ORIGINAL', $household->household_code);
        $this->assertSame($newPurok->id, $household->purok_id);
    }

    public function test_updating_household_rejects_non_member_as_head(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $household = Household::create([
            'barangay_id' => $this->barangay->id,
            'purok_id' => $this->purok->id,
            'household_code' => 'HH-UPDATE-HEAD',
        ]);
        $nonMember = $this->createResident($this->barangay, $this->purok, ['mobile' => '09176000030']);

        $response = $this->actingAs($admin)->put("/admin/households/{$household->id}", [
            'purok_id' => $this->purok->id,
            'household_code' => 'HH-UPDATE-HEAD',
            'head_resident_id' => $nonMember->id,
        ]);

        $response->assertSessionHasErrors(['head_resident_id']);
    }

    public function test_updating_household_preserves_member_relationships_when_changing_head(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $resident1 = $this->createResident($this->barangay, $this->purok, ['mobile' => '09176000040']);
        $resident2 = $this->createResident($this->barangay, $this->purok, ['mobile' => '09176000041']);

        $household = Household::create([
            'barangay_id' => $this->barangay->id,
            'purok_id' => $this->purok->id,
            'household_code' => 'HH-CHANGE-HEAD',
            'head_resident_id' => $resident1->id,
        ]);
        $resident1->update(['household_id' => $household->id, 'household_relationship' => 'Parent']);
        $resident2->update(['household_id' => $household->id, 'household_relationship' => 'Child']);

        $response = $this->actingAs($admin)->put("/admin/households/{$household->id}", [
            'purok_id' => $this->purok->id,
            'household_code' => 'HH-CHANGE-HEAD',
            'head_resident_id' => $resident2->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $household->refresh();
        $this->assertSame($resident2->id, $household->head_resident_id);

        // Verify relationships are preserved (Rule 4)
        $resident1->refresh();
        $resident2->refresh();
        $this->assertSame('Parent', $resident1->household_relationship);
        $this->assertSame('Child', $resident2->household_relationship);
    }

    public function test_admin_can_add_member_to_household(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $household = Household::create([
            'barangay_id' => $this->barangay->id,
            'purok_id' => $this->purok->id,
            'household_code' => 'HH-ADD-MEMBER',
        ]);
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09176000050']);

        $response = $this->actingAs($admin)->post("/admin/households/{$household->id}/members", [
            'resident_id' => $resident->id,
            'household_relationship' => 'Cousin',
            'is_head' => true,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $resident->refresh();
        $household->refresh();
        $this->assertSame($household->id, $resident->household_id);
        $this->assertSame('Cousin', $resident->household_relationship);
        $this->assertSame($resident->id, $household->head_resident_id);
    }

    public function test_adding_member_clears_their_previous_household_head_pointer_if_they_were_head(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $oldHousehold = Household::create([
            'barangay_id' => $this->barangay->id,
            'purok_id' => $this->purok->id,
            'household_code' => 'HH-OLD-HOUSEHOLD',
        ]);
        $newHousehold = Household::create([
            'barangay_id' => $this->barangay->id,
            'purok_id' => $this->purok->id,
            'household_code' => 'HH-NEW-HOUSEHOLD',
        ]);

        $headResident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09176000060']);
        $oldHousehold->update(['head_resident_id' => $headResident->id]);
        $headResident->update(['household_id' => $oldHousehold->id, 'household_relationship' => 'Head']);

        $response = $this->actingAs($admin)->post("/admin/households/{$newHousehold->id}/members", [
            'resident_id' => $headResident->id,
            'household_relationship' => 'Member',
            'is_head' => false,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $oldHousehold->refresh();
        $newHousehold->refresh();
        $headResident->refresh();

        // Old household head must be cleared (Rule 2)
        $this->assertNull($oldHousehold->head_resident_id);
        $this->assertSame($newHousehold->id, $headResident->household_id);
    }

    public function test_admin_can_remove_member_from_household(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $household = Household::create([
            'barangay_id' => $this->barangay->id,
            'purok_id' => $this->purok->id,
            'household_code' => 'HH-REMOVE-MEMBER',
        ]);
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09176000070']);
        $resident->update(['household_id' => $household->id, 'household_relationship' => 'Child']);

        $response = $this->actingAs($admin)->delete("/admin/households/{$household->id}/members/{$resident->id}");

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $resident->refresh();
        $this->assertNull($resident->household_id);
        $this->assertNull($resident->household_relationship);
    }

    public function test_removing_head_member_clears_household_head_pointer_and_keeps_empty_household(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $headResident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09176000080']);
        $household = Household::create([
            'barangay_id' => $this->barangay->id,
            'purok_id' => $this->purok->id,
            'household_code' => 'HH-HEAD-REMOVE',
            'head_resident_id' => $headResident->id,
        ]);
        $headResident->update(['household_id' => $household->id, 'household_relationship' => 'Head']);

        $response = $this->actingAs($admin)->delete("/admin/households/{$household->id}/members/{$headResident->id}");

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $headResident->refresh();
        $this->assertNull($headResident->household_id);
        $this->assertNull($headResident->household_relationship);

        // Household is preserved even with 0 members (Rule 3) and head is cleared (Rule 2)
        $household->refresh();
        $this->assertNull($household->head_resident_id);
        $this->assertSame(0, $household->members()->count());
        $this->assertDatabaseHas('households', ['id' => $household->id]);
    }

    public function test_admin_can_transfer_member_to_another_household(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $householdA = Household::create([
            'barangay_id' => $this->barangay->id,
            'purok_id' => $this->purok->id,
            'household_code' => 'HH-TRANS-A',
        ]);
        $householdB = Household::create([
            'barangay_id' => $this->barangay->id,
            'purok_id' => $this->purok->id,
            'household_code' => 'HH-TRANS-B',
        ]);
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09176000090']);
        $resident->update(['household_id' => $householdA->id, 'household_relationship' => 'Member']);

        $response = $this->actingAs($admin)->post("/admin/households/{$householdA->id}/members/{$resident->id}/transfer", [
            'target_household_id' => $householdB->id,
            'household_relationship' => 'Relative',
            'is_head' => true,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $resident->refresh();
        $householdB->refresh();
        $this->assertSame($householdB->id, $resident->household_id);
        $this->assertSame('Relative', $resident->household_relationship);
        $this->assertSame($resident->id, $householdB->head_resident_id);
    }

    public function test_transferring_head_clears_source_household_head_pointer(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09176000100']);
        $source = Household::create([
            'barangay_id' => $this->barangay->id,
            'purok_id' => $this->purok->id,
            'household_code' => 'HH-SOURCE-HEAD',
            'head_resident_id' => $resident->id,
        ]);
        $resident->update(['household_id' => $source->id, 'household_relationship' => 'Head']);

        $target = Household::create([
            'barangay_id' => $this->barangay->id,
            'purok_id' => $this->purok->id,
            'household_code' => 'HH-TARGET-HOUSE',
        ]);

        $response = $this->actingAs($admin)->post("/admin/households/{$source->id}/members/{$resident->id}/transfer", [
            'target_household_id' => $target->id,
            'household_relationship' => 'Brother',
            'is_head' => false,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $source->refresh();
        $target->refresh();
        $resident->refresh();

        $this->assertNull($source->head_resident_id);
        $this->assertSame($target->id, $resident->household_id);
    }

    public function test_cross_barangay_household_access_returns_404(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $foreignHousehold = Household::create([
            'barangay_id' => $this->otherBarangay->id,
            'purok_id' => $this->otherPurok->id,
            'household_code' => 'HH-FOREIGN',
        ]);

        $this->actingAs($admin)->getJson("/admin/households/{$foreignHousehold->id}")
            ->assertNotFound();

        $this->actingAs($admin)->put("/admin/households/{$foreignHousehold->id}", [
            'purok_id' => $this->purok->id,
            'household_code' => 'HH-FOREIGN-HACK',
        ])->assertNotFound();
    }

    public function test_household_management_routes_require_admin_role(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $household = Household::create([
            'barangay_id' => $this->barangay->id,
            'purok_id' => $this->purok->id,
            'household_code' => 'HH-CLERK-TEST',
        ]);

        $this->actingAs($clerk)->post('/admin/households', [
            'purok_id' => $this->purok->id,
            'household_code' => 'HH-FAIL',
        ])->assertForbidden();

        $this->actingAs($clerk)->put("/admin/households/{$household->id}", [
            'purok_id' => $this->purok->id,
            'household_code' => 'HH-FAIL2',
        ])->assertForbidden();
    }
}
