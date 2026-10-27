<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lupon_cases', function (Blueprint $table) {
            $table->id();

            $table->foreignId('barangay_id')
                ->constrained('barangays')
                ->cascadeOnDelete();

            $table->foreignId('complaint_id')
                ->unique()
                ->constrained('lupon_complaints')
                ->cascadeOnDelete();

            $table->string('case_number', 50)->unique();
            $table->string('title');
            $table->string('case_type', 100);

            // Phase: Kapitan, Lupon, Closed
            $table->string('phase', 50)->default('Kapitan');

            // Status: Active, Under Mediation, Under Conciliation, Settled, Unresolved, Dismissed
            $table->string('status', 50)->default('Active');

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('assigned_to')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->date('filed_date');
            $table->timestamp('promoted_at')->nullable();
            $table->timestamps();

            $table->index(['barangay_id', 'status'], 'lupon_cases_barangay_status_idx');
            $table->index(['barangay_id', 'phase'], 'lupon_cases_barangay_phase_idx');
        });

        Schema::create('lupon_case_parties', function (Blueprint $table) {
            $table->id();

            $table->foreignId('case_id')
                ->constrained('lupon_cases')
                ->cascadeOnDelete();

            // complainant or respondent
            $table->string('party_type', 20);

            // Nullable: party may be non-resident
            $table->foreignId('resident_id')
                ->nullable()
                ->constrained('residents')
                ->nullOnDelete();

            $table->string('name');
            $table->boolean('is_resident')->default(true);
            $table->string('address')->nullable();
            $table->string('contact_number')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['case_id', 'party_type'], 'lupon_case_parties_case_type_idx');
            $table->index(['resident_id'], 'lupon_case_parties_resident_idx');
        });

        Schema::create('lupon_hearings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('case_id')
                ->constrained('lupon_cases')
                ->cascadeOnDelete();

            $table->foreignId('barangay_id')
                ->constrained('barangays')
                ->cascadeOnDelete();

            $table->unsignedInteger('hearing_number')->default(1);
            $table->string('phase', 50)->default('Kapitan'); // Kapitan or Lupon

            $table->date('hearing_date');
            $table->string('hearing_time', 20)->nullable();
            $table->string('venue')->default('Barangay Hall');

            // Scheduled, Held, Cancelled, Rescheduled
            $table->string('status', 50)->default('Scheduled');

            // Ongoing, Settled, Unresolved, Reset, Reset to Next Hearing
            $table->string('outcome', 50)->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('officer_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['case_id', 'hearing_number'], 'lupon_hearings_case_num_idx');
            $table->index(['barangay_id', 'hearing_date'], 'lupon_hearings_barangay_date_idx');
        });

        Schema::create('lupon_settlements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('case_id')
                ->unique()
                ->constrained('lupon_cases')
                ->cascadeOnDelete();

            $table->foreignId('barangay_id')
                ->constrained('barangays')
                ->cascadeOnDelete();

            // Amicable Settlement, Arbitration Award, Resolution
            $table->string('settlement_type', 50)->default('Amicable Settlement');
            $table->date('settlement_date');
            $table->text('terms')->nullable();
            $table->string('status', 50)->default('Active'); // Active, Repudiated, Executed

            $table->foreignId('recorded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lupon_settlements');
        Schema::dropIfExists('lupon_hearings');
        Schema::dropIfExists('lupon_case_parties');
        Schema::dropIfExists('lupon_cases');
    }
};
