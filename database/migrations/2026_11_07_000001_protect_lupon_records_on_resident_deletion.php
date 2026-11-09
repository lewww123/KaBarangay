<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE lupon_complaints DROP CONSTRAINT IF EXISTS lupon_complaints_resident_id_foreign');
            DB::statement('ALTER TABLE lupon_complaints ADD CONSTRAINT lupon_complaints_resident_id_foreign FOREIGN KEY (resident_id) REFERENCES residents (id) ON DELETE SET NULL');
        } else {
            Schema::table('lupon_complaints', function (Blueprint $table) {
                $table->dropForeign(['resident_id']);
                $table->foreign('resident_id')
                    ->references('id')
                    ->on('residents')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE lupon_complaints DROP CONSTRAINT IF EXISTS lupon_complaints_resident_id_foreign');
            DB::statement('ALTER TABLE lupon_complaints ADD CONSTRAINT lupon_complaints_resident_id_foreign FOREIGN KEY (resident_id) REFERENCES residents (id) ON DELETE CASCADE');
        } else {
            Schema::table('lupon_complaints', function (Blueprint $table) {
                $table->dropForeign(['resident_id']);
                $table->foreign('resident_id')
                    ->references('id')
                    ->on('residents')
                    ->cascadeOnDelete();
            });
        }
    }
};
