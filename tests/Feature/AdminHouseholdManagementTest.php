<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Household;
use App\Models\Resident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class AdminHouseholdManagementTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    public function test_admin_can_create_a_household_head_and_attach_a_resident_to_it(): void
    {
        $barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $purok = $this->createPurok($barangay);
        $admin = $this->createStaff($barangay, 'admin');

        $this->actingAs($admin)->post('/admin/residents', [
            'purok_id' => $purok->id,
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
            'purok_id' => $purok->id,
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
        $barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $purok = $this->createPurok($barangay);
        $head = $this->createResident($barangay, $purok, ['mobile' => '09175550111']);
        $household = Household::create([
            'barangay_id' => $barangay->id, 'purok_id' => $purok->id,
            'household_code' => 'HH-TEST001', 'head_resident_id' => $head->id,
        ]);
        $head->update(['household_id' => $household->id, 'household_relationship' => 'Head']);
        $admin = $this->createStaff($barangay, 'admin');

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
}
