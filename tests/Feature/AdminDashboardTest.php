<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Certificate;
use App\Models\CertificateRequest;
use App\Models\Purok;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Purok $purok;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $this->purok = $this->createPurok($this->barangay, 'Purok 1');
    }

    /*
    |--------------------------------------------------------------------------
    | Access control
    |--------------------------------------------------------------------------
    */

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect(route('login'));
    }

    public function test_non_admin_roles_cannot_access_dashboard(): void
    {
        foreach (['clerk', 'senior', 'pwd', 'lupon'] as $role) {
            $user = $this->createStaff($this->barangay, $role);

            $this->actingAs($user)->get('/admin')->assertStatus(403);
        }
    }

    public function test_super_admin_cannot_access_dashboard(): void
    {
        $superAdmin = $this->createStaff($this->barangay, 'super_admin');

        $this->actingAs($superAdmin)->get('/admin')->assertStatus(403);
    }

    public function test_inactive_admin_cannot_access_dashboard(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin', ['status' => 'inactive']);

        $this->actingAs($admin)->get('/admin')->assertStatus(403);
    }

    public function test_dashboard_renders_for_admin(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Dashboard'));
    }

    /*
    |--------------------------------------------------------------------------
    | Residents count (matches Population definition)
    |--------------------------------------------------------------------------
    */

    public function test_resident_count_counts_only_active_residents(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->createResident($this->barangay, $this->purok, ['mobile' => '09170000001', 'resident_number' => 'A-1']);
        $this->createResident($this->barangay, $this->purok, ['mobile' => '09170000002', 'resident_number' => 'A-2']);

        // Inactive resident must not count.
        $inactive = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170000003', 'resident_number' => 'A-3']);
        $inactive->update(['status' => 'inactive']);

        // Pending registration is not a resident yet.
        $this->createPendingRegistration($this->barangay, $this->purok);

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('stats.residents', 3));
    }

    public function test_resident_count_is_scoped_to_own_barangay(): void
    {
        $other = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $otherPurok = $this->createPurok($other, 'Purok 2');

        $this->createResident($this->barangay, $this->purok, ['mobile' => '09171110001', 'resident_number' => 'B-1']);
        $this->createResident($other, $otherPurok, ['mobile' => '09172220001', 'resident_number' => 'C-1']);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('stats.residents', 2));
    }

    public function test_dashboard_exposes_only_approved_summary_metrics(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $qualified = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170002001']);
        $qualified->registration()->update(['status' => 'pending']);
        $qualified->forceFill(['email_verified_at' => null])->save();
        $this->createResident($this->barangay, $this->purok, ['mobile' => '09170002002']);

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.residents', 3)
                ->missing('stats.totalResidents')
                ->missing('stats.pendingApplications'));
    }

    /*
    |--------------------------------------------------------------------------
    | Announcement counts
    |--------------------------------------------------------------------------
    */

    public function test_total_programs_and_announcements_counts_are_scoped(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $other = $this->createBarangay('Barangay Two', 'BRGY-TWO');

        $this->createAnnouncement($this->barangay, ['category' => 'Event', 'approval_status' => 'pending']);
        $this->createAnnouncement($this->barangay, ['category' => 'Event', 'approval_status' => 'rejected']);
        $this->createAnnouncement($this->barangay, ['category' => 'General']);
        $this->createAnnouncement($this->barangay, ['category' => 'General', 'requires_attendance' => true]);
        $this->createAnnouncement($other, ['category' => 'Event']);

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.totalPrograms', 3)
                ->where('stats.totalAnnouncements', 4));
    }

    public function test_dashboard_shows_only_published_approved_announcements_for_own_barangay(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $published = $this->createAnnouncement($this->barangay, [
            'title' => 'Published program', 'content' => 'Monitoring details', 'category' => 'Event',
            'event_start_at' => now()->addDay(), 'location' => 'Barangay Hall',
        ]);
        $this->createAnnouncement($this->barangay, ['title' => 'Unpublished', 'is_published' => false]);
        $this->createAnnouncement($this->barangay, ['title' => 'Awaiting approval', 'approval_status' => 'pending']);
        $other = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $this->createAnnouncement($other, ['title' => 'Other barangay']);

        $this->actingAs($admin)->get('/admin')->assertOk()->assertInertia(fn ($page) => $page
            ->has('announcements', 1)
            ->where('announcements.0.id', $published->id)
            ->where('announcements.0.title', 'Published program')
            ->where('announcements.0.content', 'Monitoring details')
            ->where('announcements.0.location', 'Barangay Hall'));
    }

    public function test_admin_announcement_review_routes_are_registered(): void
    {
        $this->assertTrue(Route::has('admin.announcements.approvals'));
        $this->assertTrue(Route::has('admin.announcements.approve'));
        $this->assertTrue(Route::has('admin.announcements.reject'));
    }

    /*
    |--------------------------------------------------------------------------
    | Active staff counts
    |--------------------------------------------------------------------------
    */

    public function test_active_staff_count_only_counts_active_non_super_admin(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->createStaff($this->barangay, 'clerk');
        $this->createStaff($this->barangay, 'senior');
        $this->createStaff($this->barangay, 'pwd');
        $this->createStaff($this->barangay, 'lupon');

        $this->createStaff($this->barangay, 'clerk', ['status' => 'inactive']);
        $this->createStaff($this->barangay, 'super_admin');

        // The counting admin itself is active staff.
        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('activity.activeStaff', 5));
    }

    public function test_active_staff_count_is_scoped_to_own_barangay(): void
    {
        $other = $this->createBarangay('Barangay Two', 'BRGY-TWO');

        $admin = $this->createStaff($this->barangay, 'admin');
        $this->createStaff($this->barangay, 'clerk');
        $this->createStaff($other, 'clerk');

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('activity.activeStaff', 2));
    }

    /*
    |--------------------------------------------------------------------------
    | Purok counts
    |--------------------------------------------------------------------------
    */

    public function test_purok_count_is_scoped_to_own_barangay(): void
    {
        $this->createPurok($this->barangay, 'Purok Alpha');
        $this->createPurok($this->barangay, 'Purok Beta');

        $other = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $this->createPurok($other, 'Purok Gamma');

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.puroks', 3)
                ->has('puroks', 3)
                ->where('puroks', fn ($puroks) => collect($puroks)
                    ->pluck('name')
                    ->sort()
                    ->values()
                    ->all() === ['Purok 1', 'Purok Alpha', 'Purok Beta']));
    }

    public function test_purok_count_handles_empty_list(): void
    {
        $empty = $this->createBarangay('Barangay Empty', 'BRGY-EMPTY');
        $admin = $this->createStaff($empty, 'admin');

        $this->createPurok($this->barangay, 'Purok Gamma');

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.puroks', 1)
                ->has('puroks', 1));
    }

    /*
    |--------------------------------------------------------------------------
    | Released certificates
    |--------------------------------------------------------------------------
    */

    public function test_released_certificate_count_includes_only_released(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $certificate = Certificate::create([
            'barangay_id' => $this->barangay->id,
            'code' => 'BRGY-CLEARANCE',
            'name' => 'Barangay Clearance',
            'fee' => 50,
            'is_active' => true,
        ]);

        $this->createCertificateRequest($this->barangay, $this->purok, $certificate, 'REL-001', 'Released');
        $this->createCertificateRequest($this->barangay, $this->purok, $certificate, 'PROC-001', 'Processing');
        $this->createCertificateRequest($this->barangay, $this->purok, $certificate, 'REJ-001', 'Rejected');

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->missing('stats.releasedCertificates'));
    }

    public function test_released_certificate_count_is_scoped_to_own_barangay(): void
    {
        $other = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $otherPurok = $this->createPurok($other, 'Purok 2');

        $certificate = Certificate::create([
            'barangay_id' => $this->barangay->id,
            'code' => 'BRGY-CLEARANCE',
            'name' => 'Barangay Clearance',
            'fee' => 50,
            'is_active' => true,
        ]);

        $otherCertificate = Certificate::create([
            'barangay_id' => $other->id,
            'code' => 'BRGY-CLEARANCE',
            'name' => 'Barangay Clearance',
            'fee' => 50,
            'is_active' => true,
        ]);

        $this->createCertificateRequest($this->barangay, $this->purok, $certificate, 'REL-OWN', 'Released');
        $this->createCertificateRequest($other, $otherPurok, $otherCertificate, 'REL-OTHER', 'Released');

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->missing('stats.releasedCertificates'));
    }

    public function test_issued_certificates_card_defers_its_destination(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        // The destination is deferred: the controller must not expose a
        // certificates destination href, and must not link to /admin/reports
        // or the standalone /admin/certificates page for this card.
        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->missing('certificateDestination')
                ->missing('releasedCertificatesHref')
                ->missing('destination.releasedCertificates'));
    }

    /*
    |--------------------------------------------------------------------------
    | Lupon / Cases must not fabricate data
    |--------------------------------------------------------------------------
    */

    public function test_dashboard_does_not_fabricate_lupon_case_metrics(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('stats.cases')
                ->missing('stats.luponCases')
                ->missing('stats.openCases')
                ->missing('activity.openCases')
                ->missing('lupon'));
    }

    /*
    |--------------------------------------------------------------------------
    | Notifications come from real records only
    |--------------------------------------------------------------------------
    */

    public function test_notifications_are_empty_when_no_records_exist(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('notifications', 0));
    }

    public function test_notifications_are_built_from_real_pending_records(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->createPendingRegistration($this->barangay, $this->purok, ['first_name' => 'Jane', 'last_name' => 'Doe']);

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(function ($page) {
                $page->component('Dashboard')
                    ->has('notifications', 1);

                $notifications = $page->toArray()['props']['notifications'];

                foreach ($notifications as $notification) {
                    $this->assertNotSame('', $notification['time']);
                    $this->assertNotContains($notification['title'], ['Today', 'Yesterday']);
                    $this->assertContains(
                        $notification['href'],
                        ['/admin/residents', '/admin/sectors', '/admin/staff']
                    );
                }
            });
    }

    /*
    |--------------------------------------------------------------------------
    | Dashboard notifications use verified destinations
    |--------------------------------------------------------------------------
    */

    public function test_dashboard_notifications_only_link_to_verified_destinations(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->createPendingRegistration($this->barangay, $this->purok);

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(function ($page) {
                $notifications = $page->toArray()['props']['notifications'];

                $allowed = [
                    '/admin/residents',
                    '/admin/sectors',
                    '/admin/staff',
                ];

                foreach ($notifications as $notification) {
                    $this->assertContains($notification['href'], $allowed);
                }
            });
    }

    public function test_certificate_notifications_load_names_with_constant_queries(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $certificate = Certificate::create([
            'barangay_id' => $this->barangay->id,
            'code' => 'CERT-NOTIFICATION',
            'name' => 'Certificate for Notification',
            'fee' => 10,
            'is_active' => true,
        ]);

        $capture = false;
        $certificateSelects = [];
        DB::listen(function ($query) use (&$capture, &$certificateSelects): void {
            $sql = strtolower($query->sql);
            if ($capture && str_starts_with(ltrim($sql), 'select') && str_contains($sql, 'from "certificates"')) {
                $certificateSelects[] = $query->sql;
            }
        });

        foreach ([0, 1, 3] as $requestCount) {
            for ($index = 0; $index < $requestCount; $index++) {
                $this->createCertificateRequest(
                    $this->barangay,
                    $this->purok,
                    $certificate,
                    'NOTIFY-'.$requestCount.'-'.$index,
                    'Processing',
                );
            }

            $certificateSelects = [];
            $capture = true;
            $response = $this->actingAs($admin)->get('/admin')->assertOk();
            $capture = false;

            $notifications = [];
            $response->assertInertia(function ($page) use (&$notifications): void {
                $page->component('Dashboard');
                $notifications = $page->toArray()['props']['notifications'];
            });
            $certificateNotifications = array_values(array_filter(
                $notifications,
                fn (array $notification) => str_starts_with($notification['id'], 'cert-'),
            ));

            $this->assertCount($requestCount, $certificateNotifications);
            foreach ($certificateNotifications as $notification) {
                $this->assertSame('Certificate for Notification', $notification['description']);
            }

            $this->assertCount(1, $certificateSelects, 'Certificate lookup count stays fixed as notification rows grow.');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Empty datasets render without errors
    |--------------------------------------------------------------------------
    */

    public function test_empty_datasets_render_without_errors(): void
    {
        $empty = $this->createBarangay('Barangay Empty', 'BRGY-EMPTY');
        $admin = $this->createStaff($empty, 'admin');

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('stats.residents', 1)
                ->where('stats.totalPrograms', 0)
                ->where('stats.totalAnnouncements', 0)
                ->where('stats.puroks', 1)
                ->missing('stats.releasedCertificates')
                ->where('activity.activeStaff', 1)
                ->has('puroks', 1)
                ->has('notifications', 0));
    }

    /*
    |--------------------------------------------------------------------------
    | Performance guard: aggregate queries must not scale with resident count
    |--------------------------------------------------------------------------
    */

    public function test_dashboard_query_count_is_constant_regardless_of_resident_count(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        DB::enableQueryLog();

        $this->actingAs($admin)->get('/admin')->assertOk();
        $baseline = count(DB::getQueryLog());

        for ($i = 0; $i < 10; $i++) {
            $this->createResident($this->barangay, $this->purok, [
                'mobile' => '0917999'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'resident_number' => 'P-'.$i,
            ]);
        }

        DB::flushQueryLog();

        $this->actingAs($admin)->get('/admin')->assertOk();
        $after = count(DB::getQueryLog());

        DB::disableQueryLog();

        $this->assertSame($baseline, $after);
    }

    /*
    |--------------------------------------------------------------------------
    | Existing behaviour must not regress
    |--------------------------------------------------------------------------
    */

    public function test_dashboard_does_not_regress_existing_population_page(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/population')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Population'));
    }

    private function createCertificateRequest(
        Barangay $barangay,
        Purok $purok,
        Certificate $certificate,
        string $reference,
        string $status
    ): CertificateRequest {
        $resident = $this->createResident($barangay, $purok, [
            'mobile' => '0917'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
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
