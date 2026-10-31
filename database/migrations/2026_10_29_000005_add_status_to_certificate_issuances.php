<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificate_issuances', function (Blueprint $table) {
            $table->string('status', 20)->default('issued');
            $table->index(['barangay_id', 'status', 'issued_at'], 'certificate_issuances_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('certificate_issuances', function (Blueprint $table) {
            $table->dropIndex('certificate_issuances_status_idx');
            $table->dropColumn('status');
        });
    }
};
