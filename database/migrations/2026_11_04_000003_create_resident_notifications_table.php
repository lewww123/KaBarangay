<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
        Schema::dropIfExists('resident_notifications');
    }
};
