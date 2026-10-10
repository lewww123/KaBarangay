<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('residents', function (Blueprint $table) {
            $table->id();

            /*
            |--------------------------------------------------------------------------
            | Public / Source References
            |--------------------------------------------------------------------------
            */

            $table->uuid('public_id')->unique();

            // One approved registration can only create one permanent resident.
            $table->foreignId('resident_registration_id')
                ->unique()
                ->constrained('resident_registrations')
                ->restrictOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Barangay Assignment
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
            | Official Resident Number
            |--------------------------------------------------------------------------
            |
            | Examples:
            | BSJ-RES-000001
            | BSM-RES-000001
            |
            */

            $table->string('resident_number', 50)->unique();

            /*
            |--------------------------------------------------------------------------
            | Personal Information
            |--------------------------------------------------------------------------
            */

            $table->string('first_name', 100);

            $table->string('middle_name', 100)
                ->nullable();

            $table->string('last_name', 100);

            $table->string('suffix', 30)
                ->nullable();

            $table->date('birthdate');

            $table->string('sex', 30);

            $table->string('civil_status', 50);

            /*
            |--------------------------------------------------------------------------
            | Address
            |--------------------------------------------------------------------------
            */

            $table->text('address_line');

            /*
            |--------------------------------------------------------------------------
            | Contact Information
            |--------------------------------------------------------------------------
            */

            $table->string('mobile', 30);

            $table->string('email', 255)
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Resident Status
            |--------------------------------------------------------------------------
            |
            | active
            | inactive
            |
            */

            $table->string('status', 30)
                ->default('active');

            $table->timestamp('approved_at')
                ->nullable();

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Indexes / Uniqueness
            |--------------------------------------------------------------------------
            |
            | Login will include the resident's barangay, so mobile/email
            | should be unique within the same barangay.
            |
            */

            $table->unique(
                ['barangay_id', 'mobile'],
                'residents_barangay_mobile_unique'
            );

            $table->unique(
                ['barangay_id', 'email'],
                'residents_barangay_email_unique'
            );

            $table->index(
                ['barangay_id', 'status'],
                'residents_barangay_status_index'
            );

            $table->index(
                ['last_name', 'first_name'],
                'residents_name_index'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('residents');
    }
};