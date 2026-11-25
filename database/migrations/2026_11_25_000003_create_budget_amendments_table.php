<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_amendments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('allocation_id');
            $table->decimal('amount', 15, 2);
            $table->string('reference_no')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('allocation_id', 'budget_amendments_allocation_fk')
                ->references('id')
                ->on('allocations')
                ->restrictOnDelete();
            $table->index('allocation_id', 'budget_amendments_allocation_idx');
            $table->index('created_by', 'budget_amendments_created_by_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_amendments');
    }
};
