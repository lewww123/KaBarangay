<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('barangay_id')
                ->constrained('barangays')
                ->cascadeOnDelete();

            $table->string('code', 100);

            $table->string('name');

            $table->text('description')->nullable();

            $table->decimal('fee', 10, 2)->default(0);

            $table->json('requirements')->nullable();

            $table->boolean('requires_business_details')
                ->default(false);

            $table->boolean('is_active')
                ->default(true);

            $table->timestamps();

            $table->unique(
                ['barangay_id', 'code'],
                'certificates_barangay_code_unique'
            );

            $table->index(
                ['barangay_id', 'is_active'],
                'certificates_barangay_active_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};