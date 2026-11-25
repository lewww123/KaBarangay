<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('collection_date');
            $table->text('collection_type');
            $table->decimal('amount', 15, 2);
            $table->string('or_number')->unique();
            $table->text('payee_name');
            $table->foreignId('certificate_request_id')->nullable()->constrained('certificate_requests')->nullOnDelete();
            $table->boolean('is_waived')->default(false);
            $table->text('waive_reason')->nullable();
            $table->string('status')->nullable();
            $table->foreignId('encoded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('void_reason')->nullable();

            $table->index('collection_date', 'collections_date_idx');
            $table->index('status', 'collections_status_idx');
            $table->index('certificate_request_id', 'collections_certificate_request_idx');
            $table->index('encoded_by', 'collections_encoded_by_idx');
            $table->index('verified_by', 'collections_verified_by_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collections');
    }
};
