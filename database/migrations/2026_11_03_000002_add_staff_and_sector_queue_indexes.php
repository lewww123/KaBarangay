<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sector_applications', function (Blueprint $table) {
            $table->index(['barangay_id', 'status', 'created_at', 'id'], 'sector_apps_status_latest_idx');
        });
        Schema::table('staff_assignments', function (Blueprint $table) {
            $table->index(['barangay_id', 'created_at'], 'staff_assignments_barangay_latest_idx');
        });
    }

    public function down(): void
    {
        Schema::table('staff_assignments', fn (Blueprint $table) => $table->dropIndex('staff_assignments_barangay_latest_idx'));
        Schema::table('sector_applications', function (Blueprint $table) {
            $table->dropIndex('sector_apps_status_latest_idx');
        });
    }
};
