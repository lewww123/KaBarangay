<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Purok;
use App\Models\RegistrationDocument;
use App\Models\ResidentRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

/**
 * Residents page tab structure, the combined "All Residents" list, and the
 * read-only resident / registration details endpoints.
 */
class AdminResidentTabsTest extends TestCase
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
    | Tab data sources
    |--------------------------------------------------------------------------
    */

    public function test_status_tabs_expose_only_their_own_records(): void
    {
        $pending = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09981000001',
        ]);

        $needsCorrection = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09981000002',
        ]);
        $needsCorrection->update(['status' => 'needs_correction']);

        $rejected = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09981000003',
        ]);
        $rejected->update([
            'status' => 'rejected',
            'review_notes' => 'Identification is unreadable.',
            'reviewed_at' => now(),
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/residents')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Residents')
                ->has('pendingRegistrations.data', 1)
                ->where('pendingRegistrations.data.0.id', $pending->id)
                ->has('needsCorrectionRegistrations.data', 1)
                ->where('needsCorrectionRegistrations.data.0.id', $needsCorrection->id)
                ->has('rejectedRegistrations.data', 1)
                ->where('rejectedRegistrations.data.0.id', $rejected->id)
                ->where('rejectedRegistrations.data.0.status', 'rejected')
                ->where(
                    'rejectedRegistrations.data.0.review_notes',
                    'Identification is unreadable.'
                ));
    }

    public function test_all_residents_combines_residents_and_registration_only_records(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09181000001',
        ]);

        $pending = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09981000011',
        ]);

        $needsCorrection = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09981000012',
        ]);
        $needsCorrection->update(['status' => 'needs_correction']);

        $rejected = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09981000013',
        ]);
        $rejected->update(['status' => 'rejected']);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/residents')
            ->assertOk()
            ->assertInertia(function ($page) use (
                $resident,
                $pending,
                $needsCorrection,
                $rejected
            ) {
                $props = $page->toArray()['props'];
                $rows = collect($props['allResidents']['data']);

                $this->assertSame(4, $props['allResidents']['total']);

                $residentRow = $rows->firstWhere('id', $resident->id);
                $this->assertNotNull($residentRow);
                $this->assertSame('resident', $residentRow['type']);
                $this->assertSame($resident->resident_number, $residentRow['reference']);

                $statuses = $rows
                    ->where('type', 'registration')
                    ->mapWithKeys(fn ($row) => [$row['id'] => $row['status']])
                    ->all();

                $this->assertSame('pending', $statuses[$pending->id]);
                $this->assertSame('needs_correction', $statuses[$needsCorrection->id]);
                $this->assertSame('rejected', $statuses[$rejected->id]);
            });
    }

    public function test_approved_resident_is_not_duplicated_by_its_registration(): void
    {
        // createResident() also creates the source "approved" registration.
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09181000021',
        ]);

        $this->assertDatabaseHas('resident_registrations', [
            'id' => $resident->resident_registration_id,
            'status' => 'approved',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/residents')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('allResidents.total', 1)
                ->where('allResidents.data.0.type', 'resident')
                ->where('allResidents.data.0.id', $resident->id));
    }

    /*
    |--------------------------------------------------------------------------
    | All Residents search, purok filtering and pagination
    |--------------------------------------------------------------------------
    */

    public function test_all_residents_search_spans_residents_and_registrations(): void
    {
        $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Alpha',
            'mobile' => '09181000031',
        ]);

        $this->createPendingRegistration($this->barangay, $this->purok, [
            'first_name' => 'Alpha',
            'mobile' => '09981000031',
        ]);

        $this->createResident($this->barangay, $this->purokTwo, [
            'first_name' => 'Bravo',
            'mobile' => '09181000032',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/residents?resident_search=Alpha')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('allResidents.total', 2)
                ->where('filters.resident_search', 'Alpha'));
    }

    public function test_all_residents_purok_filter_excludes_other_puroks(): void
    {
        $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09181000041',
        ]);
        $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09981000041',
        ]);
        $this->createResident($this->barangay, $this->purokTwo, [
            'mobile' => '09181000042',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/residents?purok='.$this->purok->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('allResidents.total', 2)
                ->where('filters.purok_id', $this->purok->id));
    }

    public function test_all_residents_search_and_pagination_are_scoped_and_preserved(): void
    {
        foreach (range(1, 20) as $i) {
            $this->createResident($this->barangay, $this->purok, [
                'mobile' => '0918200'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'last_name' => 'Gamma '.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            ]);
        }

        $this->createResident($this->barangay, $this->purokTwo, [
            'mobile' => '09183000001',
            'last_name' => 'Gamma 99',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get(
                '/admin/residents?purok='.$this->purok->id
                .'&resident_search=Gamma&all_page=2'
            )
            ->assertOk()
            ->assertInertia(function ($page) {
                $props = $page->toArray()['props'];

                $this->assertSame(20, $props['allResidents']['total']);
                $this->assertSame(2, $props['allResidents']['current_page']);
                $this->assertCount(5, $props['allResidents']['data']);

                $prev = $props['allResidents']['prev_page_url'];
                $this->assertStringContainsString('purok='.$this->purok->id, $prev);
                $this->assertStringContainsString('resident_search=Gamma', $prev);
                $this->assertStringContainsString('all_page=1', $prev);
            });
    }

    public function test_foreign_purok_filter_never_exposes_foreign_all_residents(): void
    {
        $foreignPurok = $this->createPurok($this->other, 'Foreign Purok');
        $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09181000051',
        ]);
        $this->createResident($this->other, $foreignPurok, [
            'mobile' => '09181000052',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/residents?purok='.$foreignPurok->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.purok_id', null)
                ->where('allResidents.total', 1));
    }

    public function test_all_residents_is_barangay_scoped(): void
    {
        $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09181000061',
        ]);

        $otherPurok = $this->createPurok($this->other, 'Foreign Purok');
        $this->createResident($this->other, $otherPurok, [
            'mobile' => '09181000062',
        ]);
        $this->createPendingRegistration($this->other, $otherPurok, [
            'mobile' => '09981000061',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/residents')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('allResidents.total', 1));
    }

    /*
    |--------------------------------------------------------------------------
    | All Residents UNION column compatibility
    |--------------------------------------------------------------------------
    */

    /**
     * The residents branch references a varchar column (`resident_number`)
     * while the registrations branch references a uuid column (`public_id`).
     * PostgreSQL rejects UNION/UNION ALL across those types
     * (SQLSTATE 42804). Both branches must therefore cast `reference` to a
     * character type.
     *
     * NOTE: SQLite is dynamically typed, so this test cannot reproduce the
     * PostgreSQL type error. It guards the fix by asserting that the compiled
     * query actually emits the casts; PostgreSQL runtime verification is
     * performed separately (or explicitly reported as not performed).
     */
    public function test_all_residents_union_casts_both_reference_columns(): void
    {
        $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09181000095',
        ]);
        $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09981000095',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $unionSql = null;

        DB::listen(function ($query) use (&$unionSql) {
            if (str_contains($query->sql, 'all_residents')) {
                $unionSql = $query->sql;
            }
        });

        $this->actingAs($admin)
            ->get('/admin/residents')
            ->assertOk();

        $this->assertNotNull(
            $unionSql,
            'The All Residents UNION query was not executed.'
        );

        $this->assertStringContainsString(
            'CAST(residents.resident_number AS VARCHAR)',
            $unionSql
        );
        $this->assertStringContainsString(
            'CAST(resident_registrations.public_id AS VARCHAR)',
            $unionSql
        );
    }

    public function test_all_residents_union_output_shape_is_compatible(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09181000096',
        ]);
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09981000096',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/residents')
            ->assertOk()
            ->assertInertia(function ($page) use ($resident, $registration) {
                $rows = collect($page->toArray()['props']['allResidents']['data']);

                $this->assertCount(2, $rows);

                foreach ($rows as $row) {
                    // Every branch must transport the reference as text.
                    $this->assertIsString($row['reference']);
                    $this->assertNotSame('', $row['reference']);
                    $this->assertIsInt($row['id']);
                    $this->assertContains($row['type'], ['resident', 'registration']);
                }

                $this->assertSame(
                    $resident->resident_number,
                    $rows->firstWhere('type', 'resident')['reference']
                );

                $this->assertSame(
                    $registration->public_id,
                    $rows->firstWhere('type', 'registration')['reference']
                );
            });
    }

    /*
    |--------------------------------------------------------------------------
    | Review tab search, purok filtering and pagination
    |--------------------------------------------------------------------------
    */

    public function test_review_tabs_paginate_independently(): void
    {
        foreach (range(1, 20) as $i) {
            $this->createPendingRegistration($this->barangay, $this->purok, [
                'mobile' => '0998300'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            ]);
        }

        foreach (range(1, 20) as $i) {
            $rejected = $this->createPendingRegistration($this->barangay, $this->purok, [
                'mobile' => '0998400'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            ]);
            $rejected->update(['status' => 'rejected']);
        }

        foreach (range(1, 3) as $i) {
            $needsCorrection = $this->createPendingRegistration($this->barangay, $this->purok, [
                'mobile' => '0998500'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            ]);
            $needsCorrection->update(['status' => 'needs_correction']);
        }

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/residents?pending_page=2&rejected_page=2')
            ->assertOk()
            ->assertInertia(function ($page) {
                $props = $page->toArray()['props'];

                // Totals come from a COUNT query, not from loading every row.
                $this->assertSame(20, $props['pendingRegistrations']['total']);
                $this->assertSame(2, $props['pendingRegistrations']['current_page']);
                $this->assertCount(5, $props['pendingRegistrations']['data']);

                // The independent rejected page parameter does not leak over.
                $this->assertSame(20, $props['rejectedRegistrations']['total']);
                $this->assertSame(2, $props['rejectedRegistrations']['current_page']);
                $this->assertCount(5, $props['rejectedRegistrations']['data']);

                // An unrelated tab stays on its first page.
                $this->assertSame(3, $props['needsCorrectionRegistrations']['total']);
                $this->assertSame(1, $props['needsCorrectionRegistrations']['current_page']);
                $this->assertCount(3, $props['needsCorrectionRegistrations']['data']);
            });
    }

    public function test_review_tab_search_is_server_side_and_status_scoped(): void
    {
        $alphaPending = $this->createPendingRegistration($this->barangay, $this->purok, [
            'first_name' => 'Alpha',
            'mobile' => '09986000001',
        ]);

        $this->createPendingRegistration($this->barangay, $this->purok, [
            'first_name' => 'Bravo',
            'mobile' => '09986000002',
        ]);

        $alphaRejected = $this->createPendingRegistration($this->barangay, $this->purok, [
            'first_name' => 'Alpha',
            'mobile' => '09986000003',
        ]);
        $alphaRejected->update(['status' => 'rejected']);

        $needsCorrection = $this->createPendingRegistration($this->barangay, $this->purok, [
            'first_name' => 'Charlie',
            'mobile' => '09986000004',
        ]);
        $needsCorrection->update(['status' => 'needs_correction']);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/residents?registration_search=Alpha')
            ->assertOk()
            ->assertInertia(function ($page) use ($alphaPending, $alphaRejected) {
                $props = $page->toArray()['props'];

                $this->assertSame(
                    'Alpha',
                    $props['filters']['registration_search']
                );

                $this->assertSame(1, $props['pendingRegistrations']['total']);
                $this->assertSame(
                    $alphaPending->id,
                    $props['pendingRegistrations']['data'][0]['id']
                );

                $this->assertSame(1, $props['rejectedRegistrations']['total']);
                $this->assertSame(
                    $alphaRejected->id,
                    $props['rejectedRegistrations']['data'][0]['id']
                );

                // A non-matching status still reports an empty, correct list.
                $this->assertSame(
                    0,
                    $props['needsCorrectionRegistrations']['total']
                );
                $this->assertSame(
                    [],
                    $props['needsCorrectionRegistrations']['data']
                );
            });
    }

    public function test_review_tab_search_matches_mobile_reference_and_purok(): void
    {
        $byMobile = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09987770001',
        ]);

        $byReference = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09987770002',
        ]);

        $byPurok = $this->createPendingRegistration($this->barangay, $this->purokTwo, [
            'mobile' => '09987770003',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/residents?registration_search=09987770001')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pendingRegistrations.total', 1)
                ->where('pendingRegistrations.data.0.id', $byMobile->id));

        $this->actingAs($admin)
            ->get('/admin/residents?registration_search='.$byReference->public_id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pendingRegistrations.total', 1)
                ->where('pendingRegistrations.data.0.id', $byReference->id));

        $this->actingAs($admin)
            ->get('/admin/residents?registration_search=Purok%202')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pendingRegistrations.total', 1)
                ->where('pendingRegistrations.data.0.id', $byPurok->id));
    }

    public function test_review_tab_search_with_no_matches_returns_empty_lists(): void
    {
        $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09986660001',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/residents?registration_search=ZzzNoMatch')
            ->assertOk()
            ->assertInertia(function ($page) {
                $props = $page->toArray()['props'];

                $this->assertSame(0, $props['pendingRegistrations']['total']);
                $this->assertSame([], $props['pendingRegistrations']['data']);
                $this->assertSame(
                    0,
                    $props['needsCorrectionRegistrations']['total']
                );
                $this->assertSame(0, $props['rejectedRegistrations']['total']);
            });
    }

    public function test_review_tab_purok_filter_scopes_the_paginated_lists(): void
    {
        $ownPending = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09988880001',
        ]);

        $this->createPendingRegistration($this->barangay, $this->purokTwo, [
            'mobile' => '09988880002',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/residents?purok='.$this->purok->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.purok_id', $this->purok->id)
                ->where('pendingRegistrations.total', 1)
                ->where('pendingRegistrations.data.0.id', $ownPending->id));
    }

    public function test_review_tab_pagination_links_preserve_search_and_purok(): void
    {
        foreach (range(1, 20) as $i) {
            $this->createPendingRegistration($this->barangay, $this->purok, [
                'first_name' => 'Alpha',
                'last_name' => 'Gamma '.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'mobile' => '0998900'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            ]);
        }

        $this->createPendingRegistration($this->barangay, $this->purokTwo, [
            'first_name' => 'Alpha',
            'mobile' => '09989990001',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get(
                '/admin/residents?purok='.$this->purok->id
                .'&registration_search=Alpha&pending_page=2'
            )
            ->assertOk()
            ->assertInertia(function ($page) {
                $props = $page->toArray()['props'];
                $prev = $props['pendingRegistrations']['prev_page_url'];

                $this->assertSame(20, $props['pendingRegistrations']['total']);
                $this->assertSame(2, $props['pendingRegistrations']['current_page']);

                $this->assertStringContainsString('purok='.$this->purok->id, $prev);
                $this->assertStringContainsString('registration_search=Alpha', $prev);
                $this->assertStringContainsString('pending_page=1', $prev);
            });
    }

    public function test_review_tabs_are_barangay_scoped(): void
    {
        $ownPending = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09981110001',
        ]);

        $foreignPurok = $this->createPurok($this->other, 'Foreign Purok');

        foreach (range(1, 20) as $i) {
            $this->createPendingRegistration($this->other, $foreignPurok, [
                'mobile' => '0998222'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            ]);
        }

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/residents')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pendingRegistrations.total', 1)
                ->where('pendingRegistrations.data.0.id', $ownPending->id));
    }

    public function test_rejected_tab_does_not_eager_load_documents(): void
    {
        $pending = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09983330001',
        ]);
        $this->createDocument($pending);

        $rejected = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09983330002',
        ]);
        $rejected->update(['status' => 'rejected']);
        $this->createDocument($rejected);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/residents')
            ->assertOk()
            ->assertInertia(function ($page) {
                $props = $page->toArray()['props'];

                $this->assertCount(
                    1,
                    $props['pendingRegistrations']['data'][0]['documents']
                );

                $this->assertSame(
                    [],
                    $props['rejectedRegistrations']['data'][0]['documents']
                );
            });
    }

    /*
    |--------------------------------------------------------------------------
    | Read-only details endpoints
    |--------------------------------------------------------------------------
    */

    public function test_resident_details_expose_only_intended_fields(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09181000071',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->getJson('/admin/residents/'.$resident->id.'/details')
            ->assertOk()
            ->assertJsonPath('type', 'resident')
            ->assertJsonPath('data.resident_number', $resident->resident_number)
            ->assertJsonPath('data.mobile', $resident->mobile)
            ->assertJsonPath('data.purok', $this->purok->name)
            ->assertJsonPath('data.account_active', true)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.qr_token')
            ->assertJsonMissingPath('data.token');
    }

    public function test_registration_details_include_status_and_review_information(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09981000071',
        ]);
        $registration->update([
            'status' => 'rejected',
            'review_notes' => 'Blurry identification.',
            'reviewed_at' => now(),
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->getJson('/admin/resident-registrations/'.$registration->id.'/details')
            ->assertOk()
            ->assertJsonPath('type', 'registration')
            ->assertJsonPath('data.reference', $registration->public_id)
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.review_notes', 'Blurry identification.')
            ->assertJsonMissingPath('data.password');
    }

    public function test_registration_details_do_not_expose_the_password_hash(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09981000072',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->getJson('/admin/resident-registrations/'.$registration->id.'/details')
            ->assertOk()
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.qr_token');
    }

    public function test_cross_barangay_resident_details_return_404(): void
    {
        $otherPurok = $this->createPurok($this->other, 'Foreign Purok');
        $foreign = $this->createResident($this->other, $otherPurok, [
            'mobile' => '09181000081',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->getJson('/admin/residents/'.$foreign->id.'/details')
            ->assertStatus(404);
    }

    public function test_cross_barangay_registration_details_return_404(): void
    {
        $otherPurok = $this->createPurok($this->other, 'Foreign Purok');
        $foreign = $this->createPendingRegistration($this->other, $otherPurok, [
            'mobile' => '09981000081',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->getJson('/admin/resident-registrations/'.$foreign->id.'/details')
            ->assertStatus(404);
    }

    public function test_details_endpoints_require_admin_role(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09181000091',
        ]);
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09981000091',
        ]);

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->getJson('/admin/residents/'.$resident->id.'/details')
            ->assertStatus(403);

        $this->actingAs($clerk)
            ->getJson('/admin/resident-registrations/'.$registration->id.'/details')
            ->assertStatus(403);
    }

    public function test_details_endpoints_block_guests(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09181000092',
        ]);

        $this->get('/admin/residents/'.$resident->id.'/details')
            ->assertRedirect('/login');
    }

    public function test_missing_details_records_return_404(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->getJson('/admin/residents/999999/details')
            ->assertStatus(404);

        $this->actingAs($admin)
            ->getJson('/admin/resident-registrations/999999/details')
            ->assertStatus(404);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function createDocument(ResidentRegistration $registration): RegistrationDocument
    {
        return RegistrationDocument::create([
            'resident_registration_id' => $registration->id,
            'sector_id' => null,
            'document_type' => 'valid_id',
            'disk' => 'local',
            'path' => 'resident-verifications/'.$registration->public_id.'/valid_id.jpg',
            'original_name' => 'valid_id.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1234,
            'sha256' => str_repeat('a', 64),
            'verification_status' => 'pending',
        ]);
    }
}
