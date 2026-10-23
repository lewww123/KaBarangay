<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Certificate;
use App\Models\CertificateRequest;
use App\Models\Purok;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
                ->where('stats.residents', 2));
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
            ->assertInertia(fn ($page) => $page->where('stats.residents', 1));
    }

    /*
    |--------------------------------------------------------------------------
    | Announcement approval counts
    |--------------------------------------------------------------------------
    */

    public function test_pending_announcement_count_only_counts_pending(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->createAnnouncement($this->barangay, ['approval_status' => 'pending']);
        $this->createAnnouncement($this->barangay, ['approval_status' => 'pending']);
        $this->createAnnouncement($this->barangay, ['approval_status' => 'approved']);
        $this->createAnnouncement($this->barangay, ['approval_status' => 'rejected']);

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.pendingAnnouncements', 2)
                ->where('activity.pendingApprovals', 2));
    }

    public function test_pending_announcement_count_is_scoped_to_own_barangay(): void
    {
        $other = $this->createBarangay('Barangay Two', 'BRGY-TWO');

        $this->createAnnouncement($this->barangay, ['approval_status' => 'pending']);
        $this->createAnnouncement($other, ['approval_status' => 'pending']);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('stats.pendingAnnouncements', 1));
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
                ->where('stats.activeStaff', 5)
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
            ->assertInertia(fn ($page) => $page->where('stats.activeStaff', 2));
    }

    /*
    |--------------------------------------------------------------------------
    | Purok counts and dropdown data
    |--------------------------------------------------------------------------
    */

    public function test_purok_count_and_dropdown_are_scoped_to_own_barangay(): void
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
                ->where('puroks.0.name', 'Purok 1')
                ->where('puroks.1.name', 'Purok Alpha')
                ->where('puroks.2.name', 'Purok Beta'));
    }

    public function test_purok_dropdown_handles_empty_list(): void
    {
        $empty = $this->createBarangay('Barangay Empty', 'BRGY-EMPTY');
        $admin = $this->createStaff($empty, 'admin');

        $this->createPurok($this->barangay, 'Purok Gamma');

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.puroks', 0)
                ->has('puroks', 0));
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
            ->assertInertia(fn ($page) => $page->where('stats.releasedCertificates', 1));
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
            ->assertInertia(fn ($page) => $page->where('stats.releasedCertificates', 1));
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
                ->missing('stats.cases')
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

        $this->createAnnouncement($this->barangay, ['approval_status' => 'pending', 'title' => 'Pending Announcement']);
        $this->createPendingRegistration($this->barangay, $this->purok, ['first_name' => 'Jane', 'last_name' => 'Doe']);

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(function ($page) {
                $page->component('Dashboard')
                    ->has('notifications', 2);

                $notifications = $page->toArray()['props']['notifications'];

                foreach ($notifications as $notification) {
                    $this->assertNotSame('', $notification['time']);
                    $this->assertNotContains($notification['title'], ['Today', 'Yesterday']);
                    $this->assertContains(
                        $notification['href'],
                        ['/admin/announcements/approvals', '/admin/residents', '/admin/sectors', '/admin/staff']
                    );
                }
            });
    }

    /*
    |--------------------------------------------------------------------------
    | System Overview links use verified destinations
    |--------------------------------------------------------------------------
    */

    public function test_system_overview_only_links_to_verified_destinations(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->createAnnouncement($this->barangay, ['approval_status' => 'pending']);
        $this->createPendingRegistration($this->barangay, $this->purok);

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(function ($page) {
                $notifications = $page->toArray()['props']['notifications'];

                $allowed = [
                    '/admin/announcements/approvals',
                    '/admin/residents',
                    '/admin/sectors',
                    '/admin/staff',
                ];

                foreach ($notifications as $notification) {
                    $this->assertContains($notification['href'], $allowed);
                }
            });
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
                ->where('stats.residents', 0)
                ->where('stats.pendingAnnouncements', 0)
                ->where('stats.activeStaff', 1)
                ->where('stats.puroks', 0)
                ->where('stats.releasedCertificates', 0)
                ->where('activity.pendingApprovals', 0)
                ->where('activity.activeStaff', 1)
                ->has('puroks', 0)
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
