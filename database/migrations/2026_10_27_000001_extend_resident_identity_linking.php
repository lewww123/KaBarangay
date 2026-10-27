<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resident_registrations', function (Blueprint $table) {
            $table->foreignId('matched_resident_id')
                ->nullable()
                ->after('purok_id')
                ->constrained('residents')
                ->nullOnDelete();
            $table->timestamp('matched_at')->nullable();
            $table->foreignId('matched_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->index(['barangay_id', 'matched_resident_id'], 'resident_registrations_match_index');
        });

    }

    public function down(): void
    {
        if (DB::table('resident_registrations')->where(function ($query) {
            $query->whereNotNull('matched_resident_id')
                ->orWhereNotNull('matched_at')
                ->orWhereNotNull('matched_by');
        })->exists()) {
            throw new RuntimeException('Refusing to roll back resident identity links; resolve matched registrations first.');
        }

        Schema::table('resident_registrations', function (Blueprint $table) {
            $table->dropIndex('resident_registrations_match_index');
            $table->dropForeign(['matched_resident_id']);
            $table->dropForeign(['matched_by']);
            $table->dropColumn(['matched_resident_id', 'matched_at', 'matched_by']);
        });
    }
};
