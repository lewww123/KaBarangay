<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_attempts', function (Blueprint $table) {
            $table->id();

            /*
            |--------------------------------------------------------------------------
            | Associations
            |--------------------------------------------------------------------------
            |
            | All three are nullable because an attempt may be unresolved (an
            | unknown username, an inactive account, or a barangay that could
            | not be determined). nullOnDelete keeps the security record if the
            | barangay, staff account, or resident is later removed, so login
            | history is never cascaded away.
            |
            */

            $table->foreignId('barangay_id')
                ->nullable()
                ->constrained('barangays')
                ->nullOnDelete();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('resident_id')
                ->nullable()
                ->constrained('residents')
                ->nullOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Attempt Details
            |--------------------------------------------------------------------------
            |
            | actor_type: staff | resident
            | channel:    department | staff | resident
            | status:     success | failed | rate_limited
            |
            */

            $table->string('actor_type', 20);

            $table->string('channel', 30);

            // HMAC-SHA256 of the normalized attempted identifier. The raw
            // identifier is never persisted, and nothing here stores passwords,
            // password hashes, tokens, session data, or device fingerprints.
            $table->string('identifier_hash', 64)->nullable();

            $table->string('status', 20);

            // IPv4/IPv6 string form, matching audit_logs.
            $table->string('ip_address', 45)->nullable();

            $table->timestamp('attempted_at');

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */

            $table->index(
                ['barangay_id', 'attempted_at'],
                'login_attempts_barangay_attempted_idx'
            );

            $table->index(
                ['identifier_hash', 'attempted_at'],
                'login_attempts_identifier_attempted_idx'
            );

            $table->index(
                ['status', 'attempted_at'],
                'login_attempts_status_attempted_idx'
            );

            $table->index(
                ['ip_address', 'attempted_at'],
                'login_attempts_ip_attempted_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_attempts');
    }
};
