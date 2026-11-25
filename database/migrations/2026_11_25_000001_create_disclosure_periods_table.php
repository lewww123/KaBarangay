<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disclosure_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->integer('year');
            $table->integer('month');
            $table->boolean('is_published')->default(false);
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->index(['year', 'month'], 'disclosure_periods_year_month_idx');
            $table->index('published_by', 'disclosure_periods_published_by_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disclosure_periods');
    }
};
