<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('barangays', function (Blueprint $table) {
            $table->string('city_municipality')
                ->nullable()
                ->after('code');

            $table->string('province')
                ->nullable()
                ->after('city_municipality');
        });
    }

    public function down(): void
    {
        Schema::table('barangays', function (Blueprint $table) {
            $table->dropColumn([
                'city_municipality',
                'province',
            ]);
        });
    }
};