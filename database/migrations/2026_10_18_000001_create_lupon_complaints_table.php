<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lupon_complaints', function (Blueprint $table) {
            $table->id();

            $table->foreignId('barangay_id')
                ->constrained('barangays')
                ->cascadeOnDelete();

            $table->foreignId('resident_id')
                ->constrained('residents')
                ->cascadeOnDelete();

            $table->string('reference', 50)->unique();

            // Respondent details are free-text snapshots captured at intake.
            // They are intentionally not linked to a residents row because the
            // respondent is not required to be a registered resident.
            $table->string('respondent_name');
            $table->string('respondent_resident_status', 20);
            $table->string('respondent_address')->nullable();
            $table->string('respondent_contact')->nullable();

            $table->string('complaint_type', 100);

            $table->date('incident_date');

            // Stored as HH:MM exactly as submitted by the form input.
            $table->string('incident_time', 5)->nullable();

            $table->string('incident_location');
            $table->text('narrative');
            $table->text('requested_action');

            $table->string('status', 50)
                ->default('Submitted');

            $table->text('lupon_note')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('rejected_at')->nullable();

            $table->timestamps();

            $table->index(
                ['barangay_id', 'status'],
                'lupon_complaints_barangay_status_idx'
            );

            $table->index(
                ['resident_id', 'status'],
                'lupon_complaints_resident_status_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lupon_complaints');
    }
};
