<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->index(
                ['barangay_id', 'is_published', 'approval_status', 'event_start_at', 'created_at'],
                'announcements_dashboard_latest_idx',
            );
        });

        Schema::table('resident_registrations', function (Blueprint $table) {
            $table->index(
                ['barangay_id', 'status', 'submitted_at', 'id'],
                'registrations_dashboard_queue_idx',
            );
            $table->index(
                ['barangay_id', 'purok_id', 'status', 'submitted_at', 'id'],
                'registrations_purok_queue_idx',
            );
        });

        Schema::table('certificate_requests', function (Blueprint $table) {
            $table->index(
                ['barangay_id', 'created_at'],
                'certificate_requests_dashboard_latest_idx',
            );
            $table->index(
                ['resident_id', 'barangay_id', 'created_at', 'id'],
                'certificate_requests_resident_history_idx',
            );
        });

        Schema::table('sector_applications', function (Blueprint $table) {
            $table->index(
                ['barangay_id', 'created_at', 'id'],
                'sector_apps_barangay_latest_idx',
            );
            $table->index(
                ['resident_id', 'created_at', 'id'],
                'sector_apps_resident_history_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('sector_applications', function (Blueprint $table) {
            $table->dropIndex('sector_apps_resident_history_idx');
            $table->dropIndex('sector_apps_barangay_latest_idx');
        });

        Schema::table('certificate_requests', function (Blueprint $table) {
            $table->dropIndex('certificate_requests_resident_history_idx');
            $table->dropIndex('certificate_requests_dashboard_latest_idx');
        });

        Schema::table('resident_registrations', function (Blueprint $table) {
            $table->dropIndex('registrations_purok_queue_idx');
            $table->dropIndex('registrations_dashboard_queue_idx');
        });

        Schema::table('announcements', function (Blueprint $table) {
            $table->dropIndex('announcements_dashboard_latest_idx');
        });
    }
};
