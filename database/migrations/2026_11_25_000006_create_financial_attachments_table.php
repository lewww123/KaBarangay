<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('record_type');
            $table->uuid('record_id');
            $table->text('title');
            $table->text('file_path');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at')->useCurrent();

            $table->index(['record_type', 'record_id'], 'financial_attachments_record_idx');
            $table->index('uploaded_by', 'financial_attachments_uploaded_by_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_attachments');
    }
};
