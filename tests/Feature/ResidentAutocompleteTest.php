<?php

namespace Tests\Feature;

use App\Models\Resident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class ResidentAutocompleteTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    public function test_only_admin_and_clerk_can_search(): void
    {
        $barangay = $this->createBarangay('One', 'ONE');
        foreach (['admin', 'clerk', 'lupon', 'senior', 'pwd'] as $role) {
            $staff = $this->createStaff($barangay, $role);
            $response = $this->actingAs($staff)->getJson('/admin/residents/search?q=Ju');
            $role === 'admin' || $role === 'clerk' ? $response->assertOk() : $response->assertForbidden();
        }
    }

    public function test_guests_and_inactive_staff_cannot_search(): void
    {
        $this->getJson('/admin/residents/search?q=Ju')->assertUnauthorized();
        $barangay = $this->createBarangay('One', 'ONE');
        $staff = $this->createStaff($barangay, 'clerk', ['status' => 'inactive']);
        $this->actingAs($staff)->getJson('/admin/residents/search?q=Ju')->assertForbidden();
    }

    public function test_short_terms_do_not_query_the_resident_masterlist(): void
    {
        $barangay = $this->createBarangay('One', 'ONE');
        $staff = $this->createStaff($barangay, 'clerk');
        $lookups = [];
        DB::listen(function ($query) use (&$lookups) {
            if (str_contains($query->sql, '"residents" as "r"')) {
                $lookups[] = $query->sql;
            }
        });
        foreach (['', ' ', 'J', 'é'] as $term) {
            $this->actingAs($staff)->getJson('/admin/residents/search?q='.urlencode($term))
                ->assertOk()->assertExactJson(['residents' => []]);
        }
        $this->assertSame([], $lookups);
        $this->actingAs($staff)->getJson('/admin/residents/search?q[]=bad')->assertUnprocessable();
        $this->getJson('/admin/residents/search?q='.str_repeat('a', 81))->assertUnprocessable();
    }

    public function test_search_is_scoped_capped_and_has_complete_autofill_fields(): void
    {
        $barangay = $this->createBarangay('One', 'ONE');
        $purok = $this->createPurok($barangay, 'Purok 1');
        $staff = $this->createStaff($barangay, 'clerk');
        for ($i = 0; $i < 7; $i++) {
            $resident = $this->createResident($barangay, $purok, ['first_name' => 'Juan', 'last_name' => 'Santos'.$i, 'mobile' => '0917000001'.$i]);
            if ($i === 0) {
                $resident->update(['middle_name' => 'Reyes', 'suffix' => 'Jr.']);
            }
        }
        $inactive = $this->createResident($barangay, $purok, ['first_name' => 'Juan', 'last_name' => 'Inactive', 'mobile' => '09170000028']);
        $inactive->update(['status' => 'inactive']);
        $other = $this->createBarangay('Two', 'TWO');
        $this->createResident($other, $this->createPurok($other), ['first_name' => 'Juan', 'mobile' => '09170000029']);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, '"residents" as "r"')) {
                $queries[] = $query;
            }
        });
        $response = $this->actingAs($staff)->getJson('/admin/residents/search?q=Ju')
            ->assertOk()->assertJsonCount(5, 'residents')
            ->assertJsonPath('residents.0.full_name', 'Juan Reyes Santos0 Jr.')
            ->assertJsonPath('residents.0.purok', 'Purok 1')
            ->assertJsonPath('residents.0.birthdate', '1990-01-01')
            ->assertJsonPath('residents.0.civil_status', 'Single')
            ->assertJsonPath('residents.0.address_details', '123 Test Street');
        $this->assertLessThan(2048, strlen($response->getContent()));
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('limit 5', $queries[0]->sql);
        $this->assertArrayNotHasKey('mobile', $response->json('residents.0'));
        $this->assertArrayNotHasKey('email', $response->json('residents.0'));
        $ids = $response->json('residents.*.id');
        $this->assertSame(count($ids), count(array_unique($ids)));
        $this->assertSame(5, Resident::whereIn('id', $ids)->where('barangay_id', $barangay->id)->where('status', 'active')->count());
    }

    public function test_last_name_and_first_last_prefixes_are_supported_without_wildcard_expansion(): void
    {
        $barangay = $this->createBarangay('One', 'ONE');
        $staff = $this->createStaff($barangay, 'admin');
        $resident = $this->createResident($barangay, $this->createPurok($barangay), ['first_name' => 'Maria Clara', 'last_name' => 'Santos', 'mobile' => '09170000031']);
        foreach (['San', 'Maria San', '  Maria   San  '] as $term) {
            $this->actingAs($staff)->getJson('/admin/residents/search?q='.urlencode($term))
                ->assertOk()->assertJsonCount(1, 'residents')->assertJsonPath('residents.0.id', $resident->id);
        }
        $this->getJson('/admin/residents/search?q=%25_')->assertOk()->assertExactJson(['residents' => []]);
    }

    public function test_multibyte_records_cannot_exceed_the_response_budget(): void
    {
        $barangay = $this->createBarangay('One', 'ONE');
        $staff = $this->createStaff($barangay, 'clerk');
        $purok = $this->createPurok($barangay);
        for ($i = 0; $i < 5; $i++) {
            $resident = $this->createResident($barangay, $purok, ['first_name' => 'Budget', 'last_name' => 'Match'.$i, 'mobile' => '0917000004'.$i]);
            $resident->update(['address_line' => str_repeat('界', 200)]);
        }
        $response = $this->actingAs($staff)->getJson('/admin/residents/search?q=Bu')->assertOk();
        $this->assertLessThan(2048, strlen($response->getContent()));
        $this->assertGreaterThan(0, count($response->json('residents')));
        $this->assertLessThan(5, count($response->json('residents')));
        $this->assertSame(str_repeat('界', 200), $response->json('residents.0.address_details'));
    }
}
