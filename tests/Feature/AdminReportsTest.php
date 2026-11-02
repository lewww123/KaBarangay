<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\AnnouncementAttendance;
use App\Models\Barangay;
use App\Models\Certificate;
use App\Models\CertificateIssuance;
use App\Models\CertificateRequest;
use App\Models\LuponCase;
use App\Models\LuponComplaint;
use App\Models\Purok;
use App\Models\Resident;
use App\Models\Sector;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class AdminReportsTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Barangay $otherBarangay;

    private Purok $purok;

    private Purok $otherPurok;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->barangay = $this->createBarangay('Reports Barangay', 'REPORTS-ONE');
        $this->otherBarangay = $this->createBarangay('Other Reports Barangay', 'REPORTS-TWO');
        $this->purok = $this->createPurok($this->barangay, 'Purok 1');
        $this->otherPurok = $this->createPurok($this->otherBarangay, 'Purok 1');
    }

    public function test_reports_is_admin_only_and_has_no_mutation_route(): void
    {
        $this->get('/admin/reports')->assertRedirect(route('login'));

        $clerk = $this->createStaff($this->barangay, 'clerk');
        $this->actingAs($clerk)->get('/admin/reports')->assertForbidden();

        $inactiveAdmin = $this->createStaff($this->barangay, 'admin', ['status' => 'inactive']);
        $this->actingAs($inactiveAdmin)->get('/admin/reports')->assertForbidden();

        $admin = $this->createStaff($this->barangay, 'admin');
        $this->actingAs($admin)->post('/admin/reports')->assertStatus(405);
    }

    public function test_population_metrics_are_active_distinct_barangay_scoped_and_preserve_general_semantics(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $general = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170001001',
            'resident_number' => 'REPORT-001',
        ]);
        $senior = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170001002',
            'resident_number' => 'REPORT-002',
        ]);
        $both = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170001003',
            'resident_number' => 'REPORT-003',
        ]);
        $inactive = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170001004',
            'resident_number' => 'REPORT-004',
        ]);
        $outside = $this->createResident($this->otherBarangay, $this->otherPurok, [
            'mobile' => '09170001005',
            'resident_number' => 'OTHER-001',
        ]);

        $seniorSector = Sector::create(['name' => 'Senior Citizen', 'slug' => 'senior', 'requires_proof' => true, 'is_active' => true]);
        $pwdSector = Sector::create(['name' => 'PWD', 'slug' => 'pwd', 'requires_proof' => true, 'is_active' => true]);
        $otherSector = Sector::create(['name' => 'Other Group', 'slug' => 'other-group', 'requires_proof' => false, 'is_active' => true]);
        $seniorSector->residents()->attach([$senior->id, $both->id, $inactive->id]);
        $pwdSector->residents()->attach($both->id);
        $otherSector->residents()->attach($outside->id);
        $inactive->update(['status' => 'inactive']);

        $this->actingAs($admin)
            ->get('/admin/reports?period=monthly&date=2026-10-27')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Reports')
                ->where('population.metrics.Total active Residents', 4)
                ->where('population.metrics.Senior Citizens', 2)
                ->where('population.metrics.PWD', 1)
                ->where('population.metrics.General Residents', 2)
                ->where('population.distribution_note', fn ($note) => str_contains($note, 'exclusive distribution chart is omitted'))
                ->where('financial.available', false)
                ->where('window.timezone', 'Asia/Manila'));
    }

    public function test_certificate_issuances_are_counted_by_issued_at_not_request_release_status(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170001011',
            'resident_number' => 'REPORT-011',
        ]);
        $certificate = Certificate::create([
            'barangay_id' => $this->barangay->id,
            'code' => 'RESIDENCY',
            'name' => 'Certificate of Residency',
            'fee' => 100,
            'is_active' => true,
        ]);
        $request = CertificateRequest::create([
            'barangay_id' => $this->barangay->id,
            'resident_id' => $resident->id,
            'certificate_id' => $certificate->id,
            'reference' => 'CERT-REPORT-001',
            'purpose' => 'Record test fixture',
            'fee' => 100,
            'status' => 'Released',
            'submitted_at' => CarbonImmutable::parse('2026-10-06 01:00:00', 'Asia/Manila')->utc(),
            'released_at' => CarbonImmutable::parse('2026-10-06 02:00:00', 'Asia/Manila')->utc(),
        ]);

        $this->createIssuance($this->barangay, '2026-10-05 17:00:00', [
            'certificate_request_id' => $request->id,
            'resident_id' => $resident->id,
            'certificate_type' => 'Certificate of Residency',
            'fee' => 100,
        ]);
        $this->createIssuance($this->otherBarangay, '2026-10-05 17:00:00', [
            'certificate_type' => 'Other Barangay Certificate',
        ]);

        $this->actingAs($admin)
            ->get('/admin/reports?period=daily&date=2026-10-06&category=certificates')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('certificates.count', 1)
                ->where('certificates.by_type.0.count', 1)
                ->where('certificates.trend.1.count', 1)
                ->where('certificates.meaning', fn ($meaning) => str_contains($meaning, 'not proof of physical handover')));
    }

    public function test_lupon_reports_count_manual_and_promoted_case_rows_once_and_by_filed_date(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->createCase($this->barangay, 'REPORT-CASE-001', 'manual', null, '2026-10-06', 'Debt Dispute');

        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170001021',
            'resident_number' => 'REPORT-021',
        ]);
        $complaint = $this->createComplaint($resident, 'REPORT-COMPLAINT-001');
        $this->createComplaint($resident, 'REPORT-COMPLAINT-UNPROMOTED');
        $this->createCase($this->barangay, 'REPORT-CASE-002', 'promoted', $complaint->id, '2026-10-06', 'Property Dispute');
        $this->createCase($this->otherBarangay, 'OTHER-CASE-001', 'manual', null, '2026-10-06', 'Debt Dispute');

        $this->actingAs($admin)
            ->get('/admin/reports?period=monthly&date=2026-10-01&category=lupon')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('lupon.count', 2)
                ->where('lupon.by_type.0.count', 1)
                ->where('lupon.by_type.1.count', 1)
                ->where('lupon.meaning', fn ($meaning) => str_contains($meaning, 'Complaints are not counted separately')));
    }

    public function test_program_and_attendance_reports_include_only_barangay_approved_published_programs(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170001031',
            'resident_number' => 'REPORT-031',
        ]);
        $program = $this->createProgram($this->barangay, 'approved', true, '2026-10-06 09:00:00');
        $this->createProgram($this->barangay, 'pending', false, '2026-10-06 09:00:00');
        $otherResident = $this->createResident($this->otherBarangay, $this->otherPurok, [
            'mobile' => '09170001032',
            'resident_number' => 'OTHER-032',
        ]);
        $otherProgram = $this->createProgram($this->otherBarangay, 'approved', true, '2026-10-06 09:00:00');
        AnnouncementAttendance::create([
            'barangay_id' => $this->barangay->id,
            'announcement_id' => $program->id,
            'resident_id' => $resident->id,
            'status' => 'present',
            'method' => 'manual',
            'attended_at' => CarbonImmutable::parse('2026-10-06 09:15:00', 'Asia/Manila')->utc(),
        ]);
        AnnouncementAttendance::create([
            'barangay_id' => $this->otherBarangay->id,
            'announcement_id' => $otherProgram->id,
            'resident_id' => $otherResident->id,
            'status' => 'present',
            'method' => 'manual',
            'attended_at' => CarbonImmutable::parse('2026-10-06 09:15:00', 'Asia/Manila')->utc(),
        ]);

        $this->actingAs($admin)
            ->get('/admin/reports?period=daily&date=2026-10-06&category=programs')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('programs.count', 1)
                ->where('programs.attendance.0.label', 'present')
                ->where('programs.attendance.0.count', 1));
    }

    public function test_periods_return_expected_bucket_counts_and_range_is_bounded(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        foreach ([
            ['daily', '2026-10-27', 24],
            ['weekly', '2026-10-27', 7],
            ['monthly', '2026-10-01', 31],
            ['yearly', '2026-01-01', 12],
        ] as [$period, $date, $bucketCount]) {
            $this->actingAs($admin)
                ->get('/admin/reports?period='.$period.'&date='.$date)
                ->assertOk()
                ->assertInertia(fn ($page) => $page->has('certificates.trend', $bucketCount));
        }

        $this->actingAs($admin)
            ->get('/admin/reports?period=range&date_from=2025-01-01&date_to=2026-10-27')
            ->assertSessionHasErrors('date_to');

        $this->actingAs($admin)
            ->get('/admin/reports?period=range&date_from=2026-10-02&date_to=2026-10-01')
            ->assertSessionHasErrors('date_to');

        $this->actingAs($admin)
            ->get('/admin/reports?period=fortnight')
            ->assertSessionHasErrors('period');
    }

    public function test_export_contains_only_aggregates_and_escapes_formula_like_labels(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->createIssuance($this->barangay, '2026-10-05 17:00:00', [
            'certificate_type' => '=HYPERLINK("https://example.test")',
            'resident_name' => 'Private Resident Name',
            'resident_number' => 'PRIVATE-001',
        ]);

        $response = $this->actingAs($admin)
            ->get('/admin/reports/export?period=daily&date=2026-10-06&category=certificates')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Issuance type: =HYPERLINK', $csv);
        $this->assertStringNotContainsString(",'=HYPERLINK", $csv);
        $this->assertStringNotContainsString('Private Resident Name', $csv);
        $this->assertStringNotContainsString('PRIVATE-001', $csv);
        $this->assertStringNotContainsString('100.00', $csv);
    }

    public function test_financial_export_keeps_exact_unavailable_csv_and_skips_report_data_queries(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $reportQueries = [];
        $reportTables = [
            'residents', 'sectors', 'resident_sector', 'certificate_issuances',
            'lupon_cases', 'announcements', 'announcement_attendances',
        ];

        DB::listen(function (QueryExecuted $event) use (&$reportQueries, $reportTables): void {
            $sql = strtolower($event->sql);
            foreach ($reportTables as $table) {
                if (preg_match('/\\b'.preg_quote($table, '/').'\\b/', $sql)) {
                    $reportQueries[] = $event->sql;
                    break;
                }
            }
        });

        $response = $this->actingAs($admin)
            ->get('/admin/reports/export?period=daily&date=2026-10-06&category=financial')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->assertSame(
            "Report,Metric,Period,Value\nFinancial,Availability,\"Oct 6, 2026\",\"Unavailable: no authoritative financial ledger\"\n",
            $response->streamedContent(),
        );
        $this->assertSame([], $reportQueries, 'Unavailable financial export does not query report data tables.');
    }

    public function test_program_and_all_csv_exports_skip_unused_program_trend_query(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->createProgram($this->barangay, 'approved', true, '2026-10-06 09:00:00');
        $trendQueries = [];

        DB::listen(function (QueryExecuted $event) use (&$trendQueries): void {
            if (preg_match('/\\bgroup\\s+by\\b.*\\bevent_start_at\\b/is', strtolower($event->sql))) {
                $trendQueries[] = $event->sql;
            }
        });

        foreach (['programs', 'all'] as $category) {
            $trendQueries = [];
            $response = $this->actingAs($admin)
                ->get('/admin/reports/export?period=daily&date=2026-10-06&category='.$category)
                ->assertOk();

            $rows = array_map('str_getcsv', explode("\n", trim($response->streamedContent())));
            $this->assertContains(
                ['Programs & Attendance', 'Approved and published programs', 'Oct 6, 2026', '1'],
                $rows,
            );
            $this->assertContains(
                ['Programs & Attendance', 'Program category: Event', 'Oct 6, 2026', '1'],
                $rows,
            );
            $this->assertSame([], $trendQueries, $category.' export does not execute its unused event-date trend query.');
        }
    }

    public function test_empty_datasets_render_zeroes_and_financial_category_is_explicitly_unavailable(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/reports?period=monthly&date=2026-10-01&category=financial')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('population.metrics.Total active Residents', 1)
                ->where('certificates.count', 0)
                ->where('certificates.trend', [])
                ->where('lupon.count', 0)
                ->where('lupon.by_type', [])
                ->where('programs.count', 0)
                ->where('programs.attendance', [])
                ->where('financial.available', false)
                ->where('financial.message', fn ($message) => str_contains($message, 'unavailable')));
    }

    public function test_population_matches_approved_directory_and_excludes_unqualified_active_rows(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $admin->resident->account()->delete();
        $qualified = [];
        for ($i = 0; $i < 6; $i++) {
            $qualified[] = $this->createResident($this->barangay, $this->purok, ['mobile' => '0917444000'.$i]);
        }
        $qualified[2]->account()->delete();
        $qualified[3]->account()->update(['is_active' => false]);
        $qualified[4]->registration()->update(['status' => 'pending']);
        $qualified[5]->registration()->update(['barangay_id' => $this->otherBarangay->id]);
        $pwd = Sector::create(['name' => 'PWD', 'slug' => 'pwd', 'requires_proof' => true, 'is_active' => true]);
        $pwd->residents()->attach($qualified[3]->id);
        $this->actingAs($admin)->get('/admin/reports?period=monthly&date=2026-11-01')->assertOk()->assertInertia(fn ($page) => $page
            ->where('population.metrics.Total active Residents', 2)
            ->where('population.metrics.General Residents', 2)
            ->where('population.metrics.PWD', 0)
            ->where('population.sectors.0.count', 0));
        $csv = $this->get('/admin/reports/export?period=monthly&date=2026-11-01&category=population')->assertOk()->streamedContent();
        $this->assertStringContainsString('"Total active Residents","Current snapshot",2', $csv);
        $this->assertStringContainsString('"General Residents","Current snapshot",2', $csv);
        $qualified[1]->account()->update(['is_active' => false]);
        $this->get('/admin/reports?period=monthly&date=2026-11-01')->assertOk()->assertInertia(fn ($page) => $page->where('population.metrics.Total active Residents', 1)->where('population.metrics.General Residents', 1));
    }

    private function createIssuance(Barangay $barangay, string $utcTimestamp, array $overrides = []): CertificateIssuance
    {
        static $sequence = 0;
        $sequence++;

        return CertificateIssuance::create([
            'barangay_id' => $barangay->id,
            'certificate_request_id' => null,
            'resident_id' => null,
            'reference' => 'ISS-REPORT-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'certificate_type' => 'Certificate of Residency',
            'resident_name' => 'Issuance Snapshot',
            'resident_number' => null,
            'purok' => 'Purok 1',
            'fee' => null,
            'issued_by' => null,
            'issued_at' => CarbonImmutable::parse($utcTimestamp, 'UTC'),
            ...$overrides,
        ]);
    }

    private function createComplaint(Resident $resident, string $reference): LuponComplaint
    {
        return LuponComplaint::create([
            'barangay_id' => $resident->barangay_id,
            'resident_id' => $resident->id,
            'reference' => $reference,
            'respondent_name' => 'Test Respondent',
            'respondent_resident_status' => 'No',
            'complaint_type' => 'Debt Dispute',
            'incident_date' => '2026-09-30',
            'incident_location' => 'Barangay Hall',
            'narrative' => 'Test-only report data.',
            'requested_action' => 'Mediation',
            'status' => 'Accepted',
            'submitted_at' => CarbonImmutable::parse('2026-10-01 00:00:00', 'UTC'),
        ]);
    }

    private function createCase(Barangay $barangay, string $caseNumber, string $origin, ?int $complaintId, string $filedDate, string $type): LuponCase
    {
        return LuponCase::create([
            'barangay_id' => $barangay->id,
            'complaint_id' => $complaintId,
            'origin' => $origin,
            'case_number' => $caseNumber,
            'title' => 'Test case title',
            'case_type' => $type,
            'phase' => 'Kapitan',
            'status' => 'Active',
            'filed_date' => $filedDate,
            'promoted_at' => $origin === 'promoted' ? CarbonImmutable::parse('2026-10-06 01:00:00', 'UTC') : null,
        ]);
    }

    private function createProgram(Barangay $barangay, string $approvalStatus, bool $published, string $utcStart): Announcement
    {
        return Announcement::create([
            'barangay_id' => $barangay->id,
            'title' => 'Test program',
            'content' => 'Test-only program content.',
            'category' => 'Event',
            'target_audience' => 'all',
            'requires_attendance' => true,
            'event_start_at' => CarbonImmutable::parse($utcStart, 'UTC'),
            'is_published' => $published,
            'approval_status' => $approvalStatus,
        ]);
    }
}
