<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Fail safely if duplicate non-NULL OR numbers already exist within the same barangay.
        $duplicates = DB::table('lupon_cases')
            ->select('barangay_id', 'or_number', DB::raw('count(*) as count'))
            ->whereNotNull('or_number')
            ->groupBy('barangay_id', 'or_number')
            ->havingRaw('count(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot create unique index on lupon_cases: duplicate non-NULL OR numbers exist within the same barangay.'
            );
        }

        // Additive partial unique index for PostgreSQL and SQLite.
        // Multiple NULL or_number values are permitted (unpaid, waived),
        // while duplicate issued receipts within the same barangay are strictly prohibited.
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS lupon_cases_barangay_or_number_unique ON lupon_cases (barangay_id, or_number) WHERE or_number IS NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS lupon_cases_barangay_or_number_unique');
    }
};
