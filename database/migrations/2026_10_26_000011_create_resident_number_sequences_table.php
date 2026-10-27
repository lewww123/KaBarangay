<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resident_number_sequences', function (Blueprint $table) {
            $table->id();

            $table->foreignId('barangay_id')
                ->unique()
                ->constrained('barangays')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('next_number')
                ->default(1);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resident_number_sequences');
    }
};
