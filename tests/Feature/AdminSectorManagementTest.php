<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Certificate;
use App\Models\CertificateRequest;
use App\Models\Purok;
use App\Models\Resident;
use App\Models\Sector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class AdminSectorManagementTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Purok $purok;

    private int $residentMobileSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $this->purok = $this->createPurok($this->barangay, 'Purok 1');
    }

    private function createSector(string $name, string $slug, array $attributes = []): Sector
    {
        return Sector::create([
            'name' => $name,
            'slug' => $slug,
            'requires_proof' => true,
            'is_active' => true,
            ...$attributes,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Access control
    |--------------------------------------------------------------------------
    */

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/admin/sectors')->assertRedirect(route('login'));
    }

    public function test_non_admin_roles_cannot_access_sector_management(): void
    {
        foreach (['clerk', 'senior', 'pwd', 'lupon'] as $role) {
            $user = $this->createStaff($this->barangay, $role);

            $this->actingAs($user)->get('/admin/sectors')->assertStatus(403);
            $this->actingAs($user)->post('/admin/sectors', [
                'name' => 'Injected',
            ])->assertStatus(403);
        }
    }

    public function test_super_admin_cannot_access_sector_management(): void
    {
        $superAdmin = $this->createStaff($this->barangay, 'super_admin');

        $this->actingAs($superAdmin)->get('/admin/sectors')->assertStatus(403);
    }

    public function test_inactive_admin_cannot_access_sector_management(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin', ['status' => 'inactive']);

        $this->actingAs($admin)->get('/admin/sectors')->assertStatus(403);
    }

    /*
    |--------------------------------------------------------------------------
    | Index
    |--------------------------------------------------------------------------
    */

    public function test_admin_can_view_sectors_with_usage_counts(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $senior = $this->createSector('Senior Citizen', 'senior');
        $this->createSector('Person with Disability (PWD)', 'pwd');

        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170000061']);
        $resident->sectors()->attach($senior->id);

        $this->actingAs($admin)
            ->get('/admin/sectors')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Sectors')
                ->has('sectors', 2)
                ->where('stats.total', 2)
                ->where('stats.active', 2));
    }

    public function test_index_usage_counts_reflect_dependent_residents(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $senior = $this->createSector('Senior Citizen', 'senior');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170000062']);
        $resident->sectors()->attach($senior->id);

        $this->actingAs($admin)
            ->get('/admin/sectors')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Sectors')
                ->where('sectors.0.slug', 'senior')
                ->where('sectors.0.residents_count', 1));
    }

    /*
    |--------------------------------------------------------------------------
    | Create
    |--------------------------------------------------------------------------
    */

    public function test_admin_can_create_sector_with_explicit_slug(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post('/admin/sectors', [
                'name' => 'Solo Parent',
                'slug' => 'solo-parent',
                'requires_proof' => true,
                'is_active' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('sectors', [
            'name' => 'Solo Parent',
            'slug' => 'solo-parent',
            'requires_proof' => true,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_create_sector_with_auto_generated_slug(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post('/admin/sectors', [
                'name' => 'Out of School Youth',
                'slug' => '',
                'requires_proof' => false,
                'is_active' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('sectors', [
            'name' => 'Out of School Youth',
            'slug' => 'out-of-school-youth',
            'requires_proof' => false,
        ]);
    }

    public function test_create_rejects_duplicate_slug(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->createSector('Senior Citizen', 'senior');

        $this->actingAs($admin)
            ->from('/admin/sectors')
            ->post('/admin/sectors', [
                'name' => 'Senior Duplicate',
                'slug' => 'senior',
            ])
            ->assertSessionHasErrors('slug');
    }

    public function test_create_rejects_invalid_slug_format(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->from('/admin/sectors')
            ->post('/admin/sectors', [
                'name' => 'Bad Slug',
                'slug' => 'Bad Slug!',
            ])
            ->assertSessionHasErrors('slug');
    }

    public function test_create_requires_name(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->from('/admin/sectors')
            ->post('/admin/sectors', [
                'name' => '',
            ])
            ->assertSessionHasErrors('name');
    }

    public function test_create_rejects_name_that_cannot_produce_a_slug(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->from('/admin/sectors')
            ->post('/admin/sectors', [
                'name' => '###',
                'slug' => '',
            ])
            ->assertSessionHasErrors('slug');
    }

    /*
    |--------------------------------------------------------------------------
    | Update
    |--------------------------------------------------------------------------
    */

    public function test_admin_can_update_sector_name_and_flags(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $sector = $this->createSector('Senior Citizen', 'senior');

        $this->actingAs($admin)
            ->put("/admin/sectors/{$sector->id}", [
                'name' => 'Senior Citizens',
                'requires_proof' => false,
                'is_active' => false,
            ])
            ->assertRedirect();

        $sector->refresh();

        $this->assertSame('Senior Citizens', $sector->name);
        $this->assertFalse($sector->requires_proof);
        $this->assertFalse($sector->is_active);
    }

    public function test_update_does_not_change_slug(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $sector = $this->createSector('Senior Citizen', 'senior');

        $this->actingAs($admin)
            ->put("/admin/sectors/{$sector->id}", [
                'name' => 'Senior Citizen',
                'slug' => 'renamed',
                'requires_proof' => true,
                'is_active' => true,
            ])
            ->assertRedirect();

        $this->assertSame('senior', $sector->refresh()->slug);
    }

    public function test_update_requires_name(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $sector = $this->createSector('Senior Citizen', 'senior');

        $this->actingAs($admin)
            ->from('/admin/sectors')
            ->put("/admin/sectors/{$sector->id}", [
                'name' => '',
            ])
            ->assertSessionHasErrors('name');
    }

    /*
    |--------------------------------------------------------------------------
    | Existing Senior/PWD functionality must not regress
    |--------------------------------------------------------------------------
    */

    public function test_resident_registration_still_exposes_active_senior_and_pwd_sectors(): void
    {
        $this->createSector('Senior Citizen', 'senior');
        $this->createSector('Person with Disability (PWD)', 'pwd');
        $this->createSector('Solo Parent', 'solo-parent');

        $this->get('/resident/register')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Resident/Register')
                ->has('sectors', 2));
    }

    public function test_deactivating_senior_sector_removes_it_from_registration(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $senior = $this->createSector('Senior Citizen', 'senior');
        $this->createSector('Person with Disability (PWD)', 'pwd');

        $this->actingAs($admin)->put("/admin/sectors/{$senior->id}", [
            'name' => 'Senior Citizen',
            'requires_proof' => true,
            'is_active' => false,
        ])->assertRedirect();

        $this->get('/resident/register')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('sectors', 1));
    }

    public function test_index_exposes_only_barangay_scoped_pwd_members(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->createSector('Person with Disability (PWD)', 'pwd');

        $ana = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'mobile' => '09170000101',
            'resident_number' => 'A-PWD-1',
        ]);
        $this->assignSector($ana, 'pwd');

        $ben = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Ben',
            'last_name' => 'Cruz',
            'mobile' => '09170000102',
            'resident_number' => 'A-PWD-2',
        ]);
        $this->assignSector($ben, 'pwd');

        $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Cara',
            'last_name' => 'Diaz',
            'mobile' => '09170000103',
            'resident_number' => 'A-REG-1',
        ]);

        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $otherPurok = $this->createPurok($otherBarangay, 'Purok 1');
        $dino = $this->createResident($otherBarangay, $otherPurok, [
            'first_name' => 'Dino',
            'last_name' => 'Enriquez',
            'mobile' => '09170000104',
            'resident_number' => 'B-PWD-1',
        ]);
        $this->assignSector($dino, 'pwd');

        $this->actingAs($admin)->get('/admin/sectors')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Sectors')
                ->has('pwdMembers.data', 2)
                ->where('pwdMembers.total', 2)
                ->where('pwdMembers.per_page', 15)
                ->where('stats.pwdMembers', 2)
                ->where('pwdMembers.data.0.resident_number', 'A-PWD-2')
                ->where('pwdMembers.data.1.resident_number', 'A-PWD-1'));
    }

    public function test_pwd_members_payload_is_read_only_and_excludes_sensitive_fields(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->createSector('Person with Disability (PWD)', 'pwd');

        $member = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Test',
            'last_name' => 'Member',
            'mobile' => '09170000201',
            'resident_number' => 'RO-1',
        ]);
        $this->assignSector($member, 'pwd');

        $this->actingAs($admin)->get('/admin/sectors')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pwdMembers.data', 1)
                ->where('pwdMembers.data.0.resident_number', 'RO-1')
                ->where('pwdMembers.data.0.name', 'Test Member')
                ->where('pwdMembers.data.0.purok', 'Purok 1')
                ->where('pwdMembers.data.0.status', 'active')
                ->missing('pwdMembers.data.0.mobile')
                ->missing('pwdMembers.data.0.email')
                ->missing('pwdMembers.data.0.birthdate'));
    }

    public function test_inactive_pwd_members_are_included_in_the_roster(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->createSector('Person with Disability (PWD)', 'pwd');

        $active = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Active',
            'last_name' => 'Member',
            'mobile' => '09170000301',
            'resident_number' => 'IA-1',
        ]);
        $this->assignSector($active, 'pwd');

        $inactive = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Inactive',
            'last_name' => 'Member',
            'mobile' => '09170000302',
            'resident_number' => 'IA-2',
        ]);
        $this->assignSector($inactive, 'pwd');
        $inactive->update(['status' => 'inactive']);

        $this->actingAs($admin)->get('/admin/sectors')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pwdMembers.data', 2)
                ->where('pwdMembers.total', 2)
                ->where('stats.pwdMembers', 2)
                ->where('pwdMembers.data.0.resident_number', 'IA-1')
                ->where('pwdMembers.data.0.status', 'active')
                ->where('pwdMembers.data.1.resident_number', 'IA-2')
                ->where('pwdMembers.data.1.status', 'inactive'));
    }

    public function test_inactive_pwd_member_survives_a_search_that_matches_it(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->createSector('Person with Disability (PWD)', 'pwd');

        $inactive = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Retired',
            'last_name' => 'Villanueva',
            'mobile' => '09170000303',
            'resident_number' => 'IA-3',
        ]);
        $this->assignSector($inactive, 'pwd');
        $inactive->update(['status' => 'inactive']);

        $this->actingAs($admin)
            ->get('/admin/sectors?search=Villanueva')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pwdMembers.data', 1)
                ->where('pwdMembers.data.0.resident_number', 'IA-3')
                ->where('pwdMembers.data.0.status', 'inactive')
                ->where('filters.search', 'Villanueva'));
    }

    public function test_pwd_count_matches_canonical_slug_not_sector_name(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->createSector('Person with Disability (PWD)', 'pwd');
        $this->createSector('PWD Lookalike', 'pwd-lookalike');

        $lookalike = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Look',
            'last_name' => 'Alike',
            'mobile' => '09170000401',
            'resident_number' => 'LK-1',
        ]);
        $this->assignSector($lookalike, 'pwd-lookalike');

        $real = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Real',
            'last_name' => 'Member',
            'mobile' => '09170000402',
            'resident_number' => 'LK-2',
        ]);
        $this->assignSector($real, 'pwd');

        $this->actingAs($admin)->get('/admin/sectors')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pwdMembers.data', 1)
                ->where('stats.pwdMembers', 1)
                ->where('pwdMembers.data.0.resident_number', 'LK-2'));
    }

    /*
    |--------------------------------------------------------------------------
    | Senior Citizens members (read-only, barangay-scoped, paginated)
    |--------------------------------------------------------------------------
    */

    public function test_index_exposes_only_barangay_scoped_senior_members(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->createSector('Senior Citizen', 'senior');

        $ana = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'mobile' => '09170000501',
            'resident_number' => 'A-SEN-1',
        ]);
        $this->assignSector($ana, 'senior');

        $ben = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Ben',
            'last_name' => 'Cruz',
            'mobile' => '09170000502',
            'resident_number' => 'A-SEN-2',
        ]);
        $this->assignSector($ben, 'senior');

        $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Cara',
            'last_name' => 'Diaz',
            'mobile' => '09170000503',
            'resident_number' => 'A-REG-1',
        ]);

        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $otherPurok = $this->createPurok($otherBarangay, 'Purok 1');
        $dino = $this->createResident($otherBarangay, $otherPurok, [
            'first_name' => 'Dino',
            'last_name' => 'Enriquez',
            'mobile' => '09170000504',
            'resident_number' => 'B-SEN-1',
        ]);
        $this->assignSector($dino, 'senior');

        $this->actingAs($admin)->get('/admin/sectors')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Sectors')
                ->has('seniorMembers.data', 2)
                ->where('stats.seniorMembers', 2)
                ->where('seniorMembers.total', 2)
                ->where('seniorMembers.data.0.resident_number', 'A-SEN-2')
                ->where('seniorMembers.data.1.resident_number', 'A-SEN-1'));
    }

    public function test_senior_members_payload_is_read_only_and_excludes_sensitive_fields(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->createSector('Senior Citizen', 'senior');

        $member = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Test',
            'last_name' => 'Senior',
            'mobile' => '09170000601',
            'resident_number' => 'RO-SEN-1',
        ]);
        $this->assignSector($member, 'senior');

        $this->actingAs($admin)->get('/admin/sectors')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('seniorMembers.data', 1)
                ->where('seniorMembers.data.0.resident_number', 'RO-SEN-1')
                ->where('seniorMembers.data.0.name', 'Test Senior')
                ->where('seniorMembers.data.0.purok', 'Purok 1')
                ->where('seniorMembers.data.0.status', 'active')
                ->where('seniorMembers.data.0', fn (Collection $row) => [
                    'id',
                    'resident_number',
                    'name',
                    'purok',
                    'status',
                ] === $row->keys()->all())
                ->missing('seniorMembers.data.0.mobile')
                ->missing('seniorMembers.data.0.email')
                ->missing('seniorMembers.data.0.birthdate'));
    }

    public function test_inactive_senior_members_are_included_in_the_roster(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->createSector('Senior Citizen', 'senior');

        $active = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Active',
            'last_name' => 'Senior',
            'mobile' => '09170000701',
            'resident_number' => 'IA-SEN-1',
        ]);
        $this->assignSector($active, 'senior');

        $inactive = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Inactive',
            'last_name' => 'Senior',
            'mobile' => '09170000702',
            'resident_number' => 'IA-SEN-2',
        ]);
        $this->assignSector($inactive, 'senior');
        $inactive->update(['status' => 'inactive']);

        $this->actingAs($admin)->get('/admin/sectors')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('seniorMembers.data', 2)
                ->where('stats.seniorMembers', 2)
                ->where('seniorMembers.total', 2)
                ->where('seniorMembers.data.0.resident_number', 'IA-SEN-1')
                ->where('seniorMembers.data.0.status', 'active')
                ->where('seniorMembers.data.1.resident_number', 'IA-SEN-2')
                ->where('seniorMembers.data.1.status', 'inactive'));
    }

    public function test_senior_members_exclude_residents_not_tagged_senior(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->createSector('Senior Citizen', 'senior');
        $this->createSector('Person with Disability (PWD)', 'pwd');

        $senior = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Sole',
            'last_name' => 'Senior',
            'mobile' => '09170000801',
            'resident_number' => 'SM-SEN-1',
        ]);
        $this->assignSector($senior, 'senior');

        $pwdOnly = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Pwd',
            'last_name' => 'Only',
            'mobile' => '09170000802',
            'resident_number' => 'SM-PWD-1',
        ]);
        $this->assignSector($pwdOnly, 'pwd');

        $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'No',
            'last_name' => 'Sector',
            'mobile' => '09170000803',
            'resident_number' => 'SM-REG-1',
        ]);

        $this->actingAs($admin)->get('/admin/sectors')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('seniorMembers.data', 1)
                ->where('stats.seniorMembers', 1)
                ->where('seniorMembers.data.0.resident_number', 'SM-SEN-1'));
    }

    public function test_senior_count_matches_canonical_slug_not_sector_name(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->createSector('Senior Citizen', 'senior');
        $this->createSector('Senior Lookalike', 'senior-lookalike');

        $lookalike = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Look',
            'last_name' => 'Alike',
            'mobile' => '09170000901',
            'resident_number' => 'SL-1',
        ]);
        $this->assignSector($lookalike, 'senior-lookalike');

        $real = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Real',
            'last_name' => 'Senior',
            'mobile' => '09170000902',
            'resident_number' => 'SL-2',
        ]);
        $this->assignSector($real, 'senior');

        $this->actingAs($admin)->get('/admin/sectors')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('seniorMembers.data', 1)
                ->where('stats.seniorMembers', 1)
                ->where('seniorMembers.data.0.resident_number', 'SL-2'));
    }

    public function test_senior_members_are_paginated_with_metadata(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $seniorSector = $this->createSector('Senior Citizen', 'senior');

        for ($i = 1; $i <= 16; $i++) {
            $resident = $this->createResident($this->barangay, $this->purok, [
                'first_name' => 'Paged',
                'last_name' => sprintf('Sortable%02d', $i),
                'mobile' => sprintf('0917001%04d', $i),
                'resident_number' => sprintf('SR-%02d', $i),
            ]);
            $resident->sectors()->attach($seniorSector->id);
        }

        $this->actingAs($admin)->get('/admin/sectors')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.seniorMembers', 16)
                ->where('seniorMembers.total', 16)
                ->where('seniorMembers.per_page', 15)
                ->where('seniorMembers.current_page', 1)
                ->where('seniorMembers.last_page', 2)
                ->where('seniorMembers.from', 1)
                ->where('seniorMembers.to', 15)
                ->has('seniorMembers.data', 15)
                ->where('seniorMembers.data.0.resident_number', 'SR-01')
                ->where('seniorMembers.data.14.resident_number', 'SR-15'));

        $this->actingAs($admin)->get('/admin/sectors?senior_page=2')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('seniorMembers.current_page', 2)
                ->where('seniorMembers.last_page', 2)
                ->where('seniorMembers.from', 16)
                ->where('seniorMembers.to', 16)
                ->has('seniorMembers.data', 1)
                ->where('seniorMembers.data.0.resident_number', 'SR-16'));
    }

    public function test_senior_members_use_stable_ordering_for_equal_names(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->createSector('Senior Citizen', 'senior');

        $first = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Same',
            'last_name' => 'Name',
            'mobile' => '09170001001',
            'resident_number' => 'EQ-1',
        ]);
        $this->assignSector($first, 'senior');

        $second = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Same',
            'last_name' => 'Name',
            'mobile' => '09170001002',
            'resident_number' => 'EQ-2',
        ]);
        $this->assignSector($second, 'senior');

        $third = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Same',
            'last_name' => 'Name',
            'mobile' => '09170001003',
            'resident_number' => 'EQ-3',
        ]);
        $this->assignSector($third, 'senior');

        $alphabetical = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Aaron',
            'last_name' => 'Aaa',
            'mobile' => '09170001004',
            'resident_number' => 'EQ-0',
        ]);
        $this->assignSector($alphabetical, 'senior');

        $this->actingAs($admin)->get('/admin/sectors')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('seniorMembers.data', 4)
                ->where('seniorMembers.data.0.resident_number', 'EQ-0')
                ->where('seniorMembers.data.1.resident_number', 'EQ-1')
                ->where('seniorMembers.data.2.resident_number', 'EQ-2')
                ->where('seniorMembers.data.3.resident_number', 'EQ-3'));
    }

    public function test_existing_sector_tabs_remain_intact_with_senior_members(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->createSector('Senior Citizen', 'senior');
        $this->createSector('Person with Disability (PWD)', 'pwd');

        $senior = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Senior',
            'last_name' => 'Only',
            'mobile' => '09170001101',
            'resident_number' => 'CO-SEN-1',
        ]);
        $this->assignSector($senior, 'senior');

        $pwd = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Pwd',
            'last_name' => 'Only',
            'mobile' => '09170001102',
            'resident_number' => 'CO-PWD-1',
        ]);
        $this->assignSector($pwd, 'pwd');

        $this->actingAs($admin)->get('/admin/sectors')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Sectors')
                ->has('sectors', 2)
                ->where('stats.total', 2)
                ->where('stats.active', 2)
                ->has('pwdMembers.data', 1)
                ->where('stats.pwdMembers', 1)
                ->where('pwdMembers.data.0.resident_number', 'CO-PWD-1')
                ->has('seniorMembers.data', 1)
                ->where('stats.seniorMembers', 1)
                ->where('seniorMembers.data.0.resident_number', 'CO-SEN-1'));
    }

    /*
    |--------------------------------------------------------------------------
    | Certificates monitoring (read-only, barangay-scoped, all-time)
    |--------------------------------------------------------------------------
    |
    | Approved definitions: Processed = Completed = individual certificate
    | requests whose current status is `Released` or `Rejected`, all-time,
    | scoped to the authenticated admin's barangay.
    |
    */

    public function test_certificate_monitoring_counts_only_released_and_rejected(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $certificate = $this->createCertificate($this->barangay);

        foreach (['Released', 'Released', 'Rejected'] as $index => $status) {
            $this->createCertificateRequest(
                $this->barangay,
                $this->purok,
                $certificate,
                $status,
                'COUNT-'.$index
            );
        }

        // Non-terminal statuses must never contribute to either metric.
        foreach (['Pending', 'Needs Action', 'For Payment', 'Processing', 'Ready for Claim'] as $index => $status) {
            $this->createCertificateRequest(
                $this->barangay,
                $this->purok,
                $certificate,
                $status,
                'SKIP-'.$index
            );
        }

        $this->actingAs($admin)->get('/admin/sectors')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Sectors')
                ->where('certificates.processed', 3)
                ->where('certificates.completed', 3));
    }

    public function test_certificate_monitoring_is_all_time(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $certificate = $this->createCertificate($this->barangay);

        $old = $this->createCertificateRequest(
            $this->barangay,
            $this->purok,
            $certificate,
            'Released',
            'OLD-001'
        );
        $old->created_at = now()->subYears(3);
        $old->submitted_at = now()->subYears(3);
        $old->save();

        $this->actingAs($admin)->get('/admin/sectors')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('certificates.processed', 1)
                ->where('certificates.completed', 1));
    }

    public function test_certificate_monitoring_excludes_other_barangays(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $certificate = $this->createCertificate($this->barangay);

        $this->createCertificateRequest(
            $this->barangay,
            $this->purok,
            $certificate,
            'Released',
            'OWN-001'
        );

        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $otherPurok = $this->createPurok($otherBarangay, 'Purok 1');
        $otherCertificate = $this->createCertificate($otherBarangay);

        foreach (['Released', 'Rejected', 'Released'] as $index => $status) {
            $this->createCertificateRequest(
                $otherBarangay,
                $otherPurok,
                $otherCertificate,
                $status,
                'FOREIGN-'.$index
            );
        }

        $this->actingAs($admin)->get('/admin/sectors')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('certificates.processed', 1)
                ->where('certificates.completed', 1));
    }

    public function test_certificate_monitoring_counts_each_request_separately(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $certificate = $this->createCertificate($this->barangay);
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170002001',
        ]);

        foreach (['Released', 'Released', 'Rejected'] as $index => $status) {
            $this->createCertificateRequest(
                $this->barangay,
                $this->purok,
                $certificate,
                $status,
                'SAME-'.$index,
                $resident
            );
        }

        $this->actingAs($admin)->get('/admin/sectors')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('certificates.processed', 3)
                ->where('certificates.completed', 3));
    }

    public function test_certificate_monitoring_payload_exposes_counts_and_terminal_list(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $certificate = $this->createCertificate($this->barangay);
        $this->createCertificateRequest(
            $this->barangay,
            $this->purok,
            $certificate,
            'Released',
            'AGG-001'
        );

        $this->actingAs($admin)->get('/admin/sectors')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('certificates')
                ->where('certificates', fn (Collection $payload) => [
                    'processed',
                    'completed',
                ] === $payload->keys()->all())
                ->has('certificateRequests')
                ->has('certificateFilters')
                ->where('certificateFilters', fn (Collection $payload) => [
                    'search',
                ] === $payload->keys()->all()));
    }

    public function test_certificate_monitoring_lists_only_released_and_rejected(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $certificate = $this->createCertificate($this->barangay);

        foreach (['Released', 'Released', 'Rejected'] as $index => $status) {
            $this->createCertificateRequest(
                $this->barangay,
                $this->purok,
                $certificate,
                $status,
                'SHOW-'.$index
            );
        }

        // Every other status must be excluded from the list, not only from the
        // aggregate counts.
        foreach (['Pending', 'Needs Action', 'For Payment', 'Processing', 'Ready for Claim'] as $index => $status) {
            $this->createCertificateRequest(
                $this->barangay,
                $this->purok,
                $certificate,
                $status,
                'HIDE-'.$index
            );
        }

        $this->actingAs($admin)->get('/admin/sectors')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Sectors')
                ->has('certificateRequests.data', 3)
                ->where('certificateRequests.total', 3)
                ->where('certificateRequests.data', fn (Collection $rows) => $rows->every(
                    fn ($row) => in_array($row['status'], ['Released', 'Rejected'], true)
                )));
    }

    public function test_certificate_monitoring_list_excludes_other_barangays(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $certificate = $this->createCertificate($this->barangay);

        $this->createCertificateRequest(
            $this->barangay,
            $this->purok,
            $certificate,
            'Released',
            'OWN-LIST-001'
        );

        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $otherPurok = $this->createPurok($otherBarangay, 'Purok 1');
        $otherCertificate = $this->createCertificate($otherBarangay);

        $this->createCertificateRequest(
            $otherBarangay,
            $otherPurok,
            $otherCertificate,
            'Released',
            'FOREIGN-LIST-001'
        );

        $this->actingAs($admin)->get('/admin/sectors')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('certificateRequests.data', 1)
                ->where('certificateRequests.data.0.reference', 'OWN-LIST-001'));
    }

    public function test_certificate_monitoring_search_matches_reference_and_preserves_restriction(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $certificate = $this->createCertificate($this->barangay);

        $this->createCertificateRequest(
            $this->barangay,
            $this->purok,
            $certificate,
            'Released',
            'SEARCH-HIT-001'
        );

        $this->createCertificateRequest(
            $this->barangay,
            $this->purok,
            $certificate,
            'Released',
            'OTHER-001'
        );

        // A non-terminal request whose reference also matches the search term
        // must still never surface.
        $this->createCertificateRequest(
            $this->barangay,
            $this->purok,
            $certificate,
            'Processing',
            'SEARCH-HIT-002'
        );

        $this->actingAs($admin)->get('/admin/sectors?cert_search=SEARCH-HIT')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('certificateRequests.data', 1)
                ->where('certificateRequests.total', 1)
                ->where('certificateRequests.data.0.reference', 'SEARCH-HIT-001')
                ->where('certificateFilters.search', 'SEARCH-HIT'));
    }

    public function test_certificate_monitoring_search_matches_resident_and_certificate_name(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $certificate = $this->createCertificate($this->barangay);

        $resident = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'mobile' => $this->nextResidentMobile(),
        ]);

        $this->createCertificateRequest(
            $this->barangay,
            $this->purok,
            $certificate,
            'Rejected',
            'RESIDENT-001',
            $resident
        );

        $this->actingAs($admin)->get('/admin/sectors?cert_search=Santos')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('certificateRequests.data', 1)
                ->where('certificateRequests.data.0.resident_name', 'Maria Santos'));

        $this->actingAs($admin)->get('/admin/sectors?cert_search=Barangay Clearance')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('certificateRequests.data', 1));
    }

    public function test_certificate_monitoring_search_cannot_bypass_the_status_restriction(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $certificate = $this->createCertificate($this->barangay);

        // Only non-terminal requests exist; no search string may reveal them.
        foreach (['Pending', 'Processing', 'Ready for Claim'] as $index => $status) {
            $this->createCertificateRequest(
                $this->barangay,
                $this->purok,
                $certificate,
                $status,
                'ONLY-NONTERMINAL-'.$index
            );
        }

        $this->actingAs($admin)->get('/admin/sectors?cert_search=NONTERMINAL')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('certificateRequests.data', 0)
                ->where('certificateRequests.total', 0));
    }

    public function test_certificate_monitoring_pagination_does_not_leak_other_statuses(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $certificate = $this->createCertificate($this->barangay);

        for ($index = 0; $index < 16; $index++) {
            $this->createCertificateRequest(
                $this->barangay,
                $this->purok,
                $certificate,
                $index % 2 === 0 ? 'Released' : 'Rejected',
                'PAGE-'.$index
            );
        }

        // A non-terminal request must not leak onto any page.
        $this->createCertificateRequest(
            $this->barangay,
            $this->purok,
            $certificate,
            'Processing',
            'PAGE-HIDDEN'
        );

        $this->actingAs($admin)->get('/admin/sectors')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('certificateRequests.data', 15)
                ->where('certificateRequests.total', 16)
                ->where('certificateRequests.last_page', 2));

        $this->actingAs($admin)->get('/admin/sectors?cert_page=2')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('certificateRequests.data', 1)
                ->where('certificateRequests.data', fn (Collection $rows) => $rows->every(
                    fn ($row) => in_array($row['status'], ['Released', 'Rejected'], true)
                )));
    }

    public function test_certificate_monitoring_list_omits_sensitive_fields(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $certificate = $this->createCertificate($this->barangay);
        $this->createCertificateRequest(
            $this->barangay,
            $this->purok,
            $certificate,
            'Released',
            'FIELDS-001'
        );

        $this->actingAs($admin)->get('/admin/sectors')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('certificateRequests.data.0', fn (Collection $row) => [
                    'id',
                    'reference',
                    'certificate',
                    'resident_name',
                    'resident_number',
                    'purpose',
                    'fee',
                    'status',
                    'submitted_at',
                    'status_at',
                ] === $row->keys()->all())
                ->missing('certificateRequests.data.0.admin_note')
                ->missing('certificateRequests.data.0.mobile')
                ->missing('certificateRequests.data.0.email')
                ->missing('certificateRequests.data.0.birthdate')
                ->missing('certificateRequests.data.0.address_line'));
    }

    public function test_certificate_monitoring_requires_admin(): void
    {
        foreach (['clerk', 'senior', 'pwd', 'lupon'] as $role) {
            $user = $this->createStaff($this->barangay, $role);

            $this->actingAs($user)->get('/admin/sectors')->assertStatus(403);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Tab structure (source-level assertions)
    |--------------------------------------------------------------------------
    |
    | The repository does not currently ship a React component test runner:
    | there is no vitest/jest configuration, no DOM environment, no
    | `@testing-library/react`, and no `test` script. These assertions therefore
    | read the Sectors page source instead of rendering it. They parse the
    | actual `<nav>` block for the approved tab labels and order, assert the
    | removed Residents tab is gone, and pin the tab union/default state plus
    | the absence of write affordances in the read-only tab blocks.
    |
    | These are structural guards only. They do NOT prove runtime rendering
    | behaviour; a real component test would be required for that.
    |
    */

    public function test_sectors_tab_order_matches_approved_order(): void
    {
        $this->assertSame(
            [
                'Sector Definitions',
                'PWD Members',
                'Senior Citizens',
                'Certificates',
                'Lupon Cases',
            ],
            $this->sectorTabLabels(),
            'Sector navigation tabs are not in the approved order.'
        );
    }

    public function test_removed_residents_tab_is_absent_from_navigation(): void
    {
        $labels = $this->sectorTabLabels();

        // The removed tab was labelled "Residents" (previously rendered as a
        // "Resident Snapshot"). Asserting against the navigation labels only
        // means legitimate mentions of "residents" elsewhere on the page (for
        // example the PWD or Senior member lists) can neither satisfy nor break
        // this check.
        $this->assertNotContains('Residents', $labels);
        $this->assertNotContains('General Residents', $labels);

        $source = $this->sectorPageSource();

        // The removed tab must not reappear as a content block or tab
        // identifier. These are exact literals, so unrelated prose that merely
        // contains the word "residents" is not affected.
        $this->assertStringNotContainsString("activeTab === 'Residents'", $source);
        $this->assertStringNotContainsString("activeTab === 'general'", $source);
    }

    public function test_sector_definitions_is_the_default_tab(): void
    {
        // Pins the exact tab union and the default selection. Reintroducing a
        // Residents-style tab identifier to the union would break this.
        $this->assertMatchesRegularExpression(
            "/useState<\s*'sectors'\s*\|\s*'pwd'\s*\|\s*'senior'\s*\|\s*'certificates'\s*\|\s*'lupon'\s*>\('sectors'\)/",
            $this->sectorPageSource()
        );
    }

    public function test_lupon_tab_shows_count_unavailable_without_fake_counts(): void
    {
        $block = $this->tabBlock('lupon');

        $normalized = (string) preg_replace('/\s+/', ' ', $block);

        $this->assertStringContainsString(
            'Lupon case counts are unavailable because case records are not yet connected.',
            $normalized
        );

        // No bound value, count expression, or interpolated metric.
        $this->assertStringNotContainsString('{certificates', $block);
        $this->assertStringNotContainsString('{stats', $block);
        $this->assertStringNotContainsString('count(', $block);
        $this->assertStringNotContainsString('value=', $block);
    }

    public function test_lupon_tab_exposes_no_case_management_actions(): void
    {
        $block = $this->tabBlock('lupon');

        foreach (['<button', 'onClick', '<form', '<input', '<select', '<textarea', 'method=', 'action=', 'href=', 'router.', 'useForm'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $block);
        }
    }

    public function test_certificates_tab_is_read_only_with_search_and_pagination(): void
    {
        $block = $this->tabBlock('certificates');

        /*
        | The Certificates tab is strictly read-only monitoring: it must not
        | expose any control that mutates a request. No form submission helper,
        | request-mutating verb, processing endpoint, status editor, or note
        | editor may appear inside the tab.
        */
        foreach ([
            'useForm',
            'router.put',
            'router.post',
            'router.patch',
            'router.delete',
            'method="post"',
            "method='post'",
            'updateStatus',
            'certificates.status',
            'admin_note',
            '<select',
            '<textarea',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $block);
        }

        // The approved read-only search and pagination affordances are present.
        $this->assertStringContainsString(
            'aria-label="Search certificate requests"',
            $block
        );
        $this->assertStringContainsString('<MembersPagination', $block);
    }

    public function test_standalone_certificates_sidebar_entry_is_absent(): void
    {
        $source = $this->adminLayoutSource();

        // Admin certificate monitoring moved into Sectors → Certificates, so
        // the separate sidebar destination and its active state are gone.
        $this->assertStringNotContainsString('/admin/certificates', $source);
        $this->assertStringNotContainsString("active === 'certificates'", $source);
    }

    public function test_other_admin_sidebar_entries_remain(): void
    {
        $source = $this->adminLayoutSource();

        $entries = [
            '/admin' => 'Overview',
            '/admin/announcements/approvals' => 'Announcements',
            '/admin/residents' => 'Residents',
            '/admin/qr-scanner' => 'QR Scanner',
            '/admin/sectors' => 'Sectors',
            '/admin/staff' => 'Staff',
            '/admin/population' => 'Population',
            '/admin/audit-log' => 'Audit Log',
            '/admin/reports' => 'Reports',
            '/admin/settings' => 'Settings',
        ];

        foreach ($entries as $href => $label) {
            $this->assertStringContainsString(
                'href="'.$href.'"',
                $source,
                "Admin sidebar is missing the {$href} entry."
            );
            $this->assertStringContainsString(
                'label="'.$label.'"',
                $source,
                "Admin sidebar is missing the {$label} label."
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Member roster: search, purok filter, and server-side pagination
    |--------------------------------------------------------------------------
    */

    public function test_pwd_roster_search_matches_resident_number_and_name(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->createSector('Person with Disability (PWD)', 'pwd');

        $alpha = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Alpha',
            'last_name' => 'Bravo',
            'mobile' => '09170002001',
            'resident_number' => 'SRCH-PWD-001',
        ]);
        $this->assignSector($alpha, 'pwd');

        $charlie = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Charlie',
            'last_name' => 'Delta',
            'mobile' => '09170002002',
            'resident_number' => 'SRCH-PWD-002',
        ]);
        $this->assignSector($charlie, 'pwd');

        $this->actingAs($admin)
            ->get('/admin/sectors?search=SRCH-PWD-001')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pwdMembers.data', 1)
                ->where('pwdMembers.data.0.resident_number', 'SRCH-PWD-001')
                ->where('pwdMembers.total', 1)
                ->where('filters.search', 'SRCH-PWD-001'));

        $this->actingAs($admin)
            ->get('/admin/sectors?search='.urlencode('Charlie Delta'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pwdMembers.data', 1)
                ->where('pwdMembers.data.0.resident_number', 'SRCH-PWD-002'));
    }

    public function test_pwd_roster_search_requires_every_token_to_match(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->createSector('Person with Disability (PWD)', 'pwd');

        $anaReyes = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'mobile' => '09170002011',
            'resident_number' => 'MT-1',
        ]);
        $this->assignSector($anaReyes, 'pwd');

        $anaLopez = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Ana',
            'last_name' => 'Lopez',
            'mobile' => '09170002012',
            'resident_number' => 'MT-2',
        ]);
        $this->assignSector($anaLopez, 'pwd');

        $this->actingAs($admin)
            ->get('/admin/sectors?search=Ana')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pwdMembers.data', 2)
                ->where('pwdMembers.total', 2));

        $this->actingAs($admin)
            ->get('/admin/sectors?search='.urlencode('Ana Reyes'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pwdMembers.data', 1)
                ->where('pwdMembers.data.0.resident_number', 'MT-1'));

        $this->actingAs($admin)
            ->get('/admin/sectors?search='.urlencode('Ana Villanueva'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pwdMembers.data', 0)
                ->where('pwdMembers.total', 0));
    }

    public function test_pwd_roster_search_matches_purok_middle_name_and_suffix(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->createSector('Person with Disability (PWD)', 'pwd');

        $mabini = $this->createPurok($this->barangay, 'Mabini');

        $inMabini = $this->createResident($this->barangay, $mabini, [
            'first_name' => 'First',
            'last_name' => 'PurokMember',
            'mobile' => '09170002021',
            'resident_number' => 'PS-1',
        ]);
        $this->assignSector($inMabini, 'pwd');

        $inPurokOne = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Second',
            'last_name' => 'PurokMember',
            'mobile' => '09170002022',
            'resident_number' => 'PS-2',
        ]);
        $inPurokOne->update(['middle_name' => 'Santos', 'suffix' => 'III']);
        $this->assignSector($inPurokOne, 'pwd');

        $this->actingAs($admin)
            ->get('/admin/sectors?search=Mabini')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pwdMembers.data', 1)
                ->where('pwdMembers.data.0.resident_number', 'PS-1'));

        $this->actingAs($admin)
            ->get('/admin/sectors?search=Santos')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pwdMembers.data', 1)
                ->where('pwdMembers.data.0.resident_number', 'PS-2'));

        $this->actingAs($admin)
            ->get('/admin/sectors?search=III')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pwdMembers.data', 1)
                ->where('pwdMembers.data.0.resident_number', 'PS-2'));
    }

    public function test_senior_roster_search_matches_resident_number_and_name(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->createSector('Senior Citizen', 'senior');

        $one = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Senior',
            'last_name' => 'Aguinaldo',
            'mobile' => '09170002031',
            'resident_number' => 'SRCH-SEN-001',
        ]);
        $this->assignSector($one, 'senior');

        $two = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Elderly',
            'last_name' => 'Bonifacio',
            'mobile' => '09170002032',
            'resident_number' => 'SRCH-SEN-002',
        ]);
        $this->assignSector($two, 'senior');

        $this->actingAs($admin)
            ->get('/admin/sectors?search=SRCH-SEN-002')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('seniorMembers.data', 1)
                ->where('seniorMembers.data.0.resident_number', 'SRCH-SEN-002')
                ->where('seniorMembers.total', 1)
                ->where('filters.search', 'SRCH-SEN-002'));

        $this->actingAs($admin)
            ->get('/admin/sectors?search='.urlencode('Senior Aguinaldo'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('seniorMembers.data', 1)
                ->where('seniorMembers.data.0.resident_number', 'SRCH-SEN-001'));
    }

    public function test_purok_filter_returns_only_that_puroks_members_on_both_lists(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->createSector('Person with Disability (PWD)', 'pwd');
        $this->createSector('Senior Citizen', 'senior');

        $second = $this->createPurok($this->barangay, 'Purok Dos');

        $herePwd = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Here',
            'last_name' => 'Pwd',
            'mobile' => '09170002041',
            'resident_number' => 'PF-PWD-1',
        ]);
        $this->assignSector($herePwd, 'pwd');

        $therePwd = $this->createResident($this->barangay, $second, [
            'first_name' => 'There',
            'last_name' => 'Pwd',
            'mobile' => '09170002042',
            'resident_number' => 'PF-PWD-2',
        ]);
        $this->assignSector($therePwd, 'pwd');

        $thereSenior = $this->createResident($this->barangay, $second, [
            'first_name' => 'There',
            'last_name' => 'Senior',
            'mobile' => '09170002043',
            'resident_number' => 'PF-SEN-1',
        ]);
        $this->assignSector($thereSenior, 'senior');

        $this->actingAs($admin)
            ->get('/admin/sectors?purok='.$second->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pwdMembers.data', 1)
                ->where('pwdMembers.data.0.resident_number', 'PF-PWD-2')
                ->where('stats.pwdMembers', 2)
                ->has('seniorMembers.data', 1)
                ->where('seniorMembers.data.0.resident_number', 'PF-SEN-1')
                ->where('stats.seniorMembers', 1)
                ->where('filters.purok', (string) $second->id));
    }

    public function test_purok_filter_is_barangay_scoped_and_ignores_invalid_values(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->createSector('Person with Disability (PWD)', 'pwd');

        $member = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Scoped',
            'last_name' => 'Member',
            'mobile' => '09170002051',
            'resident_number' => 'SC-1',
        ]);
        $this->assignSector($member, 'pwd');

        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $otherPurok = $this->createPurok($otherBarangay, 'Purok Foreign');

        $foreign = $this->createResident($otherBarangay, $otherPurok, [
            'first_name' => 'Foreign',
            'last_name' => 'Member',
            'mobile' => '09170002052',
            'resident_number' => 'SC-2',
        ]);
        $this->assignSector($foreign, 'pwd');

        $this->actingAs($admin)
            ->get('/admin/sectors?purok='.$otherPurok->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pwdMembers.data', 0)
                ->where('pwdMembers.total', 0));

        $this->actingAs($admin)
            ->get('/admin/sectors?purok=not-a-number')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pwdMembers.data', 1)
                ->where('pwdMembers.data.0.resident_number', 'SC-1'));
    }

    public function test_search_and_purok_filter_combine(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->createSector('Person with Disability (PWD)', 'pwd');

        $second = $this->createPurok($this->barangay, 'Purok Dos');

        $firstMaria = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Maria',
            'last_name' => 'Clara',
            'mobile' => '09170002061',
            'resident_number' => 'CB-1',
        ]);
        $this->assignSector($firstMaria, 'pwd');

        $secondMaria = $this->createResident($this->barangay, $second, [
            'first_name' => 'Maria',
            'last_name' => 'Clara',
            'mobile' => '09170002062',
            'resident_number' => 'CB-2',
        ]);
        $this->assignSector($secondMaria, 'pwd');

        $otherName = $this->createResident($this->barangay, $second, [
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'mobile' => '09170002063',
            'resident_number' => 'CB-3',
        ]);
        $this->assignSector($otherName, 'pwd');

        $this->actingAs($admin)
            ->get('/admin/sectors?search='.urlencode('Maria Clara').'&purok='.$second->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pwdMembers.data', 1)
                ->where('pwdMembers.data.0.resident_number', 'CB-2')
                ->where('pwdMembers.total', 1)
                ->where('filters.search', 'Maria Clara')
                ->where('filters.purok', (string) $second->id));

        $this->actingAs($admin)
            ->get('/admin/sectors?search=Juan&purok='.$second->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pwdMembers.data', 1)
                ->where('pwdMembers.data.0.resident_number', 'CB-3'));

        $this->actingAs($admin)
            ->get('/admin/sectors?search=Juan&purok='.$this->purok->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pwdMembers.data', 0)
                ->where('pwdMembers.total', 0));
    }

    public function test_pwd_roster_is_paginated_independently_of_the_senior_roster(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $pwdSector = $this->createSector('Person with Disability (PWD)', 'pwd');
        $seniorSector = $this->createSector('Senior Citizen', 'senior');

        for ($i = 1; $i <= 16; $i++) {
            $pwd = $this->createResident($this->barangay, $this->purok, [
                'first_name' => 'Paged',
                'last_name' => sprintf('Pwd%02d', $i),
                'mobile' => sprintf('0917002%04d', $i),
                'resident_number' => sprintf('PD-%02d', $i),
            ]);
            $pwd->sectors()->attach($pwdSector->id);

            $senior = $this->createResident($this->barangay, $this->purok, [
                'first_name' => 'Paged',
                'last_name' => sprintf('Sen%02d', $i),
                'mobile' => sprintf('0917003%04d', $i),
                'resident_number' => sprintf('SD-%02d', $i),
            ]);
            $senior->sectors()->attach($seniorSector->id);
        }

        $this->actingAs($admin)->get('/admin/sectors')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pwdMembers.total', 16)
                ->where('pwdMembers.per_page', 15)
                ->where('pwdMembers.current_page', 1)
                ->where('pwdMembers.last_page', 2)
                ->where('pwdMembers.from', 1)
                ->where('pwdMembers.to', 15)
                ->has('pwdMembers.data', 15)
                ->where('pwdMembers.data.0.resident_number', 'PD-01')
                ->where('pwdMembers.data.14.resident_number', 'PD-15')
                ->where('seniorMembers.current_page', 1)
                ->where('seniorMembers.last_page', 2)
                ->has('seniorMembers.data', 15));

        $this->actingAs($admin)->get('/admin/sectors?pwd_page=2')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pwdMembers.current_page', 2)
                ->where('pwdMembers.from', 16)
                ->where('pwdMembers.to', 16)
                ->has('pwdMembers.data', 1)
                ->where('pwdMembers.data.0.resident_number', 'PD-16')
                ->where('seniorMembers.current_page', 1)
                ->where('stats.pwdMembers', 16));

        $this->actingAs($admin)->get('/admin/sectors?senior_page=2')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('seniorMembers.current_page', 2)
                ->where('seniorMembers.from', 16)
                ->has('seniorMembers.data', 1)
                ->where('seniorMembers.data.0.resident_number', 'SD-16')
                ->where('pwdMembers.current_page', 1));
    }

    public function test_member_roster_page_links_carry_the_active_filters(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->createSector('Person with Disability (PWD)', 'pwd');

        $second = $this->createPurok($this->barangay, 'Purok Dos');

        for ($i = 1; $i <= 16; $i++) {
            $resident = $this->createResident($this->barangay, $second, [
                'first_name' => 'Filtered',
                'last_name' => sprintf('Page%02d', $i),
                'mobile' => sprintf('0917004%04d', $i),
                'resident_number' => sprintf('FP-%02d', $i),
            ]);
            $this->assignSector($resident, 'pwd');
        }

        $response = $this->actingAs($admin)->get(
            '/admin/sectors?search=Filtered&purok='.$second->id.'&pwd_page=2',
        );

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('pwdMembers.current_page', 2)
            ->where('pwdMembers.last_page', 2)
            ->where('filters.search', 'Filtered')
            ->where('filters.purok', (string) $second->id));

        $previous = (string) $response->inertiaProps('pwdMembers.prev_page_url');

        $this->assertStringContainsString('search=Filtered', $previous);
        $this->assertStringContainsString('purok='.$second->id, $previous);
        $this->assertStringContainsString('pwd_page=1', $previous);

        $firstPage = $this->actingAs($admin)->get(
            '/admin/sectors?search=Filtered&purok='.$second->id,
        );

        $next = (string) $firstPage->inertiaProps('pwdMembers.next_page_url');

        $this->assertStringContainsString('search=Filtered', $next);
        $this->assertStringContainsString('purok='.$second->id, $next);
        $this->assertStringContainsString('pwd_page=2', $next);
    }

    public function test_both_rosters_page_independently_when_both_page_params_are_present(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $pwdSector = $this->createSector('Person with Disability (PWD)', 'pwd');
        $seniorSector = $this->createSector('Senior Citizen', 'senior');

        for ($i = 1; $i <= 16; $i++) {
            $pwd = $this->createResident($this->barangay, $this->purok, [
                'first_name' => 'Both',
                'last_name' => sprintf('Pwd%02d', $i),
                'mobile' => sprintf('0917005%04d', $i),
                'resident_number' => sprintf('BP-%02d', $i),
            ]);
            $pwd->sectors()->attach($pwdSector->id);

            $senior = $this->createResident($this->barangay, $this->purok, [
                'first_name' => 'Both',
                'last_name' => sprintf('Sen%02d', $i),
                'mobile' => sprintf('0917006%04d', $i),
                'resident_number' => sprintf('BS-%02d', $i),
            ]);
            $senior->sectors()->attach($seniorSector->id);
        }

        $this->actingAs($admin)
            ->get('/admin/sectors?pwd_page=2&senior_page=2')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pwdMembers.current_page', 2)
                ->has('pwdMembers.data', 1)
                ->where('pwdMembers.data.0.resident_number', 'BP-16')
                ->where('seniorMembers.current_page', 2)
                ->has('seniorMembers.data', 1)
                ->where('seniorMembers.data.0.resident_number', 'BS-16'));
    }

    public function test_member_search_stays_within_the_authenticated_admins_barangay(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->createSector('Person with Disability (PWD)', 'pwd');
        $this->createSector('Senior Citizen', 'senior');

        $localPwd = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Shared',
            'last_name' => 'Name',
            'mobile' => '09170002071',
            'resident_number' => 'ISO-1',
        ]);
        $this->assignSector($localPwd, 'pwd');

        $localSenior = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Shared',
            'last_name' => 'Name',
            'mobile' => '09170002072',
            'resident_number' => 'ISO-2',
        ]);
        $this->assignSector($localSenior, 'senior');

        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $otherPurok = $this->createPurok($otherBarangay, 'Purok One');

        $foreignPwd = $this->createResident($otherBarangay, $otherPurok, [
            'first_name' => 'Shared',
            'last_name' => 'Name',
            'mobile' => '09170002073',
            'resident_number' => 'ISO-3',
        ]);
        $this->assignSector($foreignPwd, 'pwd');

        $foreignSenior = $this->createResident($otherBarangay, $otherPurok, [
            'first_name' => 'Shared',
            'last_name' => 'Name',
            'mobile' => '09170002074',
            'resident_number' => 'ISO-4',
        ]);
        $this->assignSector($foreignSenior, 'senior');

        $this->actingAs($admin)
            ->get('/admin/sectors?search='.urlencode('Shared Name'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pwdMembers.data', 1)
                ->where('pwdMembers.data.0.resident_number', 'ISO-1')
                ->where('pwdMembers.total', 1)
                ->has('seniorMembers.data', 1)
                ->where('seniorMembers.data.0.resident_number', 'ISO-2')
                ->where('seniorMembers.total', 1));

        $this->actingAs($admin)
            ->get('/admin/sectors?search=ISO-3')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pwdMembers.data', 0)
                ->where('pwdMembers.total', 0));
    }

    public function test_member_search_returns_an_empty_result_without_fallback_rows(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->createSector('Person with Disability (PWD)', 'pwd');
        $this->createSector('Senior Citizen', 'senior');

        $member = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Only',
            'last_name' => 'Member',
            'mobile' => '09170002081',
            'resident_number' => 'EM-1',
        ]);
        $this->assignSector($member, 'pwd');

        $senior = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Only',
            'last_name' => 'Senior',
            'mobile' => '09170002082',
            'resident_number' => 'EM-2',
        ]);
        $this->assignSector($senior, 'senior');

        $this->actingAs($admin)
            ->get('/admin/sectors?search=zzz-no-such-resident')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pwdMembers.data', 0)
                ->where('pwdMembers.total', 0)
                ->has('seniorMembers.data', 0)
                ->where('seniorMembers.total', 0)
                ->where('stats.pwdMembers', 1)
                ->where('stats.seniorMembers', 1));
    }

    public function test_search_made_only_of_like_wildcards_returns_no_rows(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->createSector('Person with Disability (PWD)', 'pwd');

        $member = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Wildcard',
            'last_name' => 'Member',
            'mobile' => '09170002091',
            'resident_number' => 'WC-1',
        ]);
        $this->assignSector($member, 'pwd');

        $this->actingAs($admin)
            ->get('/admin/sectors?search=%25')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pwdMembers.data', 0)
                ->where('pwdMembers.total', 0));

        $this->actingAs($admin)
            ->get('/admin/sectors?search=_')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pwdMembers.data', 0)
                ->where('pwdMembers.total', 0));
    }

    public function test_pwd_members_payload_row_shape_excludes_sensitive_fields(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->createSector('Person with Disability (PWD)', 'pwd');

        $member = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Shape',
            'last_name' => 'Check',
            'mobile' => '09170002101',
            'resident_number' => 'SH-1',
        ]);
        $this->assignSector($member, 'pwd');

        $this->actingAs($admin)->get('/admin/sectors')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pwdMembers.data.0', fn (Collection $row) => [
                    'id',
                    'resident_number',
                    'name',
                    'purok',
                    'status',
                ] === $row->keys()->all())
                ->missing('pwdMembers.data.0.mobile')
                ->missing('pwdMembers.data.0.email')
                ->missing('pwdMembers.data.0.birthdate')
                ->missing('pwdMembers.data.0.address_line'));
    }

    public function test_puroks_dropdown_lists_only_the_admins_barangay(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->createSector('Person with Disability (PWD)', 'pwd');

        $second = $this->createPurok($this->barangay, 'Alpha Purok');

        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $this->createPurok($otherBarangay, 'Zulu Foreign Purok');

        $this->actingAs($admin)->get('/admin/sectors')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('puroks', 2)
                ->where('puroks.0.id', $second->id)
                ->where('puroks.0.name', 'Alpha Purok')
                ->where('puroks.1.id', $this->purok->id)
                ->where('puroks.1.name', 'Purok 1')
                ->where('filters.search', '')
                ->where('filters.purok', ''));
    }

    /**
     * The label of every section tab, in the order the navigation renders it.
     *
     * Extracted from the `<nav>` block only, so page copy that legitimately
     * mentions "residents" is never mistaken for a navigation tab.
     *
     * @return list<string>
     */
    private function sectorTabLabels(): array
    {
        preg_match_all('/label="([^"]+)"/', $this->sectorNavBlock(), $matches);

        return array_values($matches[1]);
    }

    /**
     * Extract the section tab `<nav>` block from the Sectors page.
     */
    private function sectorNavBlock(): string
    {
        $source = $this->sectorPageSource();

        $start = strpos($source, '<nav');
        $end = strpos($source, '</nav>');

        $this->assertNotFalse($start, 'Sector navigation block was not found.');
        $this->assertNotFalse($end, 'Sector navigation block was not closed.');

        return substr($source, $start, $end - $start);
    }

    private function sectorPageSource(): string
    {
        return (string) file_get_contents(
            base_path('resources/js/pages/Admin/Sectors.tsx')
        );
    }

    private function adminLayoutSource(): string
    {
        return (string) file_get_contents(
            base_path('resources/js/layouts/AdminLayout.tsx')
        );
    }

    /**
     * Extract the JSX block rendered for a given tab.
     */
    private function tabBlock(string $tab): string
    {
        $source = $this->sectorPageSource();

        $start = strpos($source, "{activeTab === '{$tab}' && (");

        $this->assertNotFalse($start, "Tab block [{$tab}] was not found.");

        $end = strpos($source, '{activeTab ===', $start + 1);

        if ($end === false) {
            $end = strpos($source, '{showModal', $start + 1);
        }

        $this->assertNotFalse($end, "Tab block [{$tab}] end marker was not found.");

        return substr($source, $start, $end - $start);
    }

    private function nextResidentMobile(): string
    {
        $this->residentMobileSeq++;

        return '0999'.str_pad((string) $this->residentMobileSeq, 7, '0', STR_PAD_LEFT);
    }

    private function createCertificate(Barangay $barangay): Certificate
    {
        return Certificate::create([
            'barangay_id' => $barangay->id,
            'code' => 'BRGY-CLEARANCE-'.$barangay->id,
            'name' => 'Barangay Clearance',
            'fee' => 50,
            'is_active' => true,
        ]);
    }

    private function createCertificateRequest(
        Barangay $barangay,
        Purok $purok,
        Certificate $certificate,
        string $status,
        string $reference,
        ?Resident $resident = null
    ): CertificateRequest {
        $resident ??= $this->createResident($barangay, $purok, [
            'mobile' => $this->nextResidentMobile(),
        ]);

        return CertificateRequest::create([
            'barangay_id' => $barangay->id,
            'resident_id' => $resident->id,
            'certificate_id' => $certificate->id,
            'reference' => $reference,
            'purpose' => 'Employment',
            'fee' => $certificate->fee,
            'status' => $status,
            'submitted_at' => now(),
        ]);
    }
}
