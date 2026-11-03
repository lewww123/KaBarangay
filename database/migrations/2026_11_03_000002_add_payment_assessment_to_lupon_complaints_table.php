<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lupon_complaints', function (Blueprint $table) {
            $table->decimal('assessed_fee', 10, 2)->nullable();
            $table->timestamp('payment_requested_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lupon_complaints', function (Blueprint $table) {
            $table->dropColumn(['assessed_fee', 'payment_requested_at']);
        });
    }
};
