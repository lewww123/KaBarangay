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

class AdminResidentManagementTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Barangay $other;

    private Purok $purok;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $this->other = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $this->purok = $this->createPurok($this->barangay, 'Purok 1');
    }

    /*
    |--------------------------------------------------------------------------
    | Approved Residents List (scoping, search, pagination)
    |--------------------------------------------------------------------------
    */

    public function test_index_lists_only_own_barangay_residents(): void
    {
        $own = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170000101',
        ]);

        $otherPurok = $this->createPurok($this->other, 'Purok 2');
        $this->createResident($this->other, $otherPurok, [
            'mobile' => '09170000102',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/residents')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Residents')
                ->has('allResidents.data', 2)
                ->where('allResidents.data.0.id', $own->id)
                ->where('allResidents.total', 2)
                ->where('allResidents.data.0.reference', $own->resident_number)
                ->where('allResidents.data.0.status', 'active')
                ->where('allResidents.data.0.accountActive', true));
    }

    public function test_households_deep_link_selects_tab_and_defers_only_that_dataset(): void
    {
        $head = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09171550001',
        ]);
        Household::create([
            'barangay_id' => $this->barangay->id,
            'purok_id' => $this->purok->id,
            'household_code' => 'HH-DEEP001',
            'head_resident_id' => $head->id,
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $version = app(HandleInertiaRequests::class)->version(request()) ?? '';

        // With deferral enabled, a full page load must select the deep-linked
        // tab and expose only that tab's dataset to the deferred loader.
        config(['inertia.defer_heavy_props' => true]);

        $response = $this->actingAs($admin)->get('/admin/residents?tab=households', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version,
        ]);

        $response->assertOk()
            ->assertJsonPath('props.filters.tab', 'households')
            ->assertJsonMissingPath('props.households')
            ->assertJsonMissingPath('props.allResidents')
            ->assertJsonMissingPath('props.addedByAdmin')
            ->assertJsonMissingPath('props.pendingRegistrations')
            ->assertJsonMissingPath('props.needsCorrectionRegistrations')
            ->assertJsonMissingPath('props.rejectedRegistrations');

        $deferred = collect($response->json('deferredProps'))->flatten()->all();

        $this->assertContains('households', $deferred);
        $this->assertNotContains('allResidents', $deferred);
        $this->assertNotContains('addedByAdmin', $deferred);
        $this->assertNotContains('pendingRegistrations', $deferred);
        $this->assertNotContains('needsCorrectionRegistrations', $deferred);
        $this->assertNotContains('rejectedRegistrations', $deferred);

        // Partial visits may still request any tab's dataset, including tabs
        // that were not part of the original full page load.
        config(['inertia.defer_heavy_props' => false]);

        $this->actingAs($admin)->get('/admin/residents?tab=households', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version,
            'X-Inertia-Partial-Component' => 'Admin/Residents',
            'X-Inertia-Partial-Data' => 'allResidents,filters',
        ])
            ->assertOk()
            ->assertJsonPath('props.filters.tab', 'households')
            ->assertJsonPath('props.allResidents.total', 2)
            ->assertJsonMissingPath('props.households');
    }

    public function test_index_paginates_residents(): void
    {
        foreach (range(1, 20) as $i) {
            $this->createResident($this->barangay, $this->purok, [
                'mobile' => '0917100'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'last_name' => 'Resident '.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            ]);
        }

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/residents')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('allResidents.total', 21)
                ->where('allResidents.per_page', 15)
                ->has('allResidents.data', 15)
                ->where('allResidents.current_page', 1));

        $this->actingAs($admin)
            ->get('/admin/residents?all_page=2')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('allResidents.current_page', 2)
                ->has('allResidents.data', 6));
    }

    public function test_index_searches_residents_server_side(): void
    {
        $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09172000001',
            'first_name' => 'Maria',
            'last_name' => 'Santos',
        ]);

        $target = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09172000002',
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/residents?resident_search=Juan')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('allResidents.data', 1)
                ->where('allResidents.data.0.id', $target->id)
                ->where('filters.resident_search', 'Juan'));

        // Search by resident number.
        $this->actingAs($admin)
            ->get('/admin/residents?resident_search='.$target->resident_number)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('allResidents.data', 1)
                ->where('allResidents.data.0.id', $target->id));
    }

    public function test_index_returns_empty_results_when_search_matches_nothing(): void
    {
        $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09173000001',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/residents?resident_search=NobodyMatchesThis')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('allResidents.data', 0)
                ->where('allResidents.total', 0));
    }

    /*
    |--------------------------------------------------------------------------
    | Resident Status Management
    |--------------------------------------------------------------------------
    */

    public function test_resident_can_be_deactivated_and_reactivated(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09174000001',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->patch('/admin/residents/'.$resident->id.'/status', [
                'status' => 'inactive',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('residents', [
            'id' => $resident->id,
            'status' => 'inactive',
        ]);

        $this->actingAs($admin)
            ->patch('/admin/residents/'.$resident->id.'/status', [
                'status' => 'active',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('residents', [
            'id' => $resident->id,
            'status' => 'active',
        ]);
    }

    public function test_invalid_resident_status_is_rejected(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09174000002',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->patch('/admin/residents/'.$resident->id.'/status', [
                'status' => 'deleted',
            ])
            ->assertSessionHasErrors('status');

        $this->assertDatabaseHas('residents', [
            'id' => $resident->id,
            'status' => 'active',
        ]);
    }

    public function test_resident_login_account_can_be_disabled_and_enabled(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09174000003',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->patch('/admin/residents/'.$resident->id.'/account-status', [
                'is_active' => false,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('resident_accounts', [
            'resident_id' => $resident->id,
            'is_active' => 0,
        ]);

        $this->actingAs($admin)
            ->patch('/admin/residents/'.$resident->id.'/account-status', [
                'is_active' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('resident_accounts', [
            'resident_id' => $resident->id,
            'is_active' => 1,
        ]);
    }

    public function test_invalid_account_status_is_rejected(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09174000004',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->patch('/admin/residents/'.$resident->id.'/account-status', [
                'is_active' => 'maybe',
            ])
            ->assertSessionHasErrors('is_active');

        $this->assertDatabaseHas('resident_accounts', [
            'resident_id' => $resident->id,
            'is_active' => 1,
        ]);
    }

    public function test_account_status_returns_404_when_no_account_exists(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09174000005',
        ]);
        $resident->account()->delete();

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->patch('/admin/residents/'.$resident->id.'/account-status', [
                'is_active' => false,
            ])
            ->assertStatus(404);
    }

    /*
    |--------------------------------------------------------------------------
    | Cross-barangay isolation (404)
    |--------------------------------------------------------------------------
    */

    public function test_cross_barangay_resident_status_returns_404(): void
    {
        $otherPurok = $this->createPurok($this->other, 'Purok 2');
        $otherResident = $this->createResident($this->other, $otherPurok, [
            'mobile' => '09175000001',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->patch('/admin/residents/'.$otherResident->id.'/status', [
                'status' => 'inactive',
            ])
            ->assertStatus(404);

        $this->assertDatabaseHas('residents', [
            'id' => $otherResident->id,
            'status' => 'active',
        ]);
    }

    public function test_cross_barangay_account_status_returns_404(): void
    {
        $otherPurok = $this->createPurok($this->other, 'Purok 2');
        $otherResident = $this->createResident($this->other, $otherPurok, [
            'mobile' => '09175000002',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->patch('/admin/residents/'.$otherResident->id.'/account-status', [
                'is_active' => false,
            ])
            ->assertStatus(404);

        $this->assertDatabaseHas('resident_accounts', [
            'resident_id' => $otherResident->id,
            'is_active' => 1,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */

    public function test_resident_status_routes_require_admin_role(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09176000001',
        ]);

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->patch('/admin/residents/'.$resident->id.'/status', [
                'status' => 'inactive',
            ])
            ->assertStatus(403);

        $this->actingAs($clerk)
            ->patch('/admin/residents/'.$resident->id.'/account-status', [
                'is_active' => false,
            ])
            ->assertStatus(403);
    }

    /*
    |--------------------------------------------------------------------------
    | Enforcement (resident auth middleware)
    |--------------------------------------------------------------------------
    */

    public function test_inactive_resident_is_blocked_from_protected_routes(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09177000001',
        ]);

        $resident->update(['status' => 'inactive']);

        $this->withSession(['resident_id' => $resident->id])
            ->get('/resident')
            ->assertRedirect(route('resident.login'));
    }

    public function test_disabled_account_is_blocked_from_protected_routes(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09177000002',
        ]);

        $resident->account->update(['is_active' => false]);

        $this->withSession(['resident_id' => $resident->id])
            ->get('/resident')
            ->assertRedirect(route('resident.login'));
    }

    public function test_active_resident_can_access_protected_routes(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09177000003',
        ]);

        $this->withSession(['resident_id' => $resident->id])
            ->get('/resident')
            ->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | Resident Update / Edit Tests
    |--------------------------------------------------------------------------
    */

    public function test_admin_can_update_resident_personal_and_contact_info(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Original',
            'last_name' => 'Name',
            'mobile' => '09178000001',
            'email' => 'original@kabarangay.local',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $response = $this->actingAs($admin)
            ->put("/admin/residents/{$resident->id}", [
                'purok_id' => $this->purok->id,
                'first_name' => 'UpdatedFirst',
                'middle_name' => 'UpdatedMiddle',
                'last_name' => 'UpdatedLast',
                'suffix' => 'Jr.',
                'birthdate' => '1995-05-15',
                'sex' => 'Male',
                'civil_status' => 'Married',
                'address_line' => '123 New Address St.',
                'mobile' => '09179998888',
                'email' => 'updated@kabarangay.local',
                'household_mode' => 'preserve',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $resident->refresh();
        $this->assertSame('UpdatedFirst', $resident->first_name);
        $this->assertSame('UpdatedMiddle', $resident->middle_name);
        $this->assertSame('UpdatedLast', $resident->last_name);
        $this->assertSame('Jr.', $resident->suffix);
        $this->assertSame('1995-05-15', $resident->birthdate->format('Y-m-d'));
        $this->assertSame('Male', $resident->sex);
        $this->assertSame('Married', $resident->civil_status);
        $this->assertSame('123 New Address St.', $resident->address_line);
        $this->assertSame('09179998888', $resident->mobile);
        $this->assertSame('updated@kabarangay.local', $resident->email);
    }

    public function test_updating_resident_preserves_household_when_mode_is_preserve(): void
    {
        $household = Household::query()->create([
            'barangay_id' => $this->barangay->id,
            'purok_id' => $this->purok->id,
            'household_code' => 'HH-PRESERVE1',
        ]);

        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09178000002',
        ]);
        $resident->update([
            'household_id' => $household->id,
            'household_relationship' => 'Child',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $response = $this->actingAs($admin)
            ->put("/admin/residents/{$resident->id}", [
                'purok_id' => $this->purok->id,
                'first_name' => $resident->first_name,
                'last_name' => $resident->last_name,
                'birthdate' => '1998-01-01',
                'sex' => 'Female',
                'civil_status' => 'Single',
                'address_line' => $resident->address_line,
                'mobile' => $resident->mobile,
                'email' => $resident->email,
                'household_mode' => 'preserve',
            ]);

        $response->assertRedirect();
        $resident->refresh();
        $this->assertSame($household->id, $resident->household_id);
        $this->assertSame('Child', $resident->household_relationship);
    }

    public function test_updating_resident_can_reassign_to_existing_household(): void
    {
        $targetHousehold = Household::query()->create([
            'barangay_id' => $this->barangay->id,
            'purok_id' => $this->purok->id,
            'household_code' => 'HH-TARGET01',
        ]);

        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09178000003',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $response = $this->actingAs($admin)
            ->put("/admin/residents/{$resident->id}", [
                'purok_id' => $this->purok->id,
                'first_name' => $resident->first_name,
                'last_name' => $resident->last_name,
                'birthdate' => '1990-06-20',
                'sex' => 'Male',
                'civil_status' => 'Single',
                'address_line' => $resident->address_line,
                'mobile' => $resident->mobile,
                'email' => $resident->email,
                'household_mode' => 'existing',
                'household_id' => $targetHousehold->id,
                'household_relationship' => 'Brother',
            ]);

        $response->assertRedirect();
        $resident->refresh();
        $this->assertSame($targetHousehold->id, $resident->household_id);
        $this->assertSame('Brother', $resident->household_relationship);
    }

    public function test_updating_resident_can_create_new_household(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09178000004',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $response = $this->actingAs($admin)
            ->put("/admin/residents/{$resident->id}", [
                'purok_id' => $this->purok->id,
                'first_name' => $resident->first_name,
                'last_name' => $resident->last_name,
                'birthdate' => '1985-03-10',
                'sex' => 'Female',
                'civil_status' => 'Single',
                'address_line' => $resident->address_line,
                'mobile' => $resident->mobile,
                'email' => $resident->email,
                'household_mode' => 'new',
                'household_relationship' => 'Head',
            ]);

        $response->assertRedirect();
        $resident->refresh();
        $this->assertNotNull($resident->household_id);
        $this->assertSame('Head', $resident->household_relationship);

        $newHousehold = Household::query()->findOrFail($resident->household_id);
        $this->assertSame($this->barangay->id, $newHousehold->barangay_id);
        $this->assertSame($resident->id, $newHousehold->head_resident_id);
    }

    public function test_updating_resident_can_remove_household(): void
    {
        $household = Household::query()->create([
            'barangay_id' => $this->barangay->id,
            'purok_id' => $this->purok->id,
            'household_code' => 'HH-REMOVE01',
        ]);

        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09178000005',
        ]);
        $resident->update([
            'household_id' => $household->id,
            'household_relationship' => 'Head',
        ]);
        $household->update(['head_resident_id' => $resident->id]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $response = $this->actingAs($admin)
            ->put("/admin/residents/{$resident->id}", [
                'purok_id' => $this->purok->id,
                'first_name' => $resident->first_name,
                'last_name' => $resident->last_name,
                'birthdate' => '1988-12-01',
                'sex' => 'Male',
                'civil_status' => 'Single',
                'address_line' => $resident->address_line,
                'mobile' => $resident->mobile,
                'email' => $resident->email,
                'household_mode' => 'none',
            ]);

        $response->assertRedirect();
        $resident->refresh();
        $this->assertNull($resident->household_id);
        $this->assertNull($resident->household_relationship);

        $household->refresh();
        $this->assertNull($household->head_resident_id);
    }

    public function test_updating_resident_validates_unique_contact_in_same_barangay(): void
    {
        $existing = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09178000010',
            'email' => 'taken@kabarangay.local',
        ]);

        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09178000011',
            'email' => 'other@kabarangay.local',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $response = $this->actingAs($admin)
            ->from('/admin/residents')
            ->put("/admin/residents/{$resident->id}", [
                'purok_id' => $this->purok->id,
                'first_name' => 'Name',
                'last_name' => 'Last',
                'birthdate' => '1990-01-01',
                'sex' => 'Male',
                'civil_status' => 'Single',
                'address_line' => 'Address',
                'mobile' => '09178000010', // duplicate
                'email' => 'taken@kabarangay.local', // duplicate
                'household_mode' => 'preserve',
            ]);

        $response->assertRedirect('/admin/residents');
        $response->assertSessionHasErrors(['mobile', 'email']);
    }

    public function test_updating_resident_allows_same_resident_to_keep_own_contact(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09178000020',
            'email' => 'own@kabarangay.local',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $response = $this->actingAs($admin)
            ->put("/admin/residents/{$resident->id}", [
                'purok_id' => $this->purok->id,
                'first_name' => 'UpdatedFirst',
                'last_name' => 'SameLast',
                'birthdate' => '1992-04-12',
                'sex' => 'Female',
                'civil_status' => 'Single',
                'address_line' => 'New Street',
                'mobile' => '09178000020', // unchanged
                'email' => 'own@kabarangay.local', // unchanged
                'household_mode' => 'preserve',
            ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
    }

    public function test_cross_barangay_resident_update_returns_404(): void
    {
        $otherPurok = $this->createPurok($this->other, 'Foreign Purok');
        $foreignResident = $this->createResident($this->other, $otherPurok, [
            'mobile' => '09178000030',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->put("/admin/residents/{$foreignResident->id}", [
                'purok_id' => $this->purok->id,
                'first_name' => 'Hacked',
                'last_name' => 'Hacked',
                'birthdate' => '1990-01-01',
                'sex' => 'Male',
                'civil_status' => 'Single',
                'address_line' => 'Some address',
                'mobile' => '09178000099',
                'household_mode' => 'none',
            ])
            ->assertNotFound();
    }

    public function test_resident_update_requires_admin_role(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09178000040',
        ]);

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->put("/admin/residents/{$resident->id}", [
                'purok_id' => $this->purok->id,
                'first_name' => 'ClerkAttempt',
                'last_name' => 'Name',
                'birthdate' => '1990-01-01',
                'sex' => 'Male',
                'civil_status' => 'Single',
                'address_line' => 'Address',
                'mobile' => '09178000041',
                'household_mode' => 'none',
            ])
            ->assertForbidden();
    }
}
