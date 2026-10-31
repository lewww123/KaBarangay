<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('barangay_id')->constrained()->cascadeOnDelete();
            $table->string('department', 50);
            $table->string('position', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['barangay_id', 'department', 'position', 'is_active'], 'staff_assignments_lookup_idx');
            $table->index('resident_id', 'staff_assignments_resident_idx');
            $table->unique(['user_id', 'resident_id', 'barangay_id', 'department', 'position'], 'staff_assignments_identity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_assignments');
    }
};
