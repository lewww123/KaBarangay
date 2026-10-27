<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resident_email_verification_tokens', function (Blueprint $table) {
            $table->id();

            $table->foreignId('resident_id')
                ->constrained('residents')
                ->cascadeOnDelete();

            $table->foreignId('resident_claim_id')
                ->nullable()
                ->constrained('resident_claims')
                ->cascadeOnDelete();

            $table->string('email', 255);

            // SHA-256 hex digest. The plaintext token is only sent by email.
            $table->string('token_hash', 64)
                ->unique();

            $table->timestamp('expires_at');

            $table->timestamp('consumed_at')
                ->nullable();

            $table->timestamps();

            $table->index(
                ['resident_id', 'email'],
                'resident_email_verifications_resident_email_index'
            );

            $table->index(
                ['resident_id', 'expires_at'],
                'resident_email_verifications_resident_expiry_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resident_email_verification_tokens');
    }
};
