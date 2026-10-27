<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->string('importance', 20)->default('normal')->after('category');
            $table->timestamp('completed_at')->nullable()->after('reviewed_at');
            $table->foreignId('completed_by')
                ->nullable()
                ->after('completed_at')
                ->constrained('users')
                ->nullOnDelete();

            $table->index(['barangay_id', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropIndex(['barangay_id', 'completed_at']);
            $table->dropConstrainedForeignId('completed_by');
            $table->dropColumn(['importance', 'completed_at']);
        });
    }
};
