<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificate_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('barangay_id')
                ->constrained('barangays')
                ->cascadeOnDelete();

            $table->foreignId('resident_id')
                ->constrained('residents')
                ->cascadeOnDelete();

            $table->foreignId('certificate_id')
                ->constrained('certificates')
                ->restrictOnDelete();

            $table->string('reference', 50)->unique();

            $table->string('purpose');

            $table->decimal('fee', 10, 2)->default(0);

            $table->string('status', 50)
                ->default('Pending');

            $table->string('business_name')->nullable();

            $table->text('business_address')->nullable();

            $table->text('additional_details')->nullable();

            $table->text('admin_note')->nullable();

            $table->timestamp('submitted_at')->nullable();

            $table->timestamp('reviewed_at')->nullable();

            $table->timestamp('processing_at')->nullable();

            $table->timestamp('ready_for_claim_at')->nullable();

            $table->timestamp('released_at')->nullable();

            $table->timestamp('rejected_at')->nullable();

            $table->timestamps();

            $table->index(
                ['barangay_id', 'status'],
                'certificate_requests_barangay_status_idx'
            );

            $table->index(
                ['resident_id', 'status'],
                'certificate_requests_resident_status_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificate_requests');
    }
};