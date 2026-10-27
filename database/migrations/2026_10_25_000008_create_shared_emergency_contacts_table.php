<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_emergency_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('source_key', 100)->unique();
            $table->string('service_type', 50)->index();
            $table->string('name', 255);
            $table->string('label', 255)->nullable();
            $table->string('phone_number', 50);
            $table->string('normalized_phone', 20);
            $table->string('coverage_area', 255);
            $table->boolean('can_call')->default(true);
            $table->boolean('can_sms')->default(false);
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('verified_at');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('verification_source', 255);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'display_order']);
            $table->index(['service_type', 'normalized_phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_emergency_contacts');
    }
};
