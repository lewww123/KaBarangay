<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FinancialDisclosureSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_all_financial_tables(): void
    {
        foreach ([
            'disclosure_periods',
            'allocations',
            'budget_amendments',
            'disbursements',
            'collections',
            'financial_attachments',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }
    }

    public function test_financial_tables_use_uuid_primary_keys(): void
    {
        foreach ([
            'disclosure_periods',
            'allocations',
            'budget_amendments',
            'disbursements',
            'collections',
            'financial_attachments',
        ] as $table) {
            $type = Schema::getColumnType($table, 'id');

            $this->assertContains($type, ['uuid', 'varchar', 'char'], "Unexpected UUID column type for {$table}.id: {$type}");
        }
    }

    public function test_foreign_keys_are_compatible_with_existing_bigint_records(): void
    {
        $this->assertContains('users', $this->foreignKeyTargets('allocations'));
        $this->assertContains('users', $this->foreignKeyTargets('budget_amendments'));
        $this->assertContains('allocations', $this->foreignKeyTargets('budget_amendments'));
        $this->assertContains('users', $this->foreignKeyTargets('disbursements'));
        $this->assertContains('allocations', $this->foreignKeyTargets('disbursements'));
        $this->assertContains('users', $this->foreignKeyTargets('collections'));
        $this->assertContains('certificate_requests', $this->foreignKeyTargets('collections'));
        $this->assertContains('users', $this->foreignKeyTargets('financial_attachments'));
        $this->assertContains('users', $this->foreignKeyTargets('disclosure_periods'));
        $this->assertContains('barangays', $this->foreignKeyTargets('disclosure_periods'));
        $this->assertContains('collections', $this->foreignKeyTargets('certificate_requests'));

        foreach ([
            ['allocations', 'created_by'],
            ['budget_amendments', 'created_by'],
            ['disbursements', 'prepared_by'],
            ['disbursements', 'approved_by'],
            ['disbursements', 'released_by'],
            ['collections', 'encoded_by'],
            ['collections', 'verified_by'],
            ['collections', 'certificate_request_id'],
            ['financial_attachments', 'uploaded_by'],
            ['disclosure_periods', 'published_by'],
            ['disclosure_periods', 'barangay_id'],
        ] as [$table, $column]) {
            $type = Schema::getColumnType($table, $column);

            $this->assertContains($type, ['bigint', 'integer'], "Unexpected compatibility FK type for {$table}.{$column}: {$type}");
        }
    }

    public function test_required_indexes_and_unique_constraints_exist(): void
    {
        $this->assertContains('collections_or_number_unique', $this->indexNames('collections'));
        $this->assertContains('collections_certificate_request_idx', $this->indexNames('collections'));
        $this->assertContains('collections_encoded_by_idx', $this->indexNames('collections'));
        $this->assertContains('collections_verified_by_idx', $this->indexNames('collections'));
        $this->assertContains('financial_attachments_record_idx', $this->indexNames('financial_attachments'));
        $this->assertContains('financial_attachments_uploaded_by_idx', $this->indexNames('financial_attachments'));
        $this->assertContains('disclosure_periods_tenant_period_idx', $this->indexNames('disclosure_periods'));
        $this->assertContains('disclosure_periods_tenant_published_idx', $this->indexNames('disclosure_periods'));
        $this->assertContains('disclosure_periods_published_by_idx', $this->indexNames('disclosure_periods'));
        $this->assertContains('allocations_created_by_idx', $this->indexNames('allocations'));
        $this->assertContains('budget_amendments_allocation_idx', $this->indexNames('budget_amendments'));
        $this->assertContains('budget_amendments_created_by_idx', $this->indexNames('budget_amendments'));
        $this->assertContains('disbursements_allocation_idx', $this->indexNames('disbursements'));
        $this->assertContains('disbursements_prepared_by_idx', $this->indexNames('disbursements'));
        $this->assertContains('disbursements_approved_by_idx', $this->indexNames('disbursements'));
        $this->assertContains('disbursements_released_by_idx', $this->indexNames('disbursements'));
        $this->assertContains('certificate_requests_collection_idx', $this->indexNames('certificate_requests'));
    }

    public function test_certificate_request_financial_fields_are_added_without_removing_existing_fields(): void
    {
        $columns = Schema::getColumnListing('certificate_requests');

        foreach ([
            'barangay_id',
            'resident_id',
            'certificate_id',
            'reference',
            'purpose',
            'fee',
            'status',
            'or_number',
            'amount_paid',
            'is_waived',
            'collection_id',
        ] as $column) {
            $this->assertContains($column, $columns, "Missing certificate_requests column: {$column}");
        }
    }

    public function test_financial_migrations_are_recorded(): void
    {
        $migrations = DB::table('migrations')->pluck('migration')->all();

        foreach ([
            '2026_11_25_000001_create_disclosure_periods_table',
            '2026_11_25_000002_create_allocations_table',
            '2026_11_25_000003_create_budget_amendments_table',
            '2026_11_25_000004_create_disbursements_table',
            '2026_11_25_000005_create_collections_table',
            '2026_11_25_000006_create_financial_attachments_table',
            '2026_11_25_000007_add_financial_fields_to_certificate_requests_table',
            '2026_11_26_000001_make_collection_or_number_nullable',
            '2026_11_26_000002_add_barangay_to_disclosure_periods_table',
            '2026_11_26_000003_make_financial_attachments_record_id_nullable',
        ] as $migration) {
            $this->assertContains($migration, $migrations, "Migration was not recorded: {$migration}");
        }
    }

    /**
     * @return array<int, string>
     */
    private function foreignKeyTargets(string $table): array
    {
        return collect(DB::select("PRAGMA foreign_key_list('{$table}')"))
            ->pluck('table')
            ->map(fn ($value) => (string) $value)
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function indexNames(string $table): array
    {
        return collect(DB::select("PRAGMA index_list('{$table}')"))
            ->pluck('name')
            ->map(fn ($value) => (string) $value)
            ->all();
    }
}
