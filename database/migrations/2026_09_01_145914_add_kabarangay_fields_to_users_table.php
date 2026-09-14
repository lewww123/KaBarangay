<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('barangay_id')
                ->nullable()
                ->after('id')
                ->constrained('barangays')
                ->nullOnDelete();

            $table->string('staff_id')
                ->nullable()
                ->unique()
                ->after('name');

            $table->string('department')
                ->nullable()
                ->after('staff_id');

            $table->string('role')
                ->default('staff')
                ->after('department');

            $table->string('status')
                ->default('active')
                ->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['barangay_id']);

            $table->dropColumn([
                'barangay_id',
                'staff_id',
                'department',
                'role',
                'status',
            ]);
        });
    }
};