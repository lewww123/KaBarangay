<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resident_claims', function (Blueprint $table) {
            $table->id();

            $table->uuid('public_id')->unique();

            $table->foreignId('resident_id')
                ->constrained('residents')
                ->cascadeOnDelete();

            $table->foreignId('barangay_id')
                ->constrained('barangays')
                ->restrictOnDelete();

            $table->foreignId('purok_id')
                ->constrained('puroks')
                ->restrictOnDelete();

            $table->foreignId('qr_credential_id')
                ->nullable()
                ->constrained('resident_qr_credentials')
                ->nullOnDelete();

            // Snapshot of the Resident email the claimant proved access to.
            $table->string('email', 255);

            // Stored as a hash; the account is only created after approval.
            $table->string('password');

            $table->string('status', 30)
                ->default('pending');

            $table->timestamp('identity_checked_at')
                ->nullable();

            $table->string('identity_method', 50)
                ->nullable();

            $table->text('review_notes')
                ->nullable();

            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('reviewed_at')
                ->nullable();

            $table->timestamps();

            $table->index(
                ['barangay_id', 'status'],
                'resident_claims_barangay_status_index'
            );

            $table->index(
                ['resident_id', 'status'],
                'resident_claims_resident_status_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resident_claims');
    }
};
