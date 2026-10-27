<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resident_notification_outbox', function (Blueprint $table) {
            // Existing Claim notifications keep their resident association; registration
            // verification and pre-link decisions may have no Resident yet.
            $table->foreignId('resident_id')->nullable()->change();

            $table->foreignId('resident_registration_id')
                ->nullable()
                ->constrained('resident_registrations', 'id', 'resident_notification_registration_fk')
                ->restrictOnDelete();

            $table->foreignId('correction_cycle_id')
                ->nullable()
                ->constrained('resident_registration_correction_cycles', 'id', 'resident_notification_correction_fk')
                ->restrictOnDelete();

            $table->string('decision_type', 50)->nullable();
            $table->timestamp('deferred_at')->nullable();
            $table->uuid('lease_token')->nullable()->unique();
            $table->timestamp('lease_expires_at')->nullable();
            $table->string('failure_class', 100)->nullable();

            $table->index(
                ['resident_registration_id', 'type'],
                'resident_notification_registration_type_index'
            );
            $table->index(
                ['status', 'lease_expires_at'],
                'resident_notification_lease_index'
            );
            $table->index(
                'correction_cycle_id',
                'resident_notification_correction_cycle_index'
            );
        });
    }

    public function down(): void
    {
        if (DB::table('resident_notification_outbox')->where(function ($query) {
            $query->whereNull('resident_id')
                ->orWhereNotNull('resident_registration_id')
                ->orWhereNotNull('correction_cycle_id')
                ->orWhereNotNull('decision_type')
                ->orWhereNotNull('deferred_at')
                ->orWhereNotNull('lease_token')
                ->orWhereNotNull('lease_expires_at')
                ->orWhereNotNull('failure_class')
                ->orWhere('status', 'deferred');
        })->exists()) {
            throw new RuntimeException(
                'Refusing to remove registration notification history or lease data while it is present.'
            );
        }

        Schema::table('resident_notification_outbox', function (Blueprint $table) {
            $table->dropIndex('resident_notification_registration_type_index');
            $table->dropIndex('resident_notification_lease_index');
            $table->dropIndex('resident_notification_correction_cycle_index');
            $table->dropUnique(['lease_token']);
            $table->dropForeign('resident_notification_registration_fk');
            $table->dropForeign('resident_notification_correction_fk');
            $table->dropColumn([
                'resident_registration_id',
                'correction_cycle_id',
                'decision_type',
                'deferred_at',
                'lease_token',
                'lease_expires_at',
                'failure_class',
            ]);

            $table->foreignId('resident_id')->nullable(false)->change();
        });
    }
};
