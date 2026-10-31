<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Programs are event announcements in KaBarangay, not a separate table.
        Schema::table('announcements', function (Blueprint $table) {
            $table->enum('program_status', ['draft', 'ongoing', 'done'])->nullable();
            $table->index(['barangay_id', 'program_status', 'created_at'], 'announcements_program_lifecycle_idx');
        });
        DB::table('announcements')
            ->where(fn ($query) => $query->where('category', 'Event')->orWhere('requires_attendance', true))
            ->update(['program_status' => DB::raw("CASE WHEN completed_at IS NOT NULL THEN 'done' WHEN approval_status = 'approved' AND is_published = true THEN 'ongoing' ELSE 'draft' END")]);
        DB::table('resident_notification_outbox')->where('type', 'claim_invitation')->update(['type' => 'registration_invitation']);
    }

    public function down(): void
    {
        DB::table('resident_notification_outbox')->where('type', 'registration_invitation')->update(['type' => 'claim_invitation']);
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropIndex('announcements_program_lifecycle_idx');
            $table->dropColumn('program_status');
        });
    }
};
