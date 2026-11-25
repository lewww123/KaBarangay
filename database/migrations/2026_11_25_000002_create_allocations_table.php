<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->integer('fiscal_year');
            $table->text('name');
            $table->text('fund_type');
            $table->text('category');
            $table->text('source');
            $table->decimal('amount_approved', 15, 2);
            $table->string('reference_no')->nullable();
            $table->string('status')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index('fiscal_year', 'allocations_fiscal_year_idx');
            $table->index('status', 'allocations_status_idx');
            $table->index('created_by', 'allocations_created_by_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allocations');
    }
};
