<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const NORMALIZED_INDEX = 'lupon_cases_barangay_normalized_or_unique';

    /**
     * Add an additive normalized unique index for Lupon OR numbers.
     *
     * Approved rule: OR numbers are unique within the same barangay (not globally),
     * normalized by trimming surrounding whitespace and converting letters to uppercase.
     * Internal whitespace is not modified. Multiple NULL OR numbers remain allowed
     * (waived/unpaid). Existing records are never modified by this migration.
     *
     * Migration 08's raw index/preflight remains unchanged because its deployment
     * status in staging/production is unknown. This corrective migration uses a new
     * index name and fails safely when normalized duplicates already exist.
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        $normalized = $this->normalizedExpression($driver);

        // Fail safely if normalized duplicate non-NULL OR numbers already exist.
        // This preflight uses the same canonical normalization as the index.
        $duplicates = DB::table('lupon_cases')
            ->select('barangay_id', DB::raw("{$normalized} as normalized_or"), DB::raw('count(*) as count'))
            ->whereNotNull('or_number')
            ->groupBy('barangay_id', DB::raw($normalized))
            ->havingRaw('count(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot create normalized unique index on lupon_cases: normalized duplicate non-NULL OR numbers exist within the same barangay. Resolve duplicates through an approved business process before migrating.'
            );
        }

        $sql = match ($driver) {
            'pgsql', 'sqlite' => sprintf(
                'CREATE UNIQUE INDEX IF NOT EXISTS %s ON lupon_cases (barangay_id, %s) WHERE or_number IS NOT NULL',
                self::NORMALIZED_INDEX,
                $normalized
            ),
            default => throw new RuntimeException(
                "Unsupported database driver [{$driver}] for normalized Lupon OR uniqueness index. Use PostgreSQL or SQLite, or add an explicit driver-safe expression."
            ),
        };

        DB::statement($sql);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        $sql = match ($driver) {
            'pgsql', 'sqlite' => 'DROP INDEX IF EXISTS '.self::NORMALIZED_INDEX,
            default => 'DROP INDEX IF EXISTS '.self::NORMALIZED_INDEX,
        };

        DB::statement($sql);
    }

    /**
     * Canonical normalized OR expression, matching PHP strtoupper(trim(...)) intent.
     *
     * PostgreSQL: trims leading/trailing whitespace broadly via POSIX regex.
     * SQLite: TRIM removes surrounding spaces (practical OR receipt values in tests).
     */
    private function normalizedExpression(string $driver): string
    {
        return match ($driver) {
            'pgsql' => "UPPER(REGEXP_REPLACE(or_number, '^\\s+|\\s+$', '', 'g'))",
            'sqlite' => 'UPPER(TRIM(or_number))',
            default => "UPPER(TRIM(or_number))",
        };
    }
};
