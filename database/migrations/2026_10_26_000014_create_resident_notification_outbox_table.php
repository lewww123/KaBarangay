<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resident_notification_outbox', function (Blueprint $table) {
            $table->id();

            // Idempotency key for the business event that caused this message.
            $table->string('event_key', 100)
                ->unique();

            $table->string('type', 50);

            $table->foreignId('resident_id')
                ->constrained('residents')
                ->cascadeOnDelete();

            $table->foreignId('resident_claim_id')
                ->nullable()
                ->constrained('resident_claims')
                ->nullOnDelete();

            // Recipient snapshot captured at enqueue time.
            $table->string('recipient_email', 255);
            $table->string('recipient_name', 255);

            $table->string('status', 30)
                ->default('pending');

            $table->unsignedInteger('attempts')
                ->default(0);

            $table->timestamp('sent_at')
                ->nullable();

            $table->timestamp('failed_at')
                ->nullable();

            $table->text('last_error')
                ->nullable();

            $table->timestamps();

            $table->index(
                ['status', 'created_at'],
                'resident_notification_outbox_status_index'
            );

            $table->index(
                ['resident_id', 'type'],
                'resident_notification_outbox_resident_type_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resident_notification_outbox');
    }
};
