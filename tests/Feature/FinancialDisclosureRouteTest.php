<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class FinancialDisclosureRouteTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function pages(): array
    {
        return [
            'allocation' => [
                '/admin/financial-disclosure/allocations',
                'Admin/FinancialDisclosure/Allocation',
                'admin.financial-disclosure.allocations.index',
            ],
            'collection' => [
                '/admin/financial-disclosure/collections',
                'Admin/FinancialDisclosure/Collection',
                'admin.financial-disclosure.collections.index',
            ],
            'disbursement' => [
                '/admin/financial-disclosure/disbursements',
                'Admin/FinancialDisclosure/Disbursement',
                'admin.financial-disclosure.disbursements.index',
            ],
            'status and reports' => [
                '/admin/financial-disclosure/status-reports',
                'Admin/FinancialDisclosure/StatusAndReports',
                'admin.financial-disclosure.status-reports.index',
            ],
        ];
    }

    #[DataProvider('pages')]
    public function test_guest_is_redirected_to_login(string $url, string $component, string $routeName): void
    {
        $this->get($url)->assertRedirect(route('login'));
    }

    #[DataProvider('pages')]
    public function test_non_admin_staff_is_forbidden(string $url, string $component, string $routeName): void
    {
        $barangay = $this->createBarangay('Clerk Barangay', 'FD-CLERK');
        $clerk = $this->createStaff($barangay, 'clerk');

        $this->actingAs($clerk)->get($url)->assertForbidden();
    }

    #[DataProvider('pages')]
    public function test_admin_can_open_the_page_skeleton(string $url, string $component, string $routeName): void
    {
        $this->withoutVite();
        $barangay = $this->createBarangay('Admin Barangay', 'FD-ADMIN');
        $admin = $this->createAssignedStaff([
            'barangay' => $barangay,
            'purok' => $this->createPurok($barangay),
        ], 'admin', 'Barangay Admin');

        $this->assertTrue(Route::has($routeName));

        $this->actingAs($admin)
            ->get($url)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component($component));
    }

    public function test_attachment_upload_frontend_endpoint_matches_registered_route(): void
    {
        $route = Route::getRoutes()->getByName('admin.financial-disclosure.attachments.store');

        $this->assertNotNull($route);

        $endpoint = '/'.$route->uri();
        $source = file_get_contents(resource_path('js/pages/Admin/FinancialDisclosure/StatusAndReports.tsx'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString('${baseUrl}/attachments', $source);
        $this->assertStringContainsString("attachmentForm.post('{$endpoint}'", $source);
    }
}
