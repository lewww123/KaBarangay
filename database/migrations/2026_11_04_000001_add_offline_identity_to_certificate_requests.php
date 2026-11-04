<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificate_requests', function (Blueprint $table) {
            $table->uuid('client_request_id')->nullable();
            $table->char('client_payload_hash', 64)->nullable();
            $table->unique(['resident_id', 'client_request_id'], 'certificate_requests_resident_client_unique');
        });
    }

    public function down(): void
    {
        Schema::table('certificate_requests', function (Blueprint $table) {
            $table->dropUnique('certificate_requests_resident_client_unique');
            $table->dropColumn(['client_request_id', 'client_payload_hash']);
        });
    }
};
