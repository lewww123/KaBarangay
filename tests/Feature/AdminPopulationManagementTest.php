<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Purok;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class AdminPopulationManagementTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Purok $purok;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $this->purok = $this->createPurok($this->barangay, 'Purok 1');
    }

    /*
    |--------------------------------------------------------------------------
    | Access control
    |--------------------------------------------------------------------------
    */

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/admin/population')->assertRedirect(route('login'));
    }

    public function test_non_admin_roles_cannot_access_population(): void
    {
        foreach (['clerk', 'senior', 'pwd', 'lupon'] as $role) {
            $user = $this->createStaff($this->barangay, $role);

            $this->actingAs($user)
                ->get('/admin/population')
                ->assertStatus(403);
        }
    }

    public function test_super_admin_cannot_access_population(): void
    {
        $superAdmin = $this->createStaff($this->barangay, 'super_admin');

        $this->actingAs($superAdmin)
            ->get('/admin/population')
            ->assertStatus(403);
    }

    public function test_inactive_admin_cannot_access_population(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin', ['status' => 'inactive']);

        $this->actingAs($admin)
            ->get('/admin/population')
            ->assertStatus(403);
    }

    /*
    |--------------------------------------------------------------------------
    | Population totals
    |--------------------------------------------------------------------------
    */

    public function test_admin_sees_active_permanent_residents_as_population(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->createResident($this->barangay, $this->purok, ['mobile' => '09170000001', 'resident_number' => 'A-1']);
        $this->createResident($this->barangay, $this->purok, ['mobile' => '09170000002', 'resident_number' => 'A-2']);

        $this->actingAs($admin)
            ->get('/admin/population')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Population')
                ->where('stats.totalResidents', 3)
                ->where('stats.regularResidents', 3)
                ->where('stats.seniorCitizens', 0)
                ->where('stats.pwdMembers', 0));
    }

    public function test_inactive_residents_are_excluded_from_population_but_reported(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->createResident($this->barangay, $this->purok, ['mobile' => '09170000003', 'resident_number' => 'A-3']);

        $inactive = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170000004', 'resident_number' => 'A-4']);
        $inactive->update(['status' => 'inactive']);

        $this->actingAs($admin)
            ->get('/admin/population')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.totalResidents', 2)
                ->where('stats.inactiveResidents', 1));
    }

    public function test_population_is_scoped_to_the_admins_barangay(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $other = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $otherPurok = $this->createPurok($other, 'Purok 2');

        $this->createResident($this->barangay, $this->purok, ['mobile' => '09170000005', 'resident_number' => 'A-5']);
        $this->createResident($other, $otherPurok, ['mobile' => '09170000006', 'resident_number' => 'B-1']);
        $this->createResident($other, $otherPurok, ['mobile' => '09170000007', 'resident_number' => 'B-2']);

        $this->actingAs($admin)
            ->get('/admin/population')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.totalResidents', 2)
                ->has('puroks', 1)
                ->where('puroks.0.name', 'Purok 1')
                ->where('puroks.0.total', 2));
    }

    public function test_other_barangay_pending_registrations_are_not_counted(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $other = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $otherPurok = $this->createPurok($other, 'Purok 2');

        $this->createPendingRegistration($this->barangay, $this->purok);
        $this->createPendingRegistration($other, $otherPurok);

        $this->actingAs($admin)
            ->get('/admin/population')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.pendingRegistrations', 1));
    }

    /*
    |--------------------------------------------------------------------------
    | Applications are not population
    |--------------------------------------------------------------------------
    */

    public function test_pending_and_rejected_applications_are_not_counted_as_residents(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->createPendingRegistration($this->barangay, $this->purok);

        $needsCorrection = $this->createPendingRegistration($this->barangay, $this->purok, ['mobile' => '09980000002']);
        $needsCorrection->update(['status' => 'needs_correction']);

        $rejected = $this->createPendingRegistration($this->barangay, $this->purok, ['mobile' => '09980000003']);
        $rejected->update(['status' => 'rejected', 'review_notes' => 'Incomplete documents']);

        $this->actingAs($admin)
            ->get('/admin/population')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.totalResidents', 1)
                ->where('stats.pendingRegistrations', 2)
                ->where('stats.rejectedRegistrations', 1));
    }

    /*
    |--------------------------------------------------------------------------
    | Sector-derived counts
    |--------------------------------------------------------------------------
    */

    public function test_senior_and_pwd_counts_come_from_sector_membership(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $senior = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170000008', 'resident_number' => 'A-6']);
        $this->assignSector($senior, 'senior');

        $pwd = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170000009', 'resident_number' => 'A-7']);
        $this->assignSector($pwd, 'pwd');

        $this->createResident($this->barangay, $this->purok, ['mobile' => '09170000010', 'resident_number' => 'A-8']);

        $this->actingAs($admin)
            ->get('/admin/population')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.totalResidents', 4)
                ->where('stats.seniorCitizens', 1)
                ->where('stats.pwdMembers', 1)
                ->where('stats.seniorAndPwd', 0)
                ->where('stats.regularResidents', 2));
    }

    public function test_a_resident_in_both_sectors_is_counted_once_per_category(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $both = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170000011', 'resident_number' => 'A-9']);
        $this->assignSector($both, 'senior');
        $this->assignSector($both, 'pwd');

        $this->actingAs($admin)
            ->get('/admin/population')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.totalResidents', 2)
                ->where('stats.seniorCitizens', 1)
                ->where('stats.pwdMembers', 1)
                ->where('stats.seniorAndPwd', 1)
                ->where('stats.regularResidents', 1));
    }

    public function test_unrelated_sectors_do_not_inflate_senior_or_pwd_counts(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170000012', 'resident_number' => 'A-10']);
        $this->assignSector($resident, 'solo-parent');

        $this->actingAs($admin)
            ->get('/admin/population')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.totalResidents', 2)
                ->where('stats.seniorCitizens', 0)
                ->where('stats.pwdMembers', 0)
                ->where('stats.regularResidents', 1));
    }

    /*
    |--------------------------------------------------------------------------
    | Purok breakdown
    |--------------------------------------------------------------------------
    */

    public function test_purok_breakdown_reflects_residents_and_sector_membership(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $purokTwo = $this->createPurok($this->barangay, 'Purok 2');

        $a = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170000013', 'resident_number' => 'A-11']);
        $this->assignSector($a, 'senior');

        $this->createResident($this->barangay, $this->purok, ['mobile' => '09170000014', 'resident_number' => 'A-12']);

        $c = $this->createResident($this->barangay, $purokTwo, ['mobile' => '09170000015', 'resident_number' => 'A-13']);
        $this->assignSector($c, 'pwd');

        $this->actingAs($admin)
            ->get('/admin/population')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Population')
                ->has('puroks', 2)
                ->where('puroks.0.name', 'Purok 1')
                ->where('puroks.0.total', 3)
                ->where('puroks.0.seniors', 1)
                ->where('puroks.0.pwd', 0)
                ->where('puroks.1.name', 'Purok 2')
                ->where('puroks.1.total', 1)
                ->where('puroks.1.seniors', 0)
                ->where('puroks.1.pwd', 1));
    }

    public function test_puroks_without_residents_are_reported_as_zero(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->createPurok($this->barangay, 'Purok 2');

        $this->actingAs($admin)
            ->get('/admin/population')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('puroks', 2)
                ->where('puroks.0.total', 1)
                ->where('puroks.0.seniors', 0)
                ->where('puroks.0.pwd', 0));
    }

    public function test_population_page_renders_with_no_data(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/population')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Population')
                ->where('stats.totalResidents', 1)
                ->has('puroks', 1));
    }

    /*
    |--------------------------------------------------------------------------
    | Performance guard: aggregate queries must not scale with residents
    |--------------------------------------------------------------------------
    */

    public function test_population_query_count_is_constant_regardless_of_resident_count(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        DB::enableQueryLog();

        $this->actingAs($admin)
            ->get('/admin/population')
            ->assertOk();

        $baseline = count(DB::getQueryLog());

        for ($i = 0; $i < 10; $i++) {
            $resident = $this->createResident($this->barangay, $this->purok, [
                'mobile' => '0917999'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'resident_number' => 'P-'.$i,
            ]);

            if ($i % 2 === 0) {
                $this->assignSector($resident, 'senior');
            }
        }

        DB::flushQueryLog();

        $this->actingAs($admin)
            ->get('/admin/population')
            ->assertOk();

        $after = count(DB::getQueryLog());

        DB::disableQueryLog();

        $this->assertSame($baseline, $after);
    }

    /*
    |--------------------------------------------------------------------------
    | Legacy mock fields must be gone
    |--------------------------------------------------------------------------
    */

    public function test_page_does_not_expose_invented_metrics(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/population')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->missing('stats.estimatedFamilies')
                ->missing('stats.luponCases'));
    }
}
