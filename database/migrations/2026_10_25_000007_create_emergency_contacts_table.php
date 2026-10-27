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
        Schema::create('emergency_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barangay_id')->constrained('barangays')->cascadeOnDelete();
            $table->string('category', 50)->index(); // 'barangay_hotline' or 'emergency_hotline'
            $table->string('sub_category', 100)->nullable(); // e.g., 'City Emergency', 'Ambulance', 'Fire', 'Police', 'Hospital', 'Disaster'
            $table->string('name', 255); // Agency name, officer name, or service name
            $table->string('label', 255)->nullable(); // e.g., 'Chief Tanod', 'Emergency Hotline 1', 'Official Hotline'
            $table->string('phone_number', 50);
            $table->boolean('can_call')->default(true);
            $table->boolean('can_sms')->default(false);
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['barangay_id', 'category', 'is_active']);
            $table->index(['barangay_id', 'display_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('emergency_contacts');
    }
};
