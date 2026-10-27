<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('citizen_charter_services', function (Blueprint $table) {
            $table->id();

            $table->foreignId('barangay_id')
                ->constrained('barangays')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('current_version_id')->nullable();

            $table->string('title');
            $table->string('category', 100)->nullable();
            $table->text('description')->nullable();
            $table->integer('display_order')->default(0);

            $table->string('status', 20)->default('draft');
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(
                ['barangay_id', 'is_published', 'archived_at', 'display_order'],
                'cc_services_listing_idx'
            );

            $table->index(
                ['barangay_id', 'created_at'],
                'cc_services_created_idx'
            );
        });

        Schema::create('citizen_charter_versions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('service_id')
                ->constrained('citizen_charter_services')
                ->cascadeOnDelete();

            $table->foreignId('barangay_id')
                ->constrained('barangays')
                ->cascadeOnDelete();

            $table->unsignedInteger('version_number')->default(1);

            $table->string('disk', 40)->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 120)->default('application/pdf');
            $table->unsignedBigInteger('file_size');
            $table->string('sha256', 64)->nullable();

            $table->foreignId('uploaded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();

            $table->timestamps();

            $table->index(
                ['service_id', 'version_number'],
                'cc_versions_service_ver_idx'
            );

            $table->index(
                ['barangay_id', 'status'],
                'cc_versions_brgy_status_idx'
            );
        });

        Schema::table('citizen_charter_services', function (Blueprint $table) {
            $table->foreign('current_version_id', 'cc_services_current_version_fk')
                ->references('id')
                ->on('citizen_charter_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('citizen_charter_services', function (Blueprint $table) {
            $table->dropForeign('cc_services_current_version_fk');
        });

        Schema::dropIfExists('citizen_charter_versions');
        Schema::dropIfExists('citizen_charter_services');
    }
};
