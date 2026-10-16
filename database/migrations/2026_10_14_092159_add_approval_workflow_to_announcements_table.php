<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add approval columns to announcements table
        Schema::table('announcements', function (Blueprint $table) {
            if (!Schema::hasColumn('announcements', 'approval_status')) {
                $table->string('approval_status', 30)->default('pending')->after('is_published'); 
                // 'pending', 'approved', 'rejected'
            }
            if (!Schema::hasColumn('announcements', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('approval_status');
            }
            if (!Schema::hasColumn('announcements', 'reviewed_by')) {
                $table->foreignId('reviewed_by')->nullable()->after('rejection_reason')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('announcements', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            }

            $table->index(['barangay_id', 'approval_status', 'target_audience']);
        });

        // 2. Audit Trail Table for Approval History
        if (!Schema::hasTable('announcement_approval_logs')) {
            Schema::create('announcement_approval_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('barangay_id')->constrained('barangays')->cascadeOnDelete();
                $table->foreignId('announcement_id')->constrained('announcements')->cascadeOnDelete();
                $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete(); // Sino ang nag-action (Clerk/Admin)
                $table->string('action', 50); // 'created', 'updated', 'resubmitted', 'approved', 'rejected'
                $table->string('previous_status', 30)->nullable();
                $table->string('new_status', 30);
                $table->text('notes')->nullable(); // Rejection reason o edit summary
                $table->timestamps();

                $table->index(['announcement_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_approval_logs');

        Schema::table('announcements', function (Blueprint $table) {
            $table->dropIndex(['barangay_id', 'approval_status', 'target_audience']);
            $table->dropColumn(['approval_status', 'rejection_reason', 'reviewed_by', 'reviewed_at']);
        });
    }
};