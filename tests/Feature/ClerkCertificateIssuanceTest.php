<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Certificate;
use App\Models\CertificateIssuance;
use App\Models\CertificateRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class ClerkCertificateIssuanceTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private int $referenceSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
    }

    public function test_clerk_sees_only_own_barangay_issuances(): void
    {
        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');

        $own = $this->createIssuance($this->barangay, ['reference' => 'ISSUE-2026-OWN00001']);
        $this->createIssuance($otherBarangay, ['reference' => 'ISSUE-2026-OTH00001']);

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)->get('/clerk/certificates');

        $this->assertSame(1, $response->inertiaProps('issuances.total'));
        $this->assertSame('ISSUE-2026-OWN00001', $response->inertiaProps('issuances.data.0.reference'));
        $this->assertSame([$own->id], array_column($response->inertiaProps('issuances.data'), 'id'));
    }

    public function test_foreign_barangay_issuances_never_appear(): void
    {
        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');

        for ($i = 1; $i <= 5; $i++) {
            $this->createIssuance($otherBarangay, [
                'reference' => 'ISSUE-2026-FOR'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
            ]);
        }

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)->get('/clerk/certificates');

        $this->assertSame(0, $response->inertiaProps('issuances.total'));
        $this->assertSame([], $response->inertiaProps('issuances.data'));
    }

    public function test_unauthenticated_access_redirects_to_login(): void
    {
        $this->get('/clerk/certificates?tab=issued')->assertRedirect(route('login'));
    }

    public function test_unauthorized_roles_receive_403(): void
    {
        $senior = $this->createStaff($this->barangay, 'senior');

        $this->actingAs($senior)
            ->get('/clerk/certificates')
            ->assertStatus(403);
    }

    public function test_search_matches_issuance_reference(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->createIssuance($this->barangay, ['reference' => 'ISSUE-2026-AAAA1111']);
        $this->createIssuance($this->barangay, ['reference' => 'ISSUE-2026-BBBB2222']);

        $rows = $this->actingAs($clerk)
            ->get('/clerk/certificates?issued_search=AAAA1111')
            ->inertiaProps('issuances.data');

        $this->assertSame(['ISSUE-2026-AAAA1111'], array_column($rows, 'reference'));
    }

    public function test_search_matches_resident_name_and_number(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->createIssuance($this->barangay, [
            'reference' => 'ISSUE-2026-NAME0001',
            'resident_name' => 'Maria Santos',
            'resident_number' => 'RES-777',
        ]);
        $this->createIssuance($this->barangay, [
            'reference' => 'ISSUE-2026-NAME0002',
            'resident_name' => 'Pedro Reyes',
            'resident_number' => 'RES-888',
        ]);

        $byName = $this->actingAs($clerk)
            ->get('/clerk/certificates?issued_search=Maria Santos')
            ->inertiaProps('issuances.data');

        $this->assertSame(['ISSUE-2026-NAME0001'], array_column($byName, 'reference'));

        $byNumber = $this->actingAs($clerk)
            ->get('/clerk/certificates?issued_search=RES-888')
            ->inertiaProps('issuances.data');

        $this->assertSame(['ISSUE-2026-NAME0002'], array_column($byNumber, 'reference'));
    }

    public function test_search_strips_wildcards_and_empty_search_cannot_broaden(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->createIssuance($this->barangay, ['reference' => 'ISSUE-2026-WILD0001']);

        $wildcard = $this->actingAs($clerk)->get('/clerk/certificates?issued_search=%25_');
        $this->assertSame(0, $wildcard->inertiaProps('issuances.total'));

        $empty = $this->actingAs($clerk)->get('/clerk/certificates?issued_search=');
        $this->assertSame(1, $empty->inertiaProps('issuances.total'));
    }

    public function test_search_cannot_escape_barangay_scope(): void
    {
        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');

        $this->createIssuance($otherBarangay, [
            'reference' => 'ISSUE-2026-FOREIGN1',
            'resident_name' => 'Foreignonly Person',
        ]);
        $this->createIssuance($this->barangay, ['reference' => 'ISSUE-2026-OWNLOCAL']);

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)->get('/clerk/certificates?issued_search=Foreignonly');

        $this->assertSame([], $response->inertiaProps('issuances.data'));
        $this->assertSame(0, $response->inertiaProps('issuances.total'));
    }

    public function test_certificate_type_filter_is_applied_and_validated(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->createIssuance($this->barangay, [
            'reference' => 'ISSUE-2026-TYPE0001',
            'certificate_type' => 'Tricycle',
        ]);
        $this->createIssuance($this->barangay, [
            'reference' => 'ISSUE-2026-TYPE0002',
            'certificate_type' => 'Indigency',
        ]);

        $response = $this->actingAs($clerk)->get('/clerk/certificates?issued_type=Tricycle');
        $this->assertSame(['ISSUE-2026-TYPE0001'], array_column($response->inertiaProps('issuances.data'), 'reference'));
        $this->assertSame('Tricycle', $response->inertiaProps('issuedFilters.type'));

        // Unknown types are ignored, not passed through.
        $bogus = $this->actingAs($clerk)->get('/clerk/certificates?issued_type=Bogus');
        $this->assertSame(2, $bogus->inertiaProps('issuances.total'));
        $this->assertSame('', $bogus->inertiaProps('issuedFilters.type'));
    }

    public function test_date_range_filter_includes_boundaries(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->createIssuance($this->barangay, [
            'reference' => 'ISSUE-2026-DATE0001',
            'issued_at' => '2026-01-01 09:00:00',
        ]);
        $this->createIssuance($this->barangay, [
            'reference' => 'ISSUE-2026-DATE0002',
            'issued_at' => '2026-01-15 12:00:00',
        ]);
        $this->createIssuance($this->barangay, [
            'reference' => 'ISSUE-2026-DATE0003',
            'issued_at' => '2026-02-01 09:00:00',
        ]);

        $response = $this->actingAs($clerk)
            ->get('/clerk/certificates?issued_date_from=2026-01-01&issued_date_to=2026-01-15');

        $this->assertSame(
            ['ISSUE-2026-DATE0002', 'ISSUE-2026-DATE0001'],
            array_column($response->inertiaProps('issuances.data'), 'reference'),
        );

        $malformed = $this->actingAs($clerk)
            ->get('/clerk/certificates?issued_date_from=not-a-date');

        $this->assertSame(3, $malformed->inertiaProps('issuances.total'));
        $this->assertSame('', $malformed->inertiaProps('issuedFilters.date_from'));
    }

    public function test_allowed_page_sizes_are_honored_and_invalid_normalized(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        for ($i = 1; $i <= 12; $i++) {
            $this->createIssuance($this->barangay, [
                'reference' => 'ISSUE-2026-PP'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'issued_at' => now()->subMinutes(20 - $i),
            ]);
        }

        foreach ([10, 25, 50] as $size) {
            $response = $this->actingAs($clerk)->get('/clerk/certificates?issued_per_page='.$size);
            $this->assertSame($size, $response->inertiaProps('issuances.per_page'));
        }

        $invalid = $this->actingAs($clerk)->get('/clerk/certificates?issued_per_page=9999');
        $this->assertSame(10, $invalid->inertiaProps('issuances.per_page'));
    }

    public function test_pagination_is_newest_first_with_deterministic_order(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        for ($i = 1; $i <= 12; $i++) {
            $this->createIssuance($this->barangay, [
                'reference' => 'ISSUE-2026-PG'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'issued_at' => now()->subMinutes(20 - $i),
            ]);
        }

        $response = $this->actingAs($clerk)
            ->get('/clerk/certificates?issued_per_page=10');

        $this->assertSame(10, $response->inertiaProps('issuances.per_page'));
        $this->assertSame(12, $response->inertiaProps('issuances.total'));
        $this->assertSame(2, $response->inertiaProps('issuances.last_page'));
        $this->assertSame('ISSUE-2026-PG000012', $response->inertiaProps('issuances.data.0.reference'));

        // The paginator uses its own page parameter so the request list's page
        // never bleeds into issuance pagination.
        $nextPageUrl = (string) $response->inertiaProps('issuances.next_page_url');
        $this->assertStringContainsString('issued_page=2', $nextPageUrl);
    }

    public function test_ties_on_issued_at_fall_back_to_id_descending(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $timestamp = '2026-03-01 10:00:00';
        $first = $this->createIssuance($this->barangay, ['reference' => 'ISSUE-2026-TIE00001', 'issued_at' => $timestamp]);
        $second = $this->createIssuance($this->barangay, ['reference' => 'ISSUE-2026-TIE00002', 'issued_at' => $timestamp]);

        $rows = $this->actingAs($clerk)
            ->get('/clerk/certificates')
            ->inertiaProps('issuances.data');

        $this->assertSame([$second->id, $first->id], array_column($rows, 'id'));
    }

    public function test_row_shape_exposes_only_clerk_needed_fields(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->createIssuance($this->barangay, ['reference' => 'ISSUE-2026-SHAPE001']);

        $row = $this->actingAs($clerk)
            ->get('/clerk/certificates')
            ->inertiaProps('issuances.data.0');

        $this->assertSame([
            'id',
            'reference',
            'certificate_type',
            'resident',
            'purok',
            'status',
            'issued_at',
            'issued_by',
        ], array_keys($row));

        $this->assertSame(['name', 'resident_number'], array_keys($row['resident']));

        // No request linkage, fee, or raw identifiers leak into the response.
        $this->assertArrayNotHasKey('certificate_request_id', $row);
        $this->assertArrayNotHasKey('resident_id', $row);
        $this->assertArrayNotHasKey('fee', $row);
        $this->assertArrayNotHasKey('issued_by_id', $row);
    }

    public function test_resident_number_snapshot_is_optional(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $resident = $this->createResident($this->barangay, $this->createPurok($this->barangay, 'Purok 1'), [
            'resident_number' => 'RES-SNAP-9',
        ]);

        $this->createIssuance($this->barangay, [
            'reference' => 'ISSUE-2026-SNAP0001',
            'resident_number' => 'RES-SNAP-9',
        ]);
        $this->createIssuance($this->barangay, [
            'reference' => 'ISSUE-2026-SNAP0002',
            'resident_number' => null,
            'resident_name' => 'Manual Only',
        ]);

        $this->assertNotNull($resident->resident_number);

        $rows = collect($this->actingAs($clerk)
            ->get('/clerk/certificates')
            ->inertiaProps('issuances.data'))
            ->keyBy('reference');

        $this->assertSame('RES-SNAP-9', $rows['ISSUE-2026-SNAP0001']['resident']['resident_number']);
        $this->assertNull($rows['ISSUE-2026-SNAP0002']['resident']['resident_number']);
    }

    public function test_issuer_display_name_is_shown_without_account_fields(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk', ['name' => 'Clerk Issuer']);

        $this->createIssuance($this->barangay, [
            'reference' => 'ISSUE-2026-ISSUER01',
            'issued_by' => $clerk->id,
        ]);

        $row = $this->actingAs($clerk)
            ->get('/clerk/certificates')
            ->inertiaProps('issuances.data.0');

        $this->assertSame('Clerk Issuer', $row['issued_by']);
    }

    public function test_requests_tab_and_filters_remain_intact(): void
    {
        $certificate = Certificate::create([
            'barangay_id' => $this->barangay->id,
            'code' => 'BRGY-CLEARANCE',
            'name' => 'Barangay Clearance',
            'fee' => 50,
            'is_active' => true,
        ]);

        $purok = $this->createPurok($this->barangay, 'Purok 1');
        $resident = $this->createResident($this->barangay, $purok, ['mobile' => '09170000042']);

        CertificateRequest::create([
            'barangay_id' => $this->barangay->id,
            'resident_id' => $resident->id,
            'certificate_id' => $certificate->id,
            'reference' => 'CERT-2026-REQ00001',
            'purpose' => 'Employment',
            'fee' => 50,
            'status' => 'Pending',
            'submitted_at' => now(),
        ]);

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)->get('/clerk/certificates?search=REQ00001');

        $this->assertSame(1, $response->inertiaProps('requests.total'));
        $this->assertSame('CERT-2026-REQ00001', $response->inertiaProps('requests.data.0.reference'));
        $this->assertSame('REQ00001', $response->inertiaProps('filters.search'));
    }

    public function test_issuance_and_request_filters_are_independent(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->createIssuance($this->barangay, ['reference' => 'ISSUE-2026-INDEP001']);

        // An issuance search term must never influence the request listing.
        $response = $this->actingAs($clerk)
            ->get('/clerk/certificates?issued_search=INDEP001');

        $this->assertSame(1, $response->inertiaProps('issuances.total'));
        $this->assertSame('', $response->inertiaProps('filters.search'));
    }

    private function createIssuance(Barangay $barangay, array $attributes = []): CertificateIssuance
    {
        $reference = $attributes['reference']
            ?? 'ISSUE-2026-'.str_pad((string) ++$this->referenceSequence, 8, '0', STR_PAD_LEFT);

        return CertificateIssuance::create([
            'barangay_id' => $barangay->id,
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
