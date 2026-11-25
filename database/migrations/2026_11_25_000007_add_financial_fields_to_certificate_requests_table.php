<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificate_requests', function (Blueprint $table) {
            $table->string('or_number')->nullable();
            $table->decimal('amount_paid', 15, 2)->nullable();
            $table->boolean('is_waived')->default(false);
            $table->uuid('collection_id')->nullable();

            $table->foreign('collection_id', 'certificate_requests_collection_fk')
                ->references('id')
                ->on('collections')
                ->nullOnDelete();
            $table->index('collection_id', 'certificate_requests_collection_idx');
        });
    }

    public function down(): void
    {
        Schema::table('certificate_requests', function (Blueprint $table) {
            $table->dropIndex('certificate_requests_collection_idx');
            $table->dropForeign('certificate_requests_collection_fk');
            $table->dropColumn(['or_number', 'amount_paid', 'is_waived', 'collection_id']);
        });
    }
};
