<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resident_qr_credentials', function (Blueprint $table) {
            $table->id();

            $table->foreignId('resident_id')
                ->unique()
                ->constrained('residents')
                ->cascadeOnDelete();

            // Random opaque token used by the QR code.
            $table->string('token', 255)
                ->unique();

            $table->boolean('is_active')
                ->default(true);

            $table->timestamp('generated_at')
                ->nullable();

            $table->timestamp('revoked_at')
                ->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resident_qr_credentials');
    }
};