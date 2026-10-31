<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Certificate;
use App\Models\CertificateIssuance;
use App\Models\CertificateRequest;
use App\Models\Purok;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class ClerkCertificateAllRecordsTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Purok $purok;

    private Certificate $certificate;

    private int $residentMobileSequence = 0;

    private int $issuanceSequence = 0;

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

    public function test_all_records_combines_requests_and_issuances(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->createRequest('request', 'REQ-2026-ALL00001');
        $this->createIssuance(['reference' => 'ISSUE-2026-ALL00001']);

        $response = $this->actingAs($clerk)->get('/clerk/certificates');

        $rows = $response->inertiaProps('allRecords.data');

        $this->assertCount(2, $rows);
        $this->assertSame(
            ['issuance', 'request'],
            collect($rows)->pluck('record_type')->sort()->values()->all(),
        );
        $this->assertSame(2, $response->inertiaProps('allRecords.total'));
    }

    public function test_all_records_exposes_only_presentation_fields(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->createRequest('request', 'REQ-2026-FIELDS01');
        $this->createIssuance(['reference' => 'ISSUE-2026-FIELDS01']);

        $rows = $this->actingAs($clerk)->get('/clerk/certificates')->inertiaProps('allRecords.data');

        foreach ($rows as $row) {
            $this->assertSame(
                ['record_type', 'id', 'reference', 'certificate', 'resident', 'purok', 'status', 'occurred_at'],
                array_keys($row),
            );
            $this->assertSame(['name', 'resident_number'], array_keys($row['resident']));
        }
    }

    public function test_all_records_is_barangay_scoped(): void
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

        $this->createRequest('request', 'REQ-2026-OURS0001', [], [], $this->barangay, $this->purok, $this->certificate);
        $this->createRequest('request', 'REQ-2026-THEIRS1', [], [], $otherBarangay, $otherPurok, $otherCertificate);
        $this->createIssuance(['reference' => 'ISSUE-2026-OURS0001']);
        CertificateIssuance::create([
            'barangay_id' => $otherBarangay->id,
            'certificate_request_id' => null,
            'resident_id' => null,
            'reference' => 'ISSUE-2026-THEIRS1',
            'certificate_type' => 'Tricycle',
            'resident_name' => 'Foreign Person',
            'resident_number' => null,
            'purok' => null,
            'fee' => null,
            'issued_by' => null,
            'issued_at' => now(),
        ]);

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $rows = $this->actingAs($clerk)->get('/clerk/certificates')->inertiaProps('allRecords.data');

        $references = collect($rows)->pluck('reference')->sort()->values()->all();

        $this->assertSame(['ISSUE-2026-OURS0001', 'REQ-2026-OURS0001'], $references);
        $this->assertSame(2, $this->actingAs($clerk)->get('/clerk/certificates')->inertiaProps('allRecords.total'));
    }

    public function test_issuance_rows_carry_their_saved_issued_status(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->createRequest('request', 'REQ-2026-STATUS01', ['status' => 'Pending']);
        $this->createIssuance(['reference' => 'ISSUE-2026-STATUS01']);

        $rows = collect($this->actingAs($clerk)->get('/clerk/certificates')->inertiaProps('allRecords.data'))
            ->keyBy('record_type');

        $this->assertSame('Pending', $rows['request']['status']);
        $this->assertSame('issued', $rows['issuance']['status']);
    }

    public function test_all_records_search_spans_both_sources(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->createRequest('request', 'REQ-2026-AAA11111');
        $this->createRequest('request', 'REQ-2026-BBB22222');
        $this->createIssuance(['reference' => 'ISSUE-2026-AAA33333']);
        $this->createIssuance(['reference' => 'ISSUE-2026-CCC44444']);

        $rows = $this->actingAs($clerk)
            ->get('/clerk/certificates?all_search=AAA')
            ->inertiaProps('allRecords.data');

        $references = collect($rows)->pluck('reference')->sort()->values()->all();

        $this->assertSame(['ISSUE-2026-AAA33333', 'REQ-2026-AAA11111'], $references);
    }

    public function test_all_records_search_strips_wildcards(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->createRequest('request', 'REQ-2026-WILD0001');
        $this->createIssuance(['reference' => 'ISSUE-2026-WILD0001']);

        $wildcard = $this->actingAs($clerk)->get('/clerk/certificates?all_search=%25_');

        $this->assertSame(0, $wildcard->inertiaProps('allRecords.total'));
    }

    public function test_all_records_search_cannot_escape_barangay_scope(): void
    {
        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');

        CertificateIssuance::create([
            'barangay_id' => $otherBarangay->id,
            'certificate_request_id' => null,
            'resident_id' => null,
            'reference' => 'ISSUE-2026-FOREIGN1',
            'certificate_type' => 'Tricycle',
            'resident_name' => 'Foreignonly Person',
            'resident_number' => null,
            'purok' => null,
            'fee' => null,
            'issued_by' => null,
            'issued_at' => now(),
        ]);

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)->get('/clerk/certificates?all_search=Foreignonly');

        $this->assertSame(0, $response->inertiaProps('allRecords.total'));
        $this->assertSame([], $response->inertiaProps('allRecords.data'));
    }

    public function test_all_records_template_type_filter_matches_both_sources(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $tricycleRequest = Certificate::create([
            'barangay_id' => $this->barangay->id,
            'code' => 'BRGY-TRICYCLE',
            'name' => 'Tricycle',
            'fee' => 100,
            'is_active' => true,
        ]);

        $this->createRequest('request', 'REQ-2026-TRICY001', [], [], $this->barangay, $this->purok, $tricycleRequest);
        $this->createRequest('request', 'REQ-2026-CLEAR001', [], [], $this->barangay, $this->purok, $this->certificate);
        $this->createIssuance(['reference' => 'ISSUE-2026-TRICY001', 'certificate_type' => 'Tricycle']);
        $this->createIssuance(['reference' => 'ISSUE-2026-INDIG001', 'certificate_type' => 'Indigency']);

        $response = $this->actingAs($clerk)->get('/clerk/certificates?all_type=Tricycle');

        $references = collect($response->inertiaProps('allRecords.data'))->pluck('reference')->sort()->values()->all();

        $this->assertSame(['ISSUE-2026-TRICY001', 'REQ-2026-TRICY001'], $references);
        $this->assertSame('Tricycle', $response->inertiaProps('allFilters.type'));
    }

    public function test_all_records_catalog_id_filter_matches_requests_only(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->createRequest('request', 'REQ-2026-CATALOG1', [], [], $this->barangay, $this->purok, $this->certificate);
        $this->createRequest('request', 'REQ-2026-OTHER001', [], [], $this->barangay, $this->purok, $this->certificate);
        $this->createIssuance(['reference' => 'ISSUE-2026-CATALOG1']);

        $response = $this->actingAs($clerk)
            ->get('/clerk/certificates?all_type='.$this->certificate->id);

        $references = collect($response->inertiaProps('allRecords.data'))->pluck('reference')->all();

        // The catalog id selects requests tied to that catalog entry; issuances
        // store a type label, not a catalog id, so they match nothing.
        $this->assertCount(2, $references);
        $this->assertContains('REQ-2026-CATALOG1', $references);
        $this->assertContains('REQ-2026-OTHER001', $references);
        $this->assertNotContains('ISSUE-2026-CATALOG1', $references);
        $this->assertSame((string) $this->certificate->id, $response->inertiaProps('allFilters.type'));
    }

    public function test_all_records_foreign_catalog_id_matches_nothing(): void
    {
        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $otherCertificate = Certificate::create([
            'barangay_id' => $otherBarangay->id,
            'code' => 'BRGY-OTHER',
            'name' => 'Other Certificate',
            'fee' => 50,
            'is_active' => true,
        ]);

        $this->createRequest('request', 'REQ-2026-OURS0002');
        $this->createIssuance(['reference' => 'ISSUE-2026-OURS0002']);

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)
            ->get('/clerk/certificates?all_type='.$otherCertificate->id);

        $this->assertSame(0, $response->inertiaProps('allRecords.total'));
        $this->assertSame('', $response->inertiaProps('allFilters.type'));
    }

    public function test_all_records_unknown_type_is_ignored(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->createRequest('request', 'REQ-2026-UNKNOWN1');
        $this->createIssuance(['reference' => 'ISSUE-2026-UNKNOWN1']);

        $response = $this->actingAs($clerk)->get('/clerk/certificates?all_type=Bogus');

        $this->assertSame(2, $response->inertiaProps('allRecords.total'));
        $this->assertSame('', $response->inertiaProps('allFilters.type'));
    }

    public function test_all_records_date_range_includes_boundaries_across_sources(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->createRequest('request', 'REQ-2026-DATE0001', ['submitted_at' => '2026-01-01 09:00:00']);
        $this->createRequest('request', 'REQ-2026-DATE0002', ['submitted_at' => '2026-01-15 23:59:59']);
        $this->createRequest('request', 'REQ-2026-DATE0003', ['submitted_at' => '2026-02-01 09:00:00']);
        $this->createIssuance(['reference' => 'ISSUE-2026-DATE0001', 'issued_at' => '2026-01-01 08:00:00']);
        $this->createIssuance(['reference' => 'ISSUE-2026-DATE0002', 'issued_at' => '2026-01-15 00:00:00']);
        $this->createIssuance(['reference' => 'ISSUE-2026-DATE0003', 'issued_at' => '2026-02-01 09:00:00']);

        $response = $this->actingAs($clerk)
            ->get('/clerk/certificates?all_date_from=2026-01-01&all_date_to=2026-01-15');

        $references = collect($response->inertiaProps('allRecords.data'))->pluck('reference')->sort()->values()->all();

        $this->assertSame([
            'ISSUE-2026-DATE0001',
            'ISSUE-2026-DATE0002',
            'REQ-2026-DATE0001',
            'REQ-2026-DATE0002',
        ], $references);
    }

    public function test_all_records_invalid_dates_are_ignored_and_reported_blank(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->createRequest('request', 'REQ-2026-BADDATE1');

        $response = $this->actingAs($clerk)
            ->get('/clerk/certificates?all_date_from=not-a-date&all_date_to=2026-13-40');

        $this->assertSame(1, $response->inertiaProps('allRecords.total'));
        $this->assertSame('', $response->inertiaProps('allFilters.date_from'));
        $this->assertSame('', $response->inertiaProps('allFilters.date_to'));
    }

    public function test_all_records_per_page_allow_list(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        for ($i = 1; $i <= 30; $i++) {
            $this->createIssuance(['reference' => 'ISSUE-2026-PP'.str_pad((string) $i, 6, '0', STR_PAD_LEFT)]);
        }

        $allowed = $this->actingAs($clerk)->get('/clerk/certificates?all_per_page=25');
        $this->assertSame(25, $allowed->inertiaProps('allRecords.per_page'));
        $this->assertSame(25, $allowed->inertiaProps('allFilters.per_page'));

        $rejected = $this->actingAs($clerk)->get('/clerk/certificates?all_per_page=7');
        $this->assertSame(10, $rejected->inertiaProps('allRecords.per_page'));
        $this->assertSame(10, $rejected->inertiaProps('allFilters.per_page'));
    }

    public function test_all_records_pagination_uses_all_page_parameter(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        for ($i = 1; $i <= 12; $i++) {
            $this->createIssuance([
                'reference' => 'ISSUE-2026-PG'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'issued_at' => '2026-01-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).' 09:00:00',
            ]);
        }

        $pageTwo = $this->actingAs($clerk)->get('/clerk/certificates?all_page=2');

        $this->assertSame(2, $pageTwo->inertiaProps('allRecords.current_page'));
        $this->assertCount(2, $pageTwo->inertiaProps('allRecords.data'));

        // all_page is independent of the requests/issuances paginators.
        $this->assertSame(1, $pageTwo->inertiaProps('requests.current_page'));
        $this->assertSame(1, $pageTwo->inertiaProps('issuances.current_page'));
    }

    public function test_all_records_filters_are_independent_of_other_tabs(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->createRequest('request', 'REQ-2026-INDEP001');
        $this->createIssuance(['reference' => 'ISSUE-2026-INDEP001']);

        $response = $this->actingAs($clerk)->get('/clerk/certificates?all_search=INDEP001');

        $this->assertSame(2, $response->inertiaProps('allRecords.total'));
        $this->assertSame('', $response->inertiaProps('filters.search'));
        $this->assertSame('', $response->inertiaProps('issuedFilters.search'));
    }

    public function test_all_records_page_renders_combined_listing_markup(): void
    {
        $source = file_get_contents(base_path('resources/js/pages/Clerk/Certificates.tsx'));

        // The combined listing is rendered from the union prop, not stitched
        // together client-side from the requests and issuances props.
        $this->assertStringContainsString('allRecords', $source);
        $this->assertStringContainsString('allFilters', $source);
        $this->assertStringContainsString('allRecords.data.map', $source);
        $this->assertStringContainsString('all_search', $source);
        $this->assertStringContainsString('all_type', $source);
        $this->assertStringContainsString('all_date_from', $source);
        $this->assertStringContainsString('all_date_to', $source);
        $this->assertStringContainsString('all_per_page', $source);
        $this->assertStringContainsString("only: ['allRecords', 'allFilters']", $source);

        // No stale placeholder claiming issuances are unavailable.
        $this->assertStringNotContainsString('Issuance records are not available yet.', $source);
    }

    public function test_all_records_tab_is_not_a_client_side_merge(): void
    {
        $source = file_get_contents(base_path('resources/js/pages/Clerk/Certificates.tsx'));

        // The All Records table must not concatenate the two other paginators.
        $this->assertStringNotContainsString('[...requests.data, ...issuances.data]', $source);
        $this->assertStringNotContainsString('requests.data.concat(issuances.data)', $source);
    }

    private function createRequest(
        string $type,
        string $reference,
        array $attributes = [],
        array $residentAttributes = [],
        ?Barangay $barangay = null,
        ?Purok $purok = null,
        ?Certificate $certificate = null,
    ): CertificateRequest {
        $barangay ??= $this->barangay;
        $purok ??= $this->purok;
        $certificate ??= $this->certificate;

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

    private function createIssuance(array $attributes = []): CertificateIssuance
    {
        $reference = $attributes['reference']
            ?? 'ISSUE-2026-'.str_pad((string) ++$this->issuanceSequence, 8, '0', STR_PAD_LEFT);

        return CertificateIssuance::create([
            'barangay_id' => $this->barangay->id,
            'certificate_request_id' => null,
            'resident_id' => null,
            'reference' => $reference,
            'certificate_type' => 'Tricycle',
            'resident_name' => 'Juan Dela Cruz',
            'resident_number' => null,
            'purok' => null,
            'fee' => null,
            'issued_by' => null,
            'issued_at' => now(),
            ...$attributes,
        ]);
    }
}
