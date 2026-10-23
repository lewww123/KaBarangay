<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Certificate;
use App\Models\CertificateRequest;
use App\Models\Purok;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class AdminCertificateRequestTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Purok $purok;

    private Certificate $certificate;

    /**
     * The shared resident fixture defaults to one mobile number, which is
     * unique per barangay. Tests here create several residents in the same
     * barangay, so assign each a distinct mobile without touching the trait.
     */
    private int $residentMobileSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $this->purok = $this->createPurok($this->barangay, 'Purok 1');

        $this->certificate = Certificate::create([
            'barangay_id' => $this->barangay->id,
            'code' => 'BRGY-CLEARANCE',
            'name' => 'Barangay Clearance',
            'fee' => 50,
            'is_active' => true,
        ]);
    }

    public function test_admin_certificates_page_lists_only_own_barangay_requests(): void
    {
        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $otherPurok = $this->createPurok($otherBarangay, 'Purok 2');
        $otherCertificate = Certificate::create([
            'barangay_id' => $otherBarangay->id,
            'code' => 'BRGY-CLEARANCE',
            'name' => 'Barangay Clearance',
            'fee' => 50,
            'is_active' => true,
        ]);

        $ownRequest = $this->createRequest($this->barangay, $this->purok, $this->certificate, 'OWN-001');
        $this->createRequest($otherBarangay, $otherPurok, $otherCertificate, 'OTHER-001');

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/certificates')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Certificates')
                ->has('requests.data', 1)
                ->where('requests.data.0.id', $ownRequest->id)
                ->where('stats.total', 1));
    }

    public function test_admin_can_advance_status_and_records_timestamps(): void
    {
        $request = $this->createRequest($this->barangay, $this->purok, $this->certificate, 'ADV-001');

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->put('/admin/certificates/'.$request->id.'/status', ['status' => 'Processing'])
            ->assertRedirect();

        $request->refresh();

        $this->assertSame('Processing', $request->status);
        $this->assertNotNull($request->reviewed_at);
        $this->assertNotNull($request->processing_at);
    }

    public function test_rejecting_requires_an_admin_note(): void
    {
        $request = $this->createRequest($this->barangay, $this->purok, $this->certificate, 'REJ-001');

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->put('/admin/certificates/'.$request->id.'/status', ['status' => 'Rejected'])
            ->assertSessionHasErrors('admin_note');

        $this->assertSame('Pending', $request->refresh()->status);
    }

    public function test_invalid_status_is_rejected(): void
    {
        $request = $this->createRequest($this->barangay, $this->purok, $this->certificate, 'INV-001');

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->put('/admin/certificates/'.$request->id.'/status', ['status' => 'Bogus'])
            ->assertSessionHasErrors('status');

        $this->assertSame('Pending', $request->refresh()->status);
    }

    public function test_admin_cannot_update_another_barangays_request(): void
    {
        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $otherPurok = $this->createPurok($otherBarangay, 'Purok 2');
        $otherCertificate = Certificate::create([
            'barangay_id' => $otherBarangay->id,
            'code' => 'BRGY-CLEARANCE',
            'name' => 'Barangay Clearance',
            'fee' => 50,
            'is_active' => true,
        ]);

        $foreignRequest = $this->createRequest($otherBarangay, $otherPurok, $otherCertificate, 'FOREIGN-001');

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->put('/admin/certificates/'.$foreignRequest->id.'/status', ['status' => 'Processing'])
            ->assertStatus(403);

        $this->assertSame('Pending', CertificateRequest::find($foreignRequest->id)->status);
    }

    public function test_index_returns_paginated_requests_at_fifteen_per_page(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        for ($i = 1; $i <= 16; $i++) {
            $this->createRequest(
                $this->barangay,
                $this->purok,
                $this->certificate,
                'REQ-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            );
        }

        $this->actingAs($admin)
            ->get('/admin/certificates')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Certificates')
                ->has('requests.data', 15)
                ->where('requests.per_page', 15)
                ->where('requests.total', 16)
                ->where('requests.current_page', 1)
                ->where('requests.last_page', 2)
                ->where('requests.from', 1)
                ->where('requests.to', 15));
    }

    public function test_second_page_returns_remaining_requests_in_newest_first_order(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        for ($i = 1; $i <= 16; $i++) {
            $this->createRequest(
                $this->barangay,
                $this->purok,
                $this->certificate,
                'REQ-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            );
        }

        $this->actingAs($admin)
            ->get('/admin/certificates?page=2')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('requests.data', 1)
                ->where('requests.current_page', 2)
                ->where('requests.from', 16)
                ->where('requests.to', 16)
                ->where('requests.data.0.reference', 'REQ-001'));
    }

    public function test_requests_are_ordered_newest_first_with_an_id_tie_breaker(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $first = $this->createRequest($this->barangay, $this->purok, $this->certificate, 'AAA-001');
        $this->createRequest($this->barangay, $this->purok, $this->certificate, 'BBB-001');
        $this->createRequest($this->barangay, $this->purok, $this->certificate, 'CCC-001');

        // Make the earliest-created row the oldest so created_at decides the
        // order; the two remaining rows share a timestamp and fall back to the
        // descending id tie-breaker.
        $first->forceFill(['created_at' => now()->subDay()])->save();

        $rows = $this->actingAs($admin)
            ->get('/admin/certificates')
            ->inertiaProps('requests.data');

        $this->assertSame(
            ['CCC-001', 'BBB-001', 'AAA-001'],
            array_column($rows, 'reference'),
        );
    }

    public function test_search_matches_reference_number(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->createRequest($this->barangay, $this->purok, $this->certificate, 'ABC-123');
        $this->createRequest($this->barangay, $this->purok, $this->certificate, 'XYZ-999');

        $rows = $this->actingAs($admin)
            ->get('/admin/certificates?search=ABC-123')
            ->inertiaProps('requests.data');

        $this->assertSame(['ABC-123'], array_column($rows, 'reference'));
    }

    public function test_search_matches_resident_full_name(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->createRequest(
            $this->barangay,
            $this->purok,
            $this->certificate,
            'NAME-001',
            [],
            ['first_name' => 'Maria', 'last_name' => 'Santos'],
        );
        $this->createRequest($this->barangay, $this->purok, $this->certificate, 'NAME-002');

        $byFullName = $this->actingAs($admin)
            ->get('/admin/certificates?search=Maria Santos')
            ->inertiaProps('requests.data');

        $this->assertSame(['NAME-001'], array_column($byFullName, 'reference'));

        $byLastName = $this->actingAs($admin)
            ->get('/admin/certificates?search=Santos')
            ->inertiaProps('requests.data');

        $this->assertSame(['NAME-001'], array_column($byLastName, 'reference'));
    }

    public function test_search_matches_resident_number(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->createRequest(
            $this->barangay,
            $this->purok,
            $this->certificate,
            'NUM-001',
            [],
            ['resident_number' => 'RES-777'],
        );
        $this->createRequest($this->barangay, $this->purok, $this->certificate, 'NUM-002');

        $rows = $this->actingAs($admin)
            ->get('/admin/certificates?search=RES-777')
            ->inertiaProps('requests.data');

        $this->assertSame(['NUM-001'], array_column($rows, 'reference'));
    }

    public function test_search_matches_certificate_name(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $indigency = Certificate::create([
            'barangay_id' => $this->barangay->id,
            'code' => 'BRGY-INDIGENCY',
            'name' => 'Certificate of Indigency',
            'fee' => 0,
            'is_active' => true,
        ]);

        $this->createRequest($this->barangay, $this->purok, $indigency, 'CERT-001');
        $this->createRequest($this->barangay, $this->purok, $this->certificate, 'CERT-002');

        $rows = $this->actingAs($admin)
            ->get('/admin/certificates?search=Indigency')
            ->inertiaProps('requests.data');

        $this->assertSame(['CERT-001'], array_column($rows, 'reference'));
    }

    public function test_search_matches_request_purpose(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->createRequest(
            $this->barangay,
            $this->purok,
            $this->certificate,
            'PUR-001',
            ['purpose' => 'Business Permit'],
        );
        $this->createRequest($this->barangay, $this->purok, $this->certificate, 'PUR-002');

        $rows = $this->actingAs($admin)
            ->get('/admin/certificates?search=Business')
            ->inertiaProps('requests.data');

        $this->assertSame(['PUR-001'], array_column($rows, 'reference'));
    }

    public function test_search_requires_all_tokens_to_match(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->createRequest($this->barangay, $this->purok, $this->certificate, 'REQ-AAA-111');
        $this->createRequest($this->barangay, $this->purok, $this->certificate, 'REQ-BBB-222');

        $matches = $this->actingAs($admin)
            ->get('/admin/certificates?search=AAA Cruz')
            ->inertiaProps('requests.data');

        $this->assertSame(['REQ-AAA-111'], array_column($matches, 'reference'));

        $noMatches = $this->actingAs($admin)
            ->get('/admin/certificates?search=AAA ZZZ')
            ->inertiaProps('requests.data');

        $this->assertSame([], $noMatches);
    }

    public function test_status_filter_is_applied_server_side(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->createRequest($this->barangay, $this->purok, $this->certificate, 'ST-001', ['status' => 'Pending']);
        $this->createRequest($this->barangay, $this->purok, $this->certificate, 'ST-002', ['status' => 'Processing']);
        $this->createRequest($this->barangay, $this->purok, $this->certificate, 'ST-003', ['status' => 'Released']);

        $response = $this->actingAs($admin)->get('/admin/certificates?status=Processing');

        $this->assertSame(
            ['ST-002'],
            array_column($response->inertiaProps('requests.data'), 'reference'),
        );
        $this->assertSame(1, $response->inertiaProps('requests.total'));
        $this->assertSame('Processing', $response->inertiaProps('filters.status'));
    }

    public function test_missing_empty_and_all_status_are_treated_as_no_filter(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->createRequest($this->barangay, $this->purok, $this->certificate, 'NOF-001');
        $this->createRequest($this->barangay, $this->purok, $this->certificate, 'NOF-002');

        foreach (['', '?status=', '?status=ALL', '?status=Bogus'] as $suffix) {
            $response = $this->actingAs($admin)->get('/admin/certificates'.$suffix);

            $this->assertSame(2, $response->inertiaProps('requests.total'));
            $this->assertSame('', $response->inertiaProps('filters.status'));
        }
    }

    public function test_combined_search_and_status_filter(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->createRequest($this->barangay, $this->purok, $this->certificate, 'COM-001', [
            'purpose' => 'Business',
            'status' => 'Processing',
        ]);
        $this->createRequest($this->barangay, $this->purok, $this->certificate, 'COM-002', [
            'purpose' => 'Business',
            'status' => 'Pending',
        ]);
        $this->createRequest($this->barangay, $this->purok, $this->certificate, 'COM-003', [
            'purpose' => 'Employment',
            'status' => 'Processing',
        ]);

        $rows = $this->actingAs($admin)
            ->get('/admin/certificates?search=Business&status=Processing')
            ->inertiaProps('requests.data');

        $this->assertSame(['COM-001'], array_column($rows, 'reference'));
    }

    public function test_global_statistics_are_unaffected_by_search_and_status(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        for ($i = 1; $i <= 3; $i++) {
            $this->createRequest(
                $this->barangay,
                $this->purok,
                $this->certificate,
                'GS-PENDING-'.$i,
                ['status' => 'Pending'],
            );
        }

        for ($i = 1; $i <= 2; $i++) {
            $this->createRequest(
                $this->barangay,
                $this->purok,
                $this->certificate,
                'GS-PROCESSING-'.$i,
                ['status' => 'Processing'],
            );
        }

        $response = $this->actingAs($admin)
            ->get('/admin/certificates?search=DOES-NOT-EXIST&status=Released');

        // Filtered result set is empty...
        $this->assertSame(0, $response->inertiaProps('requests.total'));

        // ...but the global statistics still reflect every barangay request.
        $this->assertSame(5, $response->inertiaProps('stats.total'));
        $this->assertSame(3, $response->inertiaProps('stats.byStatus.Pending'));
        $this->assertSame(2, $response->inertiaProps('stats.byStatus.Processing'));
        $this->assertSame(0, $response->inertiaProps('stats.byStatus.Released'));
    }

    public function test_pagination_links_and_props_preserve_active_filters(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        for ($i = 1; $i <= 16; $i++) {
            $this->createRequest(
                $this->barangay,
                $this->purok,
                $this->certificate,
                'PG-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                ['status' => 'Processing'],
            );
        }

        $response = $this->actingAs($admin)
            ->get('/admin/certificates?search=Employment&status=Processing');

        $this->assertSame('Employment', $response->inertiaProps('filters.search'));
        $this->assertSame('Processing', $response->inertiaProps('filters.status'));

        $nextPageUrl = (string) $response->inertiaProps('requests.next_page_url');

        $this->assertStringContainsString('search=Employment', $nextPageUrl);
        $this->assertStringContainsString('status=Processing', $nextPageUrl);
        $this->assertStringContainsString('page=2', $nextPageUrl);
    }

    public function test_barangay_isolation_applies_to_search_status_and_pagination(): void
    {
        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $otherPurok = $this->createPurok($otherBarangay, 'Purok 2');
        $otherCertificate = Certificate::create([
            'barangay_id' => $otherBarangay->id,
            'code' => 'BRGY-CLEARANCE',
            'name' => 'Barangay Clearance',
            'fee' => 50,
            'is_active' => true,
        ]);

        $ownRequest = $this->createRequest(
            $this->barangay,
            $this->purok,
            $this->certificate,
            'ISO-001',
            ['status' => 'Processing'],
        );

        for ($i = 1; $i <= 20; $i++) {
            $this->createRequest(
                $otherBarangay,
                $otherPurok,
                $otherCertificate,
                'ISO-FOREIGN-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                ['status' => 'Processing'],
            );
        }

        $admin = $this->createStaff($this->barangay, 'admin');

        $response = $this->actingAs($admin)
            ->get('/admin/certificates?search=ISO&status=Processing');

        $this->assertSame([$ownRequest->id], array_column(
            $response->inertiaProps('requests.data'),
            'id',
        ));
        $this->assertSame(1, $response->inertiaProps('requests.total'));
        $this->assertSame(1, $response->inertiaProps('requests.last_page'));
    }

    public function test_search_cannot_match_another_barangays_request(): void
    {
        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $otherPurok = $this->createPurok($otherBarangay, 'Purok 2');
        $otherCertificate = Certificate::create([
            'barangay_id' => $otherBarangay->id,
            'code' => 'BRGY-CLEARANCE',
            'name' => 'Barangay Clearance',
            'fee' => 50,
            'is_active' => true,
        ]);

        $this->createRequest(
            $otherBarangay,
            $otherPurok,
            $otherCertificate,
            'FOREIGN-UNIQUE',
            [],
            ['first_name' => 'Foreignonly', 'last_name' => 'Person'],
        );

        $this->createRequest($this->barangay, $this->purok, $this->certificate, 'OWN-LOCAL');

        $admin = $this->createStaff($this->barangay, 'admin');

        $response = $this->actingAs($admin)
            ->get('/admin/certificates?search=Foreignonly');

        $this->assertSame([], $response->inertiaProps('requests.data'));
        $this->assertSame(0, $response->inertiaProps('requests.total'));
    }

    public function test_empty_search_result_returns_no_fallback_rows(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->createRequest($this->barangay, $this->purok, $this->certificate, 'EMPTY-001');

        $response = $this->actingAs($admin)
            ->get('/admin/certificates?search=NO-SUCH-REQUEST');

        $this->assertSame([], $response->inertiaProps('requests.data'));
        $this->assertSame(0, $response->inertiaProps('requests.total'));
        $this->assertNull($response->inertiaProps('requests.from'));
        $this->assertNull($response->inertiaProps('requests.to'));
        $this->assertSame('NO-SUCH-REQUEST', $response->inertiaProps('filters.search'));
    }

    public function test_search_strips_wildcard_characters(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->createRequest($this->barangay, $this->purok, $this->certificate, 'WILD-001');

        $response = $this->actingAs($admin)->get('/admin/certificates?search=%25_');

        $this->assertSame([], $response->inertiaProps('requests.data'));
        $this->assertSame(0, $response->inertiaProps('requests.total'));
    }

    public function test_row_shape_and_privacy_are_preserved(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->createRequest($this->barangay, $this->purok, $this->certificate, 'SHAPE-001');

        $row = $this->actingAs($admin)
            ->get('/admin/certificates')
            ->inertiaProps('requests.data.0');

        $this->assertSame([
            'id',
            'reference',
            'certificate',
            'resident',
            'purpose',
            'fee',
            'status',
            'admin_note',
            'submitted_at',
        ], array_keys($row));

        $this->assertSame(['name', 'resident_number'], array_keys($row['resident']));
        $this->assertArrayNotHasKey('mobile', $row['resident']);
        $this->assertArrayNotHasKey('email', $row['resident']);
        $this->assertArrayNotHasKey('birthdate', $row['resident']);
    }

    public function test_index_exposes_the_canonical_status_list(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $response = $this->actingAs($admin)->get('/admin/certificates');

        $canonical = [
            'Pending',
            'Needs Action',
            'For Payment',
            'Processing',
            'Ready for Claim',
            'Released',
            'Rejected',
        ];

        $this->assertSame($canonical, $response->inertiaProps('statuses'));
        $this->assertSame($canonical, array_keys($response->inertiaProps('stats.byStatus')));
    }

    private function createRequest(
        Barangay $barangay,
        Purok $purok,
        Certificate $certificate,
        string $reference,
        array $attributes = [],
        array $residentAttributes = [],
    ): CertificateRequest {
        $residentAttributes['mobile'] ??= '0917'.str_pad(
            (string) $this->residentMobileSequence++,
            7,
            '0',
            STR_PAD_LEFT,
        );

        $resident = $this->createResident($barangay, $purok, $residentAttributes);

        return CertificateRequest::create([
            'barangay_id' => $barangay->id,
            'resident_id' => $resident->id,
            'certificate_id' => $certificate->id,
            'reference' => $reference,
            'purpose' => 'Employment',
            'fee' => $certificate->fee,
            'status' => 'Pending',
            'submitted_at' => now(),
            ...$attributes,
        ]);
    }
}
