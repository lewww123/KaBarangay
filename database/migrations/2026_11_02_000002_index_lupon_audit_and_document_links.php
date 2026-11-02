<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LINKS = [
        'lupon_cases' => ['acting_captain_id'],
        'lupon_case_events' => ['actor_id'],
        'lupon_evidence' => ['barangay_id', 'complaint_id', 'resident_id', 'uploaded_by'],
        'lupon_documents' => ['hearing_id', 'created_by', 'served_by'],
    ];

    public function up(): void
    {
        foreach (self::LINKS as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                foreach ($columns as $column) {
                    $blueprint->index($column);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::LINKS as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                foreach ($columns as $column) {
                    $blueprint->dropIndex([$column]);
                }
            });
        }
    }
};
