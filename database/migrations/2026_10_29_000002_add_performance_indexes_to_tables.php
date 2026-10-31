<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->index(['id', 'barangay_id'], 'users_id_barangay_perf_idx');
        });
        Schema::table('residents', function (Blueprint $table) {
            $table->index(['barangay_id', 'status', 'source'], 'residents_barangay_status_source_perf_idx');
        });
        Schema::table('resident_registrations', function (Blueprint $table) {
            $table->index(['barangay_id', 'status', 'matched_resident_id'], 'registrations_barangay_status_matched_perf_idx');
        });
        Schema::table('resident_claims', function (Blueprint $table) {
            $table->index(['barangay_id', 'status', 'resident_id'], 'claims_barangay_status_resident_perf_idx');
        });
        Schema::table('resident_accounts', function (Blueprint $table) {
            $table->index(['resident_id', 'is_active'], 'resident_accounts_resident_active_perf_idx');
        });
    }

    public function down(): void
    {
        Schema::table('resident_accounts', function (Blueprint $table) {
            $table->dropIndex('resident_accounts_resident_active_perf_idx');
        });
        Schema::table('resident_claims', function (Blueprint $table) {
            $table->dropIndex('claims_barangay_status_resident_perf_idx');
        });
        Schema::table('resident_registrations', function (Blueprint $table) {
            $table->dropIndex('registrations_barangay_status_matched_perf_idx');
        });
        Schema::table('residents', function (Blueprint $table) {
            $table->dropIndex('residents_barangay_status_source_perf_idx');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_id_barangay_perf_idx');
        });
    }
};
