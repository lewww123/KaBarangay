<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            // PostgreSQL needs matching expression/pattern indexes for case-insensitive LIKE prefixes.
            DB::statement('CREATE INDEX residents_autocomplete_first_idx ON residents (barangay_id, status, lower(first_name) text_pattern_ops, id)');
            DB::statement('CREATE INDEX residents_autocomplete_last_idx ON residents (barangay_id, status, lower(last_name) text_pattern_ops, id)');

            return;
        }
        Schema::table('residents', function (Blueprint $table) {
            $table->index(['barangay_id', 'status', 'first_name', 'id'], 'residents_autocomplete_first_idx');
            $table->index(['barangay_id', 'status', 'last_name', 'id'], 'residents_autocomplete_last_idx');
        });
    }

    public function down(): void
    {
        Schema::table('residents', function (Blueprint $table) {
            $table->dropIndex('residents_autocomplete_first_idx');
            $table->dropIndex('residents_autocomplete_last_idx');
        });
    }
};
