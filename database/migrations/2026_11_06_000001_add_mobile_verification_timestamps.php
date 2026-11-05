<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['resident_registrations', 'residents'] as $table) {
            if (! Schema::hasColumn($table, 'mobile_verified_at')) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->timestamp('mobile_verified_at')->nullable());
            }
        }
    }

    public function down(): void
    {
        // Preserve verification metadata on rollback; no destructive cleanup.
    }
};
