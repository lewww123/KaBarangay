<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suspicious_login_alerts', function (Blueprint $table) {
            $table->id();

            /*
            |--------------------------------------------------------------------------
            | Associations
            |--------------------------------------------------------------------------
            |
            | Nullable because a suspicious pattern may be detected for an
            | unresolved attempt (unknown username or a barangay that could not
            | be determined). nullOnDelete keeps the security alert if a
            | barangay is later removed, so alert history is never cascaded
            | away. A null barangay_id stays out of every ordinary
            | barangay-admin view.
            |
            */

            $table->foreignId('barangay_id')
                ->nullable()
                ->constrained('barangays')
                ->nullOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Alert Details
            |--------------------------------------------------------------------------
            |
            | channel:    department | staff | resident
            | alert_type: failed_login_threshold
            |
            | identifier_hash is the same HMAC-SHA256 digest stored on
            | login_attempts. The raw identifier is never persisted, and nothing
            | here stores passwords, password hashes, tokens, session data, or
            | device fingerprints.
            |
            */

            $table->string('channel', 30);

            $table->string('identifier_hash', 64)->nullable();

            $table->string('alert_type', 30);

            $table->unsignedInteger('attempt_count');

            $table->unsignedInteger('window_seconds');

            $table->timestamp('first_attempt_at');

            $table->timestamp('last_attempt_at');

            // IPv4/IPv6 string form, matching login_attempts and audit_logs.
            $table->string('ip_address', 45)->nullable();

            // Database-enforced deduplication identity: the unique index below
            // is the concurrency guard for atomic insert-or-ignore. Concurrent
            // crossings in the same bucket resolve to one row.
            $table->string('dedup_key', 64);

            // created_at only: no updated_at column (append-only records).
            $table->timestamp('created_at')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */

            $table->unique('dedup_key', 'suspicious_login_alerts_dedup_unique');

            $table->index(
                ['barangay_id', 'created_at'],
                'suspicious_login_alerts_barangay_created_idx'
            );

            $table->index(
                ['identifier_hash', 'created_at'],
                'suspicious_login_alerts_identifier_created_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suspicious_login_alerts');
    }
};
