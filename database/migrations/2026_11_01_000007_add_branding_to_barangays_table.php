<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('barangays', function (Blueprint $table) {
            $table->text('city_logo_path')->nullable()->after('province');
            $table->text('barangay_logo_path')->nullable()->after('city_logo_path');
            $table->text('watermark_path')->nullable()->after('barangay_logo_path');
            $table->json('header_text')->nullable()->after('watermark_path');
        });
    }

    public function down(): void
    {
        Schema::table('barangays', function (Blueprint $table) {
            $table->dropColumn([
                'city_logo_path',
                'barangay_logo_path',
                'watermark_path',
                'header_text',
            ]);
        });
    }
};
