<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Nullable: a safe association may not always exist (e.g. an
            // unauthenticated failure with no session barangay). nullOnDelete
            // keeps the audit row if a barangay is ever removed, honouring
            // the indefinite-retention requirement; history is never cascaded
            // away.
            $table->foreignId('barangay_id')
                ->nullable()
                ->constrained('barangays')
                ->nullOnDelete();

            // Nullable: failed logins and resident (non-user) actions have no
            // staff actor. nullOnDelete keeps the row if the account is
            // removed later.
            $table->foreignId('actor_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('event', 60);

            $table->string('entity_type', 40)->nullable();

            // Deliberately NOT a foreign key: deleting the audited entity must
            // never cascade-delete its audit history.
            $table->unsignedBigInteger('entity_id')->nullable();

            // Minimal allowlisted metadata only (see AuditLogger).
            $table->json('context')->nullable();

            // IPv4/IPv6 string form. No user-agent column by design.
            $table->string('ip_address', 45)->nullable();

            // created_at only: no updated_at column (append-only records).
            $table->timestamp('created_at')->nullable();

            $table->index(
                ['barangay_id', 'created_at'],
                'audit_logs_barangay_created_idx'
            );

            $table->index(
                ['barangay_id', 'event', 'created_at'],
                'audit_logs_barangay_event_created_idx'
            );

            $table->index(
                ['entity_type', 'entity_id', 'created_at'],
                'audit_logs_entity_created_idx'
            );

            $table->index(
                ['actor_id', 'created_at'],
                'audit_logs_actor_created_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
