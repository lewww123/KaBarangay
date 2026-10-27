<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resident_registration_correction_cycles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('resident_registration_id')
                ->constrained('resident_registrations', 'id', 'resident_reg_correction_registration_fk')
                ->restrictOnDelete();

            $table->unsignedInteger('cycle_number');
            $table->text('instructions');
            $table->json('field_targets');
            $table->json('document_targets');

            // Snapshot only approved scalar registration fields; never credentials or QR tokens.
            $table->json('prior_field_snapshot')->nullable();

            $table->foreignId('reviewer_id')
                ->constrained('users', 'id', 'resident_reg_correction_reviewer_fk')
                ->restrictOnDelete();

            $table->timestamp('requested_at');
            $table->timestamp('resubmitted_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['resident_registration_id', 'cycle_number'],
                'resident_reg_correction_cycle_unique'
            );
            $table->index(
                ['resident_registration_id', 'requested_at'],
                'resident_reg_correction_requested_index'
            );
            $table->index(
                'reviewer_id',
                'resident_reg_correction_reviewer_index'
            );
        });
    }

    public function down(): void
    {
        if (DB::table('resident_registration_correction_cycles')->exists()) {
            throw new RuntimeException(
                'Refusing to drop resident registration correction history while cycle records are present.'
            );
        }

        Schema::dropIfExists('resident_registration_correction_cycles');
    }
};
