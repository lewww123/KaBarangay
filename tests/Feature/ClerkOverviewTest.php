<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Purok;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

/**
 * Clerk Overview (/clerk/dashboard).
 *
 * The Clerk dashboard is a real overview backed only by verified data:
 * active-resident count, announcement counts, and the recent announcements
 * list. It must not render the old simulated QR workflow or any fabricated
 * resident identity.
 */
class ClerkOverviewTest extends TestCase
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

    public function test_clerk_overview_renders_real_backend_data(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk', ['name' => 'Clerk Person']);

        $this->createResident($this->barangay, $this->purok, ['mobile' => '09170005001']);
        $this->createAnnouncement($this->barangay, [
            'title' => 'Approved One',
            'approval_status' => 'approved',
            'is_published' => true,
        ]);
        $this->createAnnouncement($this->barangay, [
            'title' => 'Pending One',
            'approval_status' => 'pending',
            'is_published' => false,
        ]);

        $this->actingAs($clerk)
            ->get('/clerk/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Clerk/Dashboard')
                ->where('userName', 'Clerk Person')
                ->where('stats.totalResidents', 1)
                ->where('stats.approvedAnnouncements', 1)
                ->where('stats.pendingAnnouncements', 1)
                ->has('recentAnnouncements', 2));
    }

    public function test_clerk_overview_recent_announcements_expose_only_overview_fields(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->createAnnouncement($this->barangay, [
            'title' => 'Field Check',
            'approval_status' => 'approved',
            'is_published' => true,
        ]);

        $response = $this->actingAs($clerk)->get('/clerk/dashboard');

        $announcement = $response->inertiaProps('recentAnnouncements')[0];

        $this->assertSame(
            ['id', 'title', 'category', 'approval_status', 'is_published', 'created_at'],
            array_keys($announcement)
        );
        $this->assertArrayNotHasKey('content', $announcement);
        $this->assertArrayNotHasKey('rejection_reason', $announcement);
        $this->assertArrayNotHasKey('reviewed_by', $announcement);
    }

    public function test_clerk_overview_contains_no_simulated_qr_or_fabricated_identity(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $source = (string) file_get_contents(
            base_path('resources/js/pages/Clerk/Dashboard.tsx')
        );

        foreach ([
            'Simulate QR Scan',
            'RES-SAMPLE',
            'LEWIN LAGUISMA',
            'RES-2026-001',
            'Purok Masagana',
            'residents/lookup',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $source,
                "Clerk Overview must not contain [{$forbidden}]."
            );
        }

        $this->assertStringNotContainsString(
            'useForm(',
            $source,
            'Clerk Overview must not duplicate the certificate-issuance form.'
        );
    }

    public function test_clerk_sidebar_has_separate_overview_and_certificates_links(): void
    {
        $source = (string) file_get_contents(
            base_path('resources/js/layouts/ClerkLayout.tsx')
        );

        $this->assertStringContainsString('href="/clerk/dashboard"', $source);
        $this->assertStringContainsString('href="/clerk/certificates"', $source);
        $this->assertStringContainsString('label="Overview"', $source);
        $this->assertStringContainsString('label="Certificates"', $source);
    }

    public function test_clerk_certificates_page_exposes_issue_action(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->get('/clerk/certificates')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Clerk/Certificates'));

        $source = (string) file_get_contents(
            base_path('resources/js/pages/Clerk/Certificates.tsx')
        );

        $this->assertStringContainsString('Issue New Certificate', $source);
        $this->assertStringContainsString('/api/qr/verify-token', $source);
        $this->assertStringContainsString('Manual Entry', $source);
    }

    public function test_clerk_overview_has_no_issue_new_certificate_shortcut(): void
    {
        // The Overview shortcut duplicated the Certificates nav link and was
        // removed; certificate issuance lives only on the Certificates page.
        $source = (string) file_get_contents(
            base_path('resources/js/pages/Clerk/Dashboard.tsx')
        );

        $this->assertStringNotContainsString('Issue New Certificate', $source);
        $this->assertStringNotContainsString('+ Issue New Certificate', $source);
    }
}
