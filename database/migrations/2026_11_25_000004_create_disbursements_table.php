<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disbursements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('disbursement_date');
            $table->uuid('allocation_id');
            $table->text('payee');
            $table->text('purpose');
            $table->decimal('amount', 15, 2);
            $table->string('dv_number')->nullable();
            $table->string('status')->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();

            $table->foreign('allocation_id', 'disbursements_allocation_fk')
                ->references('id')
                ->on('allocations')
                ->restrictOnDelete();
            $table->index('allocation_id', 'disbursements_allocation_idx');
            $table->index('status', 'disbursements_status_idx');
            $table->index('disbursement_date', 'disbursements_date_idx');
            $table->index('prepared_by', 'disbursements_prepared_by_idx');
            $table->index('approved_by', 'disbursements_approved_by_idx');
            $table->index('released_by', 'disbursements_released_by_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disbursements');
    }
};
