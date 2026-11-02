<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificate_issuances', function (Blueprint $table) {
            $table->dropUnique('cert_issuances_barangay_control_unique');
            $table->unique(['barangay_id', 'certificate_type', 'control_no'], 'cert_issuances_type_control_unique');
        });
    }

    public function down(): void
    {
        Schema::table('certificate_issuances', function (Blueprint $table) {
            $table->dropUnique('cert_issuances_type_control_unique');
            $table->unique(['barangay_id', 'control_no'], 'cert_issuances_barangay_control_unique');
        });
    }
};
