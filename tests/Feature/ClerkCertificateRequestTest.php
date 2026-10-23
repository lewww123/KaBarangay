<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Certificate;
use App\Models\CertificateRequest;
use App\Models\Purok;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class ClerkCertificateRequestTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Purok $purok;

    private Certificate $certificate;

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

    public function test_clerk_certificates_page_lists_only_own_barangay_requests(): void
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

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->get('/clerk/certificates')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Clerk/Certificates')
                ->has('requests.data', 1)
                ->where('requests.data.0.id', $ownRequest->id)
                ->where('requests.total', 1));
    }

    public function test_admin_may_also_view_the_clerk_certificate_page(): void
    {
        $this->createRequest($this->barangay, $this->purok, $this->certificate, 'ADM-001');

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/clerk/certificates')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Clerk/Certificates')->has('requests.data', 1));
    }

    public function test_foreign_barangay_requests_never_appear(): void
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

        for ($i = 1; $i <= 5; $i++) {
            $this->createRequest(
                $otherBarangay,
                $otherPurok,
                $otherCertificate,
                'FOREIGN-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            );
        }

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)->get('/clerk/certificates');

        $this->assertSame(0, $response->inertiaProps('requests.total'));
        $this->assertSame([], $response->inertiaProps('requests.data'));
    }

    public function test_unauthenticated_access_redirects_to_login(): void
    {
        $this->get('/clerk/certificates')->assertRedirect('/login');
    }

    public function test_unauthorized_roles_receive_403(): void
    {
        $senior = $this->createStaff($this->barangay, 'senior');

        $this->actingAs($senior)
            ->get('/clerk/certificates')
            ->assertStatus(403);
    }

    public function test_search_matches_reference_number(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->createRequest($this->barangay, $this->purok, $this->certificate, 'ABC-123');
        $this->createRequest($this->barangay, $this->purok, $this->certificate, 'XYZ-999');

        $rows = $this->actingAs($clerk)
            ->get('/clerk/certificates?search=ABC-123')
            ->inertiaProps('requests.data');

        $this->assertSame(['ABC-123'], array_column($rows, 'reference'));
    }

    public function test_search_matches_resident_name_and_number(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->createRequest(
            $this->barangay,
            $this->purok,
            $this->certificate,
            'NAME-001',
            [],
            ['first_name' => 'Maria', 'last_name' => 'Santos', 'resident_number' => 'RES-777'],
        );
        $this->createRequest($this->barangay, $this->purok, $this->certificate, 'NAME-002');

        $byName = $this->actingAs($clerk)
            ->get('/clerk/certificates?search=Maria Santos')
            ->inertiaProps('requests.data');

        $this->assertSame(['NAME-001'], array_column($byName, 'reference'));

        $byNumber = $this->actingAs($clerk)
            ->get('/clerk/certificates?search=RES-777')
            ->inertiaProps('requests.data');

        $this->assertSame(['NAME-001'], array_column($byNumber, 'reference'));
    }

    public function test_search_strips_wildcards(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->createRequest($this->barangay, $this->purok, $this->certificate, 'WILD-001');

        $response = $this->actingAs($clerk)->get('/clerk/certificates?search=%25_');

        $this->assertSame(0, $response->inertiaProps('requests.total'));
    }

    public function test_search_cannot_escape_barangay_scope(): void
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

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)->get('/clerk/certificates?search=Foreignonly');

        $this->assertSame([], $response->inertiaProps('requests.data'));
        $this->assertSame(0, $response->inertiaProps('requests.total'));
    }

    public function test_status_filter_is_applied_and_validated(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->createRequest($this->barangay, $this->purok, $this->certificate, 'ST-001', ['status' => 'Pending']);
        $this->createRequest($this->barangay, $this->purok, $this->certificate, 'ST-002', ['status' => 'Processing']);

        $response = $this->actingAs($clerk)->get('/clerk/certificates?status=Processing');

        $this->assertSame(['ST-002'], array_column($response->inertiaProps('requests.data'), 'reference'));
        $this->assertSame('Processing', $response->inertiaProps('filters.status'));

        // Unknown statuses are ignored, not passed through.
        $bogus = $this->actingAs($clerk)->get('/clerk/certificates?status=Bogus');
        $this->assertSame(2, $bogus->inertiaProps('requests.total'));
        $this->assertSame('', $bogus->inertiaProps('filters.status'));
    }

    public function test_certificate_type_filter_is_applied_and_scoped(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $indigency = Certificate::create([
            'barangay_id' => $this->barangay->id,
            'code' => 'BRGY-INDIGENCY',
            'name' => 'Certificate of Indigency',
            'fee' => 0,
            'is_active' => true,
        ]);

        $this->createRequest($this->barangay, $this->purok, $indigency, 'TYPE-001');
        $this->createRequest($this->barangay, $this->purok, $this->certificate, 'TYPE-002');

        $response = $this->actingAs($clerk)->get('/clerk/certificates?certificate_id='.$indigency->id);

        $this->assertSame(['TYPE-001'], array_column($response->inertiaProps('requests.data'), 'reference'));

        // A certificate id from another barangay is ignored, not applied.
        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $foreignCertificate = Certificate::create([
            'barangay_id' => $otherBarangay->id,
            'code' => 'FOREIGN-CERT',
            'name' => 'Foreign Certificate',
            'fee' => 0,
            'is_active' => true,
        ]);

        $ignored = $this->actingAs($clerk)->get('/clerk/certificates?certificate_id='.$foreignCertificate->id);
        $this->assertSame(2, $ignored->inertiaProps('requests.total'));
        $this->assertSame('', $ignored->inertiaProps('filters.certificate_id'));
    }

    public function test_date_range_filter_includes_boundaries(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->createRequest($this->barangay, $this->purok, $this->certificate, 'DATE-001', [
            'submitted_at' => '2026-01-01 09:00:00',
        ]);
        $this->createRequest($this->barangay, $this->purok, $this->certificate, 'DATE-002', [
            'submitted_at' => '2026-01-15 12:00:00',
        ]);
        $this->createRequest($this->barangay, $this->purok, $this->certificate, 'DATE-003', [
            'submitted_at' => '2026-02-01 09:00:00',
        ]);

        $response = $this->actingAs($clerk)
            ->get('/clerk/certificates?date_from=2026-01-01&date_to=2026-01-15');

        $this->assertSame(
            ['DATE-002', 'DATE-001'],
            array_column($response->inertiaProps('requests.data'), 'reference'),
        );

        // A malformed date is ignored rather than reaching the query.
        $malformed = $this->actingAs($clerk)
            ->get('/clerk/certificates?date_from=not-a-date');

        $this->assertSame(3, $malformed->inertiaProps('requests.total'));
        $this->assertSame('', $malformed->inertiaProps('filters.date_from'));
    }

    public function test_allowed_page_sizes_are_honored_and_invalid_normalized(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        for ($i = 1; $i <= 12; $i++) {
            $this->createRequest(
                $this->barangay,
                $this->purok,
                $this->certificate,
                'PP-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            );
        }

        foreach ([10, 25, 50] as $size) {
            $response = $this->actingAs($clerk)->get('/clerk/certificates?per_page='.$size);
            $this->assertSame($size, $response->inertiaProps('requests.per_page'));
        }

        // Anything outside the allow-list falls back to the default.
        $invalid = $this->actingAs($clerk)->get('/clerk/certificates?per_page=9999');
        $this->assertSame(10, $invalid->inertiaProps('requests.per_page'));
    }

    public function test_pagination_is_newest_first_and_preserves_query_string(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        for ($i = 1; $i <= 12; $i++) {
            $this->createRequest(
                $this->barangay,
                $this->purok,
                $this->certificate,
                'PG-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                ['status' => 'Processing', 'submitted_at' => now()->subMinutes(20 - $i)],
            );
        }

        $response = $this->actingAs($clerk)
            ->get('/clerk/certificates?status=Processing&per_page=10');

        $this->assertSame(10, $response->inertiaProps('requests.per_page'));
        $this->assertSame(12, $response->inertiaProps('requests.total'));
        $this->assertSame(2, $response->inertiaProps('requests.last_page'));
        $this->assertSame('PG-012', $response->inertiaProps('requests.data.0.reference'));

        $nextPageUrl = (string) $response->inertiaProps('requests.next_page_url');
        $this->assertStringContainsString('status=Processing', $nextPageUrl);
        $this->assertStringContainsString('per_page=10', $nextPageUrl);
        $this->assertStringContainsString('page=2', $nextPageUrl);
    }

    public function test_row_shape_exposes_only_clerk_needed_fields(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->createRequest($this->barangay, $this->purok, $this->certificate, 'SHAPE-001');

        $row = $this->actingAs($clerk)
            ->get('/clerk/certificates')
            ->inertiaProps('requests.data.0');

        $this->assertSame([
            'id',
            'reference',
            'certificate',
            'resident',
            'status',
            'submitted_at',
        ], array_keys($row));

        $this->assertSame(['name', 'resident_number'], array_keys($row['resident']));
        $this->assertArrayNotHasKey('mobile', $row['resident']);
        $this->assertArrayNotHasKey('email', $row['resident']);
        $this->assertArrayNotHasKey('purpose', $row);
        $this->assertArrayNotHasKey('admin_note', $row);
    }

    public function test_certificate_options_are_scoped_to_own_barangay(): void
    {
        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        Certificate::create([
            'barangay_id' => $otherBarangay->id,
            'code' => 'FOREIGN-CERT',
            'name' => 'Foreign Certificate',
            'fee' => 0,
            'is_active' => true,
        ]);

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $options = $this->actingAs($clerk)
            ->get('/clerk/certificates')
            ->inertiaProps('certificateOptions');

        $this->assertSame(['Barangay Clearance'], array_column($options, 'name'));
    }

    public function test_clerk_has_no_request_status_update_route(): void
    {
        $request = $this->createRequest($this->barangay, $this->purok, $this->certificate, 'NO-UPDATE-001');
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->put('/admin/certificates/'.$request->id.'/status', ['status' => 'Processing'])
            ->assertStatus(403);

        $this->assertSame('Pending', $request->refresh()->status);

        // The Clerk route group exposes no status-update endpoint of its own.
        $clerkStatusRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'clerk/certificates'))
            ->reject(fn ($route) => in_array('GET', $route->methods(), true));

        $this->assertTrue($clerkStatusRoutes->isEmpty(), 'Clerk certificates routes must be read-only.');
    }

    public function test_qr_and_manual_entry_scanner_source_remains_intact(): void
    {
        $source = file_get_contents(base_path('resources/js/pages/Clerk/Certificates.tsx'));

        // The physical scanner, QR verification endpoint, and Manual Entry mode
        // must survive the certificate-management additions.
        $this->assertStringContainsString("axios.get('/api/qr/verify-token'", $source);
        $this->assertStringContainsString('switchMode', $source);
        $this->assertStringContainsString('MANUAL ENTRY', $source);
        $this->assertStringContainsString('QR VERIFIED', $source);
        $this->assertStringContainsString("form.action = '/clerk/generate-certificate'", $source);

        // The scan input keeps autofocus on mount so a handheld scanner can type
        // into it, and the "Scan another" control remains available.
        $this->assertStringContainsString('autoFocus', $source);
        $this->assertStringContainsString('Scan another', $source);

        // No fabricated records or simulated QR values may be reintroduced.
        $this->assertStringNotContainsString('RES-SAMPLE', $source);
        $this->assertStringNotContainsString('LEWIN LAGUISMA', $source);
        $this->assertStringNotContainsString('RES-2026-001', $source);
    }

    public function test_qr_scan_input_does_not_steal_focus_on_blur(): void
    {
        $source = file_get_contents(base_path('resources/js/pages/Clerk/Certificates.tsx'));

        // Regression: an unconditional blur-refocus loop made the modal's other
        // fields (certificate type, purpose, signatory) unclickable after a
        // scan. The scan input must never re-grab focus on blur.
        $this->assertStringNotContainsString('window.setTimeout(focusInput, 0)', $source);
        $this->assertStringNotContainsString('focusInput', $source);
        $this->assertStringNotContainsString('onBlur', $source);
    }

    public function test_qr_scanner_is_not_a_nested_form_and_handles_enter_inline(): void
    {
        $source = file_get_contents(base_path('resources/js/pages/Clerk/Certificates.tsx'));

        $scanner = $this->section($source, 'function QrScanInput', 'export default function Dashboard');

        // The scanner must not render its own inner <form>; nesting a form inside
        // the modal's outer form made the lookup button/Enter submit the outer
        // "Proceed" form instead of running the QR lookup.
        $this->assertStringNotContainsString('<form', $scanner);
        $this->assertStringNotContainsString('onSubmit={submit}', $scanner);
        $this->assertStringNotContainsString('type="submit"', $scanner);

        // The lookup button is a plain button that runs the lookup handler.
        $this->assertStringContainsString('type="button"', $scanner);
        $this->assertStringContainsString('onClick={submit}', $scanner);

        // Enter on the scan input is intercepted so it cannot submit the outer
        // form, and instead invokes the QR lookup.
        $this->assertStringContainsString("e.key === 'Enter'", $scanner);
        $this->assertStringContainsString('e.preventDefault()', $scanner);
        $this->assertStringContainsString('submit()', $scanner);

        // The input stays a normal editable text field: no readOnly/disabled,
        // because a keyboard-wedge scanner must still be able to send keys.
        $input = $this->section($source, 'ref={inputRef}', '/>');
        $this->assertStringNotContainsString('readOnly', $input);
        $this->assertStringNotContainsString('disabled', $input);
        $this->assertStringContainsString('type="text"', $input);
    }

    public function test_qr_scanner_blocks_paste_but_not_the_physical_scanner(): void
    {
        $source = file_get_contents(base_path('resources/js/pages/Clerk/Certificates.tsx'));

        $scanner = $this->section($source, 'function QrScanInput', 'export default function Dashboard');

        // Paste is blocked so clipboard content cannot be dropped in.
        $this->assertStringContainsString('onPaste', $scanner);
        $this->assertStringContainsString('e.preventDefault()', $scanner);

        // No strict per-keystroke timing filter that could reject real scans.
        $this->assertStringNotContainsString('timeStamp', $scanner);
        $this->assertStringNotContainsString('lastKeyTime', $scanner);
    }

    public function test_qr_lookup_reports_distinct_inline_errors(): void
    {
        $source = file_get_contents(base_path('resources/js/pages/Clerk/Certificates.tsx'));

        // Empty submission gets its own message.
        $this->assertStringContainsString('Please scan a resident QR code first.', $source);

        // A 400/404 (malformed/unknown/inactive) is a distinct invalid-QR message.
        $this->assertStringContainsString('Invalid QR code. Please scan a valid resident QR code.', $source);
        $this->assertStringContainsString('status === 400 || status === 404', $source);

        // A 2xx response without a usable resident payload is a distinct message.
        $this->assertStringContainsString('Resident not found. Please scan the correct resident QR code.', $source);

        // Network/server failures must not be mislabelled as an invalid QR.
        $this->assertStringContainsString('Unable to verify the QR code right now.', $source);
        $this->assertStringContainsString('axios.isAxiosError(error)', $source);

        // Errors stay inline; no browser alert() and no navigation on failure.
        $this->assertStringNotContainsString('window.alert', $source);
        $this->assertStringNotContainsString('alert(', $source);
    }

    public function test_qr_lookup_failure_keeps_the_form_state(): void
    {
        $source = file_get_contents(base_path('resources/js/pages/Clerk/Certificates.tsx'));

        // clearResidentIdentity only resets resident identity; it must not reset
        // the whole form (which would discard certificate type/purpose/signatory).
        $clear = $this->section($source, 'const clearResidentIdentity', 'const switchMode');

        $this->assertStringContainsString("resident_id: ''", $clear);
        $this->assertStringContainsString("resident_name: ''", $clear);
        $this->assertStringNotContainsString('reset(', $clear);
        $this->assertStringNotContainsString('setFormStep(2)', $clear);

        // A visible recovery control is offered on the error state.
        $this->assertStringContainsString('Try again', $source);
    }

    /**
     * Return the slice of source between two markers (exclusive of the end marker).
     */
    private function section(string $source, string $start, string $end): string
    {
        $startAt = strpos($source, $start);

        if ($startAt === false) {
            $this->fail("Marker not found in source: {$start}");
        }

        $endAt = strpos($source, $end, $startAt);

        return $endAt === false
            ? substr($source, $startAt)
            : substr($source, $startAt, $endAt - $startAt);
    }

    public function test_issued_tab_does_not_fabricate_records(): void
    {
        $source = file_get_contents(base_path('resources/js/pages/Clerk/Certificates.tsx'));

        // The Issued tab now renders real, server-persisted issuance history.
        // No placeholder, and no fabricated/sample records may remain.
        $this->assertStringNotContainsString('Issuance history is not available yet.', $source);
        $this->assertStringNotContainsString('Persisted issuance records are coming soon.', $source);
        $this->assertStringContainsString('Issued / Completed', $source);
        $this->assertStringContainsString('All Records', $source);
        $this->assertStringNotContainsString('RES-SAMPLE', $source);
        $this->assertStringNotContainsString('LEWIN LAGUISMA', $source);
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
