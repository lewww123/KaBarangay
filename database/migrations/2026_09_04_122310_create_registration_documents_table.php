<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('resident_registration_id')
                ->constrained('resident_registrations')
                ->cascadeOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Sector
            |--------------------------------------------------------------------------
            |
            | Null for:
            | - valid_id
            | - live_identity_photo
            |
            | Set for:
            | - sector_proof (Senior/PWD)
            |
            */

            $table->foreignId('sector_id')
                ->nullable()
                ->constrained('sectors')
                ->restrictOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Document Type
            |--------------------------------------------------------------------------
            |
            | Expected values:
            |
            | valid_id
            | live_identity_photo
            | sector_proof
            |
            */

            $table->string('document_type', 50);

            /*
            |--------------------------------------------------------------------------
            | Storage Information
            |--------------------------------------------------------------------------
            */

            $table->string('disk')
                ->default('local');

            $table->string('path');

            $table->string('original_name')
                ->nullable();

            $table->string('mime_type', 100);

            $table->unsignedBigInteger('file_size');

            /*
            |--------------------------------------------------------------------------
            | File Integrity
            |--------------------------------------------------------------------------
            */

            $table->string('sha256', 64)
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Document Verification
            |--------------------------------------------------------------------------
            */

            $table->string('verification_status', 30)
                ->default('pending');

            $table->text('review_notes')
                ->nullable();

            $table->foreignId('verified_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('verified_at')
                ->nullable();

            $table->timestamps();

            $table->index([
                'resident_registration_id',
                'document_type',
            ]);

            $table->index([
                'sector_id',
                'document_type',
            ]);

            $table->index('verification_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_documents');
    }
};