<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('resident_id')
                ->nullable()
                ->after('barangay_id')
                ->constrained('residents')
                ->cascadeOnDelete();
            $table->index('resident_id', 'users_resident_idx');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_resident_idx');
            $table->dropConstrainedForeignId('resident_id');
        });
    }
};
