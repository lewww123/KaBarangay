<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminFinancialDisclosureNavigationTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function pageFiles(): array
    {
        return [
            'allocation' => ['Admin/FinancialDisclosure/Allocation'],
            'collection' => ['Admin/FinancialDisclosure/Collection'],
            'disbursement' => ['Admin/FinancialDisclosure/Disbursement'],
            'status and reports' => ['Admin/FinancialDisclosure/StatusAndReports'],
        ];
    }

    public function test_admin_sidebar_contains_financial_disclosure_group_and_links(): void
    {
        $source = (string) file_get_contents(resource_path('js/layouts/AdminLayout.tsx'));

        $this->assertStringContainsString('Financial Disclosure', $source);

        foreach ([
            '/admin/financial-disclosure/allocations',
            '/admin/financial-disclosure/collections',
            '/admin/financial-disclosure/disbursements',
            '/admin/financial-disclosure/status-reports',
            'Allocation',
            'Collection',
            'Disbursement',
            'Status & Reports',
            'financial-disclosure-allocation',
            'financial-disclosure-collection',
            'financial-disclosure-disbursement',
            'financial-disclosure-status-reports',
        ] as $needle) {
            $this->assertStringContainsString($needle, $source);
        }
    }

    #[DataProvider('pageFiles')]
    public function test_financial_disclosure_page_files_exist(string $page): void
    {
        $this->assertFileExists(resource_path("js/pages/{$page}.tsx"));
    }

    public static function pageMarkers(): array
    {
        return [
            'allocation' => [
                'Admin/FinancialDisclosure/Allocation',
                [
                    'Allocation — Budget Management',
                    'Database-backed records',
                    'Allocation records',
                    'Sources of Funds',
                    'Mandatory Funds',
                    'Record budget amendment',
                    'No allocation records found',
                ],
            ],
            'collection' => [
                'Admin/FinancialDisclosure/Collection',
                [
                    'Collection — Incoming Payments',
                    'Pending certificate requests',
                    'Collection records',
                    'Status presentation',
                    'Waived collections',
                    'Encode collection',
                ],
            ],
            'disbursement' => [
                'Admin/FinancialDisclosure/Disbursement',
                [
                    'Disbursement — Outgoing Funds',
                    'Disbursement records',
                    'Status workflow',
                    'Allocation balance',
                    'Responsible personnel',
                    'Create disbursement',
                ],
            ],
            'status and reports' => [
                'Admin/FinancialDisclosure/StatusAndReports',
                [
                    'Status & Reports — Financial Disclosure',
                    'Report filters',
                    'Allocation vs. actual per PPA',
                    'Financial audit history',
                    'Project status',
                    'BFDP compliance checklist',
                    'Disclosure period publication',
                    'Supplementary financial documents',
                ],
            ],
        ];
    }

    #[DataProvider('pageMarkers')]
    public function test_financial_disclosure_pages_contain_expected_sections(string $page, array $markers): void
    {
        $source = (string) file_get_contents(resource_path("js/pages/{$page}.tsx"));

        foreach ($markers as $needle) {
            $this->assertStringContainsString($needle, $source);
        }
    }

    public function test_resident_financial_disclosure_page_exists(): void
    {
        $this->assertFileExists(resource_path('js/pages/Resident/FinancialDisclosure.tsx'));
    }
}
