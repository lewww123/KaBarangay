<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('disclosure_periods')) {
            throw new RuntimeException(
                'Cannot add disclosure period barangay ownership: the disclosure_periods table does not exist.'
            );
        }

        $existingPeriods = DB::table('disclosure_periods')->count();

        if ($existingPeriods > 0) {
            throw new RuntimeException(
                "Cannot add disclosure period barangay ownership: {$existingPeriods} existing row(s) must be assigned to a barangay before this migration. No backfill policy is defined."
            );
        }

        Schema::table('disclosure_periods', function (Blueprint $table) {
            $table->foreignId('barangay_id')->after('id')->constrained('barangays')->restrictOnDelete();
            $table->dropIndex('disclosure_periods_year_month_idx');
            $table->unique(['barangay_id', 'year', 'month'], 'disclosure_periods_tenant_period_idx');
            $table->index(['barangay_id', 'is_published'], 'disclosure_periods_tenant_published_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('disclosure_periods') || ! Schema::hasColumn('disclosure_periods', 'barangay_id')) {
            return;
        }

        $ownedPeriods = DB::table('disclosure_periods')->count();

        if ($ownedPeriods > 0) {
            throw new RuntimeException(
                "Cannot remove disclosure period barangay ownership: {$ownedPeriods} assigned row(s) would lose ownership. Reassign or delete those periods before rolling back this migration."
            );
        }

        Schema::table('disclosure_periods', function (Blueprint $table) {
            $table->dropIndex('disclosure_periods_tenant_published_idx');
            $table->dropUnique('disclosure_periods_tenant_period_idx');
            $table->index(['year', 'month'], 'disclosure_periods_year_month_idx');
            $table->dropForeign(['barangay_id']);
            $table->dropColumn('barangay_id');
        });
    }
};
