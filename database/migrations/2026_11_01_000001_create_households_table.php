<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('households', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barangay_id')->constrained()->restrictOnDelete();
            $table->foreignId('purok_id')->constrained()->restrictOnDelete();
            $table->string('household_code', 32);
            $table->foreignId('head_resident_id')->nullable()->constrained('residents')->nullOnDelete();
            $table->timestamps();

            $table->unique(['barangay_id', 'household_code']);
            $table->index(['barangay_id', 'purok_id']);
        });

        Schema::table('residents', function (Blueprint $table) {
            $table->foreignId('household_id')->nullable()->after('purok_id')->constrained()->nullOnDelete();
            $table->string('household_relationship', 100)->nullable()->after('household_id');
            $table->index(['barangay_id', 'household_id']);
        });
    }

    public function down(): void
    {
        Schema::table('residents', function (Blueprint $table) {
            $table->dropIndex(['barangay_id', 'household_id']);
            $table->dropConstrainedForeignId('household_id');
            $table->dropColumn('household_relationship');
        });

        Schema::dropIfExists('households');
    }
};
