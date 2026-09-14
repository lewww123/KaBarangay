<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('puroks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('barangay_id')
                ->constrained('barangays')
                ->restrictOnDelete();

            $table->string('name');

            $table->string('code')
                ->nullable();

            $table->unsignedInteger('sort_order')
                ->default(0);

            $table->boolean('is_active')
                ->default(true);

            $table->timestamps();

            $table->unique([
                'barangay_id',
                'name',
            ]);

            $table->index([
                'barangay_id',
                'is_active',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('puroks');
    }
};