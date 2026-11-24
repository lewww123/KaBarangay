<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->decimal('fee', 10, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('certificates')->whereNull('fee')->exists()) {
            throw new \RuntimeException('Cannot roll back certificate fee migration while NULL fees exist.');
        }

        Schema::table('certificates', function (Blueprint $table) {
            $table->decimal('fee', 10, 2)->nullable(false)->default(0)->change();
        });
    }
};
