<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lupon_complaints', function (Blueprint $table) {
            $table->index(['barangay_id', 'status', 'created_at'], 'lupon_complaints_queue_idx');
            $table->index(['resident_id', 'barangay_id', 'created_at'], 'lupon_complaints_resident_idx');
        });

        Schema::table('lupon_cases', function (Blueprint $table) {
            $table->index(['barangay_id', 'status', 'filed_date'], 'lupon_cases_status_filed_idx');
            $table->index(['barangay_id', 'phase', 'filed_date'], 'lupon_cases_phase_filed_idx');
            $table->index(['assigned_to', 'status', 'created_at'], 'lupon_cases_assignee_status_idx');
        });

        Schema::table('lupon_hearings', function (Blueprint $table) {
            $table->index(['barangay_id', 'status', 'hearing_date'], 'lupon_hearings_status_date_idx');
            $table->index(['barangay_id', 'phase', 'hearing_date'], 'lupon_hearings_phase_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('lupon_hearings', function (Blueprint $table) {
            $table->dropIndex('lupon_hearings_status_date_idx');
            $table->dropIndex('lupon_hearings_phase_date_idx');
        });

        Schema::table('lupon_cases', function (Blueprint $table) {
            $table->dropIndex('lupon_cases_status_filed_idx');
            $table->dropIndex('lupon_cases_phase_filed_idx');
            $table->dropIndex('lupon_cases_assignee_status_idx');
        });

        Schema::table('lupon_complaints', function (Blueprint $table) {
            $table->dropIndex('lupon_complaints_queue_idx');
            $table->dropIndex('lupon_complaints_resident_idx');
        });
    }
};
