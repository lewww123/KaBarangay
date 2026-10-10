<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resident_sector', function (Blueprint $table) {
            $table->id();

            $table->foreignId('resident_id')
                ->constrained('residents')
                ->cascadeOnDelete();

            $table->foreignId('sector_id')
                ->constrained('sectors')
                ->restrictOnDelete();

            $table->timestamps();

            $table->unique(
                ['resident_id', 'sector_id'],
                'resident_sector_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resident_sector');
    }
};