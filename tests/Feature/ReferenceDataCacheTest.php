<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\OrgChartPosition;
use App\Support\ReferenceData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class ReferenceDataCacheTest extends TestCase
{
    use CreatesBarangayFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        // Exercise after-commit invalidation without an outer test transaction.
        $this->artisan('migrate:fresh');
    }

    public function test_reference_reads_are_cached_and_committed_edits_invalidate_them(): void
    {
        $barangay = $this->createBarangay('Original', 'REFERENCE-CACHE');
        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'from "barangays"')) {
                $queries++;
            }
        });
        $this->assertSame('Original', ReferenceData::barangay($barangay->id)['name']);
        $this->assertSame('Original', ReferenceData::barangay($barangay->id)['name']);
        $this->assertSame(1, $queries);

        DB::transaction(fn () => $barangay->update(['name' => 'Updated']));
        $this->assertSame('Updated', ReferenceData::barangay($barangay->id)['name']);
        $this->assertSame(2, $queries);
    }

    public function test_rolled_back_reference_data_is_not_cached(): void
    {
        $barangay = $this->createBarangay('Original', 'REFERENCE-CACHE');
        DB::beginTransaction();
        try {
            $barangay->update(['name' => 'Uncommitted']);
            $this->assertSame('Uncommitted', ReferenceData::barangay($barangay->id)['name']);
        } finally {
            DB::rollBack();
        }
        $this->assertSame('Original', ReferenceData::barangay($barangay->id)['name']);
    }

    public function test_catalog_and_signatory_edits_invalidate_only_the_affected_barangay(): void
    {
        $barangay = $this->createBarangay('Catalog', 'REFERENCE-CACHE');
        $user = $this->createStaff($barangay, 'clerk');
        $certificate = Certificate::create([
            'barangay_id' => $barangay->id, 'code' => 'CACHE', 'name' => 'Original',
            'fee' => 0, 'is_active' => true,
        ]);
        $position = OrgChartPosition::create([
            'barangay_id' => $barangay->id, 'title' => 'Captain', 'status' => 'published', 'created_by' => $user->id,
        ]);
        foreach (['clerk:certificate-options:', 'resident:certificate-options:', 'clerk:certificate-signatories:'] as $prefix) {
            Cache::put($prefix.$barangay->id, ['old'], 300);
            Cache::put($prefix.'9999', ['other'], 300);
        }
        DB::transaction(fn () => $certificate->update(['name' => 'Updated']));
        $this->assertNull(Cache::get('clerk:certificate-options:'.$barangay->id));
        $this->assertNull(Cache::get('resident:certificate-options:'.$barangay->id));
        Cache::put('clerk:certificate-signatories:'.$barangay->id, ['old'], 300);
        DB::transaction(fn () => $position->update(['assigned_person' => 'New Captain']));
        $this->assertNull(Cache::get('clerk:certificate-signatories:'.$barangay->id));
        $this->assertSame(['other'], Cache::get('clerk:certificate-options:9999'));
    }
}
