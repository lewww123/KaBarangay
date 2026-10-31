<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lupon_cases', function (Blueprint $table) {
            $table->string('payment_status', 20)->default('unpaid')->after('status');
            $table->decimal('fee_amount', 10, 2)->nullable()->after('payment_status');
            $table->string('or_number', 100)->nullable()->after('fee_amount');
            $table->timestamp('paid_at')->nullable()->after('or_number');
            $table->timestamp('form_7_issued_at')->nullable()->after('paid_at');
            $table->index(['barangay_id', 'payment_status', 'status'], 'lupon_cases_payment_status_idx');
        });

        Schema::table('lupon_case_parties', function (Blueprint $table) {
            $table->index(['resident_id', 'association_status'], 'lupon_case_parties_resident_assoc_idx');
        });

        Schema::table('resident_notification_outbox', function (Blueprint $table) {
            $table->json('context')->nullable()->after('email_safe_reason');
        });
    }

    public function down(): void
    {
        Schema::table('resident_notification_outbox', function (Blueprint $table) {
            $table->dropColumn('context');
        });

        Schema::table('lupon_case_parties', function (Blueprint $table) {
            $table->dropIndex('lupon_case_parties_resident_assoc_idx');
        });

        Schema::table('lupon_cases', function (Blueprint $table) {
            $table->dropIndex('lupon_cases_payment_status_idx');
            $table->dropColumn(['payment_status', 'fee_amount', 'or_number', 'paid_at', 'form_7_issued_at']);
        });
    }
};
