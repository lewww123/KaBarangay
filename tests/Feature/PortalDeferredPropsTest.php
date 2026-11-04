<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Barangay;
use App\Models\Purok;
use App\Models\SectorApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class PortalDeferredPropsTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Purok $purok;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['inertia.defer_heavy_props' => true]);
        $this->barangay = $this->createBarangay('Portal Deferred', 'PORTAL-DEFER');
        $this->purok = $this->createPurok($this->barangay);
    }

    public static function staffPages(): array
    {
        return [
            ['clerk', '/clerk/dashboard', 'Clerk/Dashboard', ['stats', 'recentAnnouncements']],
            ['clerk', '/clerk/certificates', 'Clerk/Certificates', ['requests', 'issuances', 'allRecords', 'certificateOptions', 'signatories', 'issuedFilters', 'allFilters', 'barangayBranding']],
            ['clerk', '/clerk/announcements', 'Clerk/Announcements/Index', ['announcements', 'tabCounts']],
            ['clerk', '/clerk/citizen-charter', 'Clerk/CitizenCharter/Index', ['services', 'stats']],
            ['clerk', '/clerk/disbursements', 'Clerk/Disbursements/Index', ['documents', 'stats']],
            ['clerk', '/clerk/org-chart', 'OrgChart/Management', ['tree', 'positions', 'stats']],
            ['lupon', '/lupon/cases', 'Lupon/Cases/Index', ['cases', 'counts']],
            ['lupon', '/lupon/complaints', 'Lupon/Complaints', ['complaints', 'stats']],
            ['lupon', '/lupon/hearings', 'Lupon/Hearings/Index', ['hearings']],
            ['senior', '/senior/dashboard', 'Senior/Dashboard', ['stats', 'recentAnnouncements']],
            ['pwd', '/pwd/dashboard', 'Pwd/Dashboard', ['stats', 'recentAnnouncements']],
        ];
    }

    #[DataProvider('staffPages')]
    public function test_staff_data_is_absent_initially_and_resolves_on_deferred_request(string $role, string $url, string $component, array $props): void
    {
        $staff = $this->createPortalStaff($role);
        $this->actingAs($staff)->get($url)->assertOk()->assertInertia(function ($page) use ($component, $props) {
            $page->component($component);
            foreach ($props as $prop) {
                $page->missing($prop);
            }
            $page->loadDeferredProps(function ($deferred) use ($props) {
                foreach ($props as $prop) {
                    $deferred->has($prop);
                }
            });
        });
    }

    public static function residentPages(): array
    {
        return [
            ['/resident', 'Resident/Dashboard', ['resident', 'qrUrl', 'latestAnnouncement', 'sharedEmergencyContacts', 'barangayHotlines']],
            ['/resident/announcements', 'Resident/Announcements', ['announcements', 'categories']],
            ['/resident/programs', 'Resident/Programs', ['programs']],
            ['/resident/certificates', 'Resident/Certificates', ['resident', 'certificates', 'requests']],
            ['/resident/lupon', 'Resident/Lupon', ['resident', 'complaints', 'complaintsAgainstMe']],
            ['/resident/profile', 'Resident/Profile', ['profile']],
            ['/resident/disbursement', 'Resident/Disbursement', ['documents']],
            ['/resident/citizen-charter', 'Resident/CitizenCharter', ['services', 'categories']],
            ['/resident/org-chart', 'Resident/OrgChart', ['tree']],
            ['/resident/sector-applications', 'Resident/SectorApplications/Index', ['applications']],
        ];
    }

    public function test_registration_options_are_present_on_initial_load_and_validation_redirects(): void
    {
        $this->get('/resident/register')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Resident/Register')->has('barangays')->has('sectors'));
        $this->withSession(['errors' => new ViewErrorBag])
            ->get('/resident/register')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Resident/Register')->has('barangays')->has('sectors'));
    }

    #[DataProvider('residentPages')]
    public function test_resident_data_resolves_on_deferred_request(string $url, string $component, array $props): void
    {
        $resident = $this->createResident($this->barangay, $this->purok);
        $this->withSession(['resident_id' => $resident->id])->get($url)->assertOk()->assertInertia(function ($page) use ($component, $props) {
            $page->component($component);
            foreach ($props as $prop) {
                $page->missing($prop);
            }
            $page->loadDeferredProps(function ($deferred) use ($props) {
                foreach ($props as $prop) {
                    $deferred->has($prop);
                }
            });
        });
    }

    public function test_partial_reload_skips_unrequested_certificate_history_queries(): void
    {
        $staff = $this->createStaff($this->barangay, 'clerk');
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->actingAs($staff)->get('/clerk/certificates', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()) ?? '',
            'X-Inertia-Partial-Component' => 'Clerk/Certificates',
            'X-Inertia-Partial-Data' => 'requests,filters',
        ])->assertOk()->assertJsonMissingPath('props.issuances')->assertJsonMissingPath('props.allRecords');

        $this->assertFalse(collect($queries)->contains(fn ($sql) => str_contains($sql, 'certificate_issuances')));
    }

    public static function attendancePages(): array
    {
        return [['clerk', 'all', 'Clerk'], ['senior', 'senior_citizens', 'Senior'], ['pwd', 'pwd', 'Pwd']];
    }

    public static function sectorReviewPages(): array
    {
        return [['senior', 'Senior'], ['pwd', 'Pwd']];
    }

    #[DataProvider('sectorReviewPages')]
    public function test_sector_review_defers_related_records_after_authorization(string $role, string $portal): void
    {
        $staff = $this->createPortalStaff($role);
        $resident = $this->createResident($this->barangay, $this->purok);
        $sector = $this->assignSector($resident, $role);
        $application = SectorApplication::create([
            'tracking_number' => 'DEFERRED-REVIEW-1',
            'barangay_id' => $this->barangay->id,
            'resident_id' => $resident->id,
            'sector_id' => $sector->id,
            'submission_type' => SectorApplication::SUBMISSION_SELF_SERVICE,
            'status' => SectorApplication::STATUS_PENDING_STAFF_REVIEW,
        ]);

        $this->actingAs($staff)->get("/{$role}/applications/{$application->id}")
            ->assertOk()->assertInertia(fn ($page) => $page
            ->component("{$portal}/Applications/Show")
            ->missing('application')
            ->loadDeferredProps(fn ($page) => $page
                ->where('application.id', $application->id)
                ->where('application.resident_id', $resident->id)
                ->has('application.documents')));
    }

    #[DataProvider('attendancePages')]
    public function test_attendance_rosters_are_deferred_and_reload_independently(string $role, string $audience, string $portal): void
    {
        $staff = $this->createPortalStaff($role);
        $announcement = $this->createAnnouncement($this->barangay, [
            'category' => 'Event',
            'target_audience' => $audience,
            'requires_attendance' => true,
        ]);

        $this->actingAs($staff)->get("/{$role}/announcements/{$announcement->id}/attendance")
            ->assertOk()->assertInertia(fn ($page) => $page
            ->component("{$portal}/Announcements/Attendance")
            ->has('announcement')
            ->missing('attendances')
            ->missing('residents')
            ->loadDeferredProps(fn ($page) => $page->has('attendances')->has('residents')));
    }

    private function createPortalStaff(string $role)
    {
        return $this->createAssignedStaff(
            ['barangay' => $this->barangay, 'purok' => $this->purok],
            $role,
            ucfirst($role).' Staff',
        );
    }
}
