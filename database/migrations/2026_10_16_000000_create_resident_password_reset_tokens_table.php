<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('resident_password_reset_tokens', function (Blueprint $table) {
            $table->id();

            $table->foreignId('resident_id')
                ->constrained('residents')
                ->cascadeOnDelete();

            $table->string('email');

            // Only the hashed token is stored, never the plain reset token.
            $table->string('token');

            $table->timestamp('created_at')->nullable();

            $table->index('email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resident_password_reset_tokens');
    }
};
