<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sector_applications', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('tracking_number', 40)->unique();

            $table->foreignId('barangay_id')
                ->constrained('barangays')
                ->cascadeOnDelete();

            $table->foreignId('resident_id')
                ->constrained('residents')
                ->cascadeOnDelete();

            $table->foreignId('sector_id')
                ->constrained('sectors')
                ->restrictOnDelete();

            // 'staff_assisted' or 'self_service'
            $table->string('submission_type', 30);

            // User ID when staff_assisted (null when self-service)
            $table->foreignId('submitted_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Status: pending_staff_review, under_admin_review, requires_correction, approved, rejected
            $table->string('status', 40)->default('pending_staff_review');

            // Sector-specific metadata (e.g. osca_id, disability_type, assistive_device)
            $table->json('details')->nullable();

            // Staff Completeness Review
            $table->foreignId('staff_reviewed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('staff_reviewed_at')->nullable();
            $table->text('staff_review_notes')->nullable();
            $table->json('staff_checklist')->nullable();
            $table->boolean('staff_checklist_completed')->default(false);

            // Admin Decision
            $table->foreignId('admin_decided_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('admin_decided_at')->nullable();
            $table->text('admin_decision_notes')->nullable();

            // Correction Feedback
            $table->text('correction_notes')->nullable();

            $table->timestamps();

            // Efficient query indexes
            $table->index(['barangay_id', 'sector_id', 'status'], 'sector_apps_b_s_status_idx');
            $table->index(['resident_id', 'sector_id'], 'sector_apps_res_sec_idx');
            $table->index(['barangay_id', 'status'], 'sector_apps_b_status_idx');
        });

        Schema::create('sector_application_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sector_application_id')
                ->constrained('sector_applications')
                ->cascadeOnDelete();

            $table->string('document_type', 50);
            $table->string('title');

            $table->string('disk', 40)->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('file_size');
            $table->string('sha256', 64)->nullable();

            $table->string('verification_status', 30)->default('pending');
            $table->text('review_notes')->nullable();

            $table->timestamps();

            $table->index(
                ['sector_application_id', 'document_type'],
                'sec_app_docs_app_type_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sector_application_documents');
        Schema::dropIfExists('sector_applications');
    }
};
