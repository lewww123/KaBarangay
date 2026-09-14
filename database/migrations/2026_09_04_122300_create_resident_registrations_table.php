<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resident_registrations', function (Blueprint $table) {
            $table->id();

            /*
            |--------------------------------------------------------------------------
            | Public Registration Identifier
            |--------------------------------------------------------------------------
            |
            | This will be used instead of exposing the sequential database ID
            | in public-facing registration status/correction URLs.
            |
            */

            $table->uuid('public_id')
                ->unique();

            /*
            |--------------------------------------------------------------------------
            | Barangay / Purok
            |--------------------------------------------------------------------------
            */

            $table->foreignId('barangay_id')
                ->constrained('barangays')
                ->restrictOnDelete();

            $table->foreignId('purok_id')
                ->constrained('puroks')
                ->restrictOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Personal Information
            |--------------------------------------------------------------------------
            */

            $table->string('first_name');

            $table->string('middle_name')
                ->nullable();

            $table->string('last_name');

            $table->string('suffix')
                ->nullable();

            $table->date('birthdate');

            $table->string('sex', 30);

            $table->string('civil_status', 30);

            $table->string('address_line');

            /*
            |--------------------------------------------------------------------------
            | Contact Information
            |--------------------------------------------------------------------------
            */

            $table->string('mobile', 30);

            $table->string('email')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Account Credentials
            |--------------------------------------------------------------------------
            |
            | This password MUST be hashed before it reaches this table.
            | We will never store plain-text passwords.
            |
            */

            $table->string('password');

            /*
            |--------------------------------------------------------------------------
            | Registration Status
            |--------------------------------------------------------------------------
            |
            | Expected application values:
            |
            | pending
            | needs_correction
            | approved
            | rejected
            |
            */

            $table->string('status', 30)
                ->default('pending');

            $table->text('review_notes')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Review Information
            |--------------------------------------------------------------------------
            */

            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('submitted_at')
                ->nullable();

            $table->timestamp('reviewed_at')
                ->nullable();

            $table->timestamp('last_resubmitted_at')
                ->nullable();

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Useful indexes
            |--------------------------------------------------------------------------
            */

            $table->index([
                'barangay_id',
                'status',
            ]);

            $table->index([
                'barangay_id',
                'purok_id',
            ]);

            $table->index('mobile');
            $table->index('email');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resident_registrations');
    }
};