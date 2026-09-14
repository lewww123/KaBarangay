<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_sectors', function (Blueprint $table) {
            $table->id();

            $table->foreignId('resident_registration_id')
                ->constrained('resident_registrations')
                ->cascadeOnDelete();

            $table->foreignId('sector_id')
                ->constrained('sectors')
                ->restrictOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Sector Verification
            |--------------------------------------------------------------------------
            |
            | pending
            | verified
            | needs_correction
            | rejected
            |
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

            $table->unique([
                'resident_registration_id',
                'sector_id',
            ]);

            $table->index([
                'sector_id',
                'verification_status',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_sectors');
    }
};