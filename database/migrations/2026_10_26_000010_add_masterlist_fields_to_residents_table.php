<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('residents', function (Blueprint $table) {
            // Admin-created masterlist records have no source registration.
            $table->foreignId('resident_registration_id')
                ->nullable()
                ->change();

            $table->string('source', 32)
                ->default('registration')
                ->after('resident_number')
                ->index();

            $table->foreignId('created_by')
                ->nullable()
                ->after('source')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('email_verified_at')
                ->nullable()
                ->after('email');
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('residents', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropIndex(['source']);
            $table->dropColumn([
                'source',
                'created_by',
                'email_verified_at',
            ]);
        });
    }
};
