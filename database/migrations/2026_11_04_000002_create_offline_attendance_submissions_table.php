<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_attendance_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('client_request_id');
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->index('announcement_id');
            $table->string('payload_hash', 64);
            $table->timestamp('captured_at');
            $table->json('result');
            $table->timestamps();
            $table->unique(['user_id', 'client_request_id'], 'offline_attendance_request_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_attendance_submissions');
    }
};
