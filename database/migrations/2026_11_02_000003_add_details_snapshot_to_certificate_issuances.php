<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificate_issuances', function (Blueprint $table) {
            // Immutable snapshot of the values and resolved template data used
            // to render the document. Historical rows remain null and are
            // reconstructed from their linked request where possible.
            $table->json('details')->nullable()->after('fee');
        });
    }

    public function down(): void
    {
        Schema::table('certificate_issuances', function (Blueprint $table) {
            $table->dropColumn('details');
        });
    }
};
