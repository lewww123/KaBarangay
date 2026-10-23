<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificate_issuances', function (Blueprint $table) {
            $table->id();

            /*
            |--------------------------------------------------------------------------
            | Barangay (Issuing)
            |--------------------------------------------------------------------------
            |
            | restrictOnDelete: a barangay that has issued certificates cannot be
            | hard-deleted while its issuance history exists. Issuance history is
            | retained indefinitely, matching the audit-log retention policy.
            |
            */

            $table->foreignId('barangay_id')
                ->constrained('barangays')
                ->restrictOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Linked Records (nullable, history-preserving)
            |--------------------------------------------------------------------------
            |
            | nullOnDelete: deleting a linked request or resident must never delete
            | their issuance history. The link is nulled and the snapshot columns
            | below keep the record meaningful.
            |
            */

            $table->foreignId('certificate_request_id')
                ->nullable()
                ->constrained('certificate_requests')
                ->nullOnDelete();

            $table->foreignId('resident_id')
                ->nullable()
                ->constrained('residents')
                ->nullOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Issuance Reference
            |--------------------------------------------------------------------------
            |
            | Server-generated, unique. Kept distinct from the resident request
            | reference (CERT-...) so the two records never collide.
            |
            */

            $table->string('reference', 50)->unique();

            /*
            |--------------------------------------------------------------------------
            | Historical Snapshots
            |--------------------------------------------------------------------------
            |
            | The clerk generation flow uses hardcoded template keys, and the
            | printed document uses free-text name/purok. These values are
            | snapshotted so later edits to the resident profile or template set
            | do not rewrite history.
            |
            */

            $table->string('certificate_type', 150);

            $table->string('resident_name', 255);

            // Nullable snapshot for historical search. Only populated when a
            // resident number is available; never required.
            $table->string('resident_number', 50)->nullable();

            $table->string('purok', 255)->nullable();

            // Snapshot transferred from a validated, server-stored request fee
            // or a trusted server-side source. Never the raw client value.
            $table->decimal('fee', 10, 2)->nullable();

            /*
            |--------------------------------------------------------------------------
            | Issuing User
            |--------------------------------------------------------------------------
            |
            | nullOnDelete: matches the users.barangay_id and audit_logs.actor_id
            | conventions. History survives account removal.
            |
            */

            $table->foreignId('issued_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Issuance Timestamp
            |--------------------------------------------------------------------------
            |
            | The moment the server successfully generated and persisted the
            | issuance. This does not prove download, printing, or physical
            | release.
            |
            */

            $table->timestamp('issued_at');

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Indexes / Uniqueness
            |--------------------------------------------------------------------------
            |
            | The unique index on the nullable certificate_request_id enforces one
            | issuance per linked request. SQLite and PostgreSQL both treat NULLs
            | as distinct in unique indexes, so multiple manual issuances (null
            | request link) remain allowed.
            |
            */

            $table->unique(
                'certificate_request_id',
                'certificate_issuances_request_unique'
            );

            $table->index(
                ['barangay_id', 'issued_at'],
                'certificate_issuances_barangay_issued_idx'
            );

            $table->index(
                ['resident_id', 'issued_at'],
                'certificate_issuances_resident_issued_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificate_issuances');
    }
};
