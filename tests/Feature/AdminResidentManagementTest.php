<?php

namespace Tests\Feature;

use App\Models\Barangay;
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
}
