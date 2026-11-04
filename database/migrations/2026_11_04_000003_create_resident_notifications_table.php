<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('resident_notifications')) {
            if (! Schema::hasColumns('resident_notifications', [
                'id', 'resident_id', 'barangay_id', 'event_key', 'title', 'message',
                'url', 'read_at', 'created_at', 'updated_at',
            ])) {
                throw new RuntimeException('Existing resident_notifications schema differs from this release. No records were changed; reconcile the schema before retrying.');
            }

            $hasEventKey = collect(Schema::getIndexes('resident_notifications'))
                ->contains(fn (array $index) => $index['unique'] && $index['columns'] === ['event_key']);
            if (! $hasEventKey) {
                throw new RuntimeException('Existing resident_notifications table requires a unique event_key index. No records were changed; review duplicate keys before retrying.');
            }

            return;
        }

        Schema::create('resident_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('barangay_id')->constrained()->cascadeOnDelete();
            $table->string('event_key')->unique();
            $table->string('title');
            $table->text('message');
            $table->string('url');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['resident_id', 'barangay_id', 'read_at']);
            $table->index('barangay_id');
        });
    }

    public function down(): void
    {
        // Keep resident history intact even when application code is rolled back.
    }
};
