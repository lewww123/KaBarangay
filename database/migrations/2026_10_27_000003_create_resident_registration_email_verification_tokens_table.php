<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resident_registration_email_verification_tokens', function (Blueprint $table) {
            $table->id();

            $table->foreignId('resident_registration_id')
                ->constrained('resident_registrations', 'id', 'resident_reg_email_token_registration_fk')
                ->cascadeOnDelete();

            // SHA-256 digest only; plaintext tokens are delivered once and never persisted.
            $table->char('token_hash', 64)
                ->unique('resident_reg_email_token_hash_unique');
            $table->string('email_snapshot', 255);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->unsignedInteger('send_count')->default(0);
            $table->timestamps();

            $table->index(
                ['resident_registration_id', 'expires_at'],
                'resident_reg_email_tokens_expiry_index'
            );
            $table->index(
                ['resident_registration_id', 'consumed_at'],
                'resident_reg_email_tokens_consumed_index'
            );
        });
    }

    public function down(): void
    {
        if (DB::table('resident_registration_email_verification_tokens')->exists()) {
            throw new RuntimeException(
                'Refusing to drop registration email verification history while token records are present.'
            );
        }

        Schema::dropIfExists('resident_registration_email_verification_tokens');
    }
};
