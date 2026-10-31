<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Purok;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

/**
 * Purok tabs and server-side purok filtering for the Admin Residents page.
 *
 * Note on "records without a Purok": both `residents.purok_id` and
 * `resident_registrations.purok_id` are NOT NULL foreign keys, so a record with
 * no purok cannot exist in the current schema. The filter applies a strict
 * `where purok_id = <selected>` match, so such records could never leak into an
 * individual purok tab even if the schema ever allowed them.
 */
class AdminResidentPurokTabsTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Barangay $other;

    private Purok $purok;

    private Purok $purokTwo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $this->other = $this->createBarangay('Barangay Two', 'BRGY-TWO');

        $this->purok = $this->createPurok($this->barangay, 'Purok 1');
        $this->purokTwo = $this->createPurok($this->barangay, 'Purok 2');
    }

    /*
    |--------------------------------------------------------------------------
    | Purok tabs (barangay scoping)
    |--------------------------------------------------------------------------
    */

    public function test_index_lists_only_own_barangay_puroks(): void
    {
        $this->createPurok($this->other, 'Foreign Purok');

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/residents')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Residents')
                ->has('puroks', 2)
                ->where('puroks.0.name', 'Purok 1')
                ->where('puroks.1.name', 'Purok 2'));
    }

    public function test_index_handles_barangay_with_no_puroks(): void
    {
        $emptyBarangay = $this->createBarangay('Barangay Three', 'BRGY-THREE');
        $admin = $this->createStaff($emptyBarangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/residents')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('puroks', 1)
                ->where('allResidents.total', 1)
                ->where('filters.purok_id', null));
    }

    public function test_all_puroks_returns_only_own_barangay_records(): void
    {
        $ownResident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170000001',
        ]);
        $ownPending = $this->createPendingRegistration($this->barangay, $this->purokTwo, [
            'mobile' => '09980000001',
        ]);

        $otherPurok = $this->createPurok($this->other, 'Foreign Purok');
        $this->createResident($this->other, $otherPurok, [
            'mobile' => '09170000002',
        ]);
        $this->createPendingRegistration($this->other, $otherPurok, [
            'mobile' => '09980000002',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/residents')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pendingRegistrations.data', 1)
                ->where('pendingRegistrations.data.0.id', $ownPending->id)
                ->has('allResidents.data', 2)
                ->where('allResidents.data.0.id', $ownResident->id)
                ->where('filters.purok_id', null));
    }

    /*
    |--------------------------------------------------------------------------
    | Purok filtering (both lists)
    |--------------------------------------------------------------------------
    */

    public function test_selected_purok_filters_pending_and_approved_lists(): void
    {
        $purokOnePending = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000011',
        ]);
        $this->createPendingRegistration($this->barangay, $this->purokTwo, [
            'mobile' => '09980000012',
        ]);

        $purokOneResident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170000011',
        ]);
        $this->createResident($this->barangay, $this->purokTwo, [
            'mobile' => '09170000012',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/residents?purok='.$this->purok->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pendingRegistrations.data', 1)
                ->where('pendingRegistrations.data.0.id', $purokOnePending->id)
                ->where('allResidents.total', 2)
                ->where('allResidents.data.0.id', $purokOneResident->id)
                ->where('filters.purok_id', $this->purok->id));
    }

    public function test_purok_filter_excludes_other_puroks(): void
    {
        $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170000021',
        ]);
        $second = $this->createResident($this->barangay, $this->purokTwo, [
            'mobile' => '09170000022',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/residents?purok='.$this->purokTwo->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('allResidents.total', 1)
                ->where('allResidents.data.0.id', $second->id));
    }

    /*
    |--------------------------------------------------------------------------
    | Search + pagination scoped to the selected purok
    |--------------------------------------------------------------------------
    */

    public function test_search_and_pagination_are_scoped_to_selected_purok(): void
    {
        foreach (range(1, 18) as $i) {
            $this->createResident($this->barangay, $this->purok, [
                'mobile' => '0917100'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'last_name' => 'Alpha '.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            ]);
        }

        foreach (range(1, 3) as $i) {
            $this->createResident($this->barangay, $this->purokTwo, [
                'mobile' => '0917200'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'last_name' => 'Alpha '.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            ]);
        }

        $admin = $this->createStaff($this->barangay, 'admin');

        // Without a purok selection the search spans the whole barangay.
        $this->actingAs($admin)
            ->get('/admin/residents?resident_search=Alpha')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('allResidents.total', 21));

        // Scoped to Purok 1 only.
        $this->actingAs($admin)
            ->get('/admin/residents?purok='.$this->purok->id.'&resident_search=Alpha')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('allResidents.total', 18)
                ->where('allResidents.current_page', 1)
                ->has('allResidents.data', 15)
                ->where('filters.purok_id', $this->purok->id));

        $this->actingAs($admin)
            ->get('/admin/residents?purok='.$this->purok->id.'&resident_search=Alpha&all_page=2')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('allResidents.current_page', 2)
                ->has('allResidents.data', 3));
    }

    public function test_pagination_links_preserve_purok_and_search(): void
    {
        foreach (range(1, 20) as $i) {
            $this->createResident($this->barangay, $this->purok, [
                'mobile' => '0917300'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'last_name' => 'Beta '.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            ]);
        }

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/residents?purok='.$this->purok->id.'&resident_search=Beta')
            ->assertOk()
            ->assertInertia(function ($page) {
                $props = $page->toArray()['props'];
                $next = $props['allResidents']['next_page_url'];

                $this->assertNotNull($next);
                $this->assertStringContainsString(
                    'purok='.$this->purok->id,
                    $next
                );
                $this->assertStringContainsString(
                    'resident_search=Beta',
                    $next
                );
            });
    }

    /*
    |--------------------------------------------------------------------------
    | Invalid / foreign purok identifiers
    |--------------------------------------------------------------------------
    */

    public function test_foreign_purok_id_cannot_access_foreign_records(): void
    {
        $foreignPurok = $this->createPurok($this->other, 'Foreign Purok');

        $ownResident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170000031',
        ]);
        $this->createResident($this->other, $foreignPurok, [
            'mobile' => '09170000032',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/residents?purok='.$foreignPurok->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.purok_id', null)
                ->where('allResidents.total', 2)
                ->where('allResidents.data.0.id', $ownResident->id));
    }

    public function test_invalid_purok_identifiers_are_ignored_safely(): void
    {
        $ownResident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170000041',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/residents?purok=99999999')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.purok_id', null)
                ->where('allResidents.total', 2)
                ->where('allResidents.data.0.id', $ownResident->id));

        $this->actingAs($admin)
            ->get('/admin/residents?purok=not-a-number')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.purok_id', null));
    }
}
