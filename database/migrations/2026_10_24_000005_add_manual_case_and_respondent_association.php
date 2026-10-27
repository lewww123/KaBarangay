<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Make complaint_id nullable on lupon_cases to support walk-in / manual cases
        Schema::table('lupon_cases', function (Blueprint $table) {
            $table->foreignId('complaint_id')->nullable()->change();
            // origin: 'promoted' or 'manual'
            $table->string('origin', 20)->default('promoted')->after('complaint_id');
        });

        // 2. Add verification lifecycle fields to lupon_case_parties for respondent account association
        Schema::table('lupon_case_parties', function (Blueprint $table) {
            // Status: 'unlinked', 'pending_verification', 'verified', 'rejected'
            $table->string('association_status', 30)->default('unlinked')->after('is_resident');
            $table->foreignId('associated_by')->nullable()->constrained('users')->nullOnDelete()->after('association_status');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete()->after('associated_by');
            $table->timestamp('verified_at')->nullable()->after('verified_by');
            $table->text('verification_notes')->nullable()->after('verified_at');

            $table->index(['case_id', 'resident_id', 'association_status'], 'lupon_parties_resident_assoc_idx');
        });
    }

    public function down(): void
    {
        Schema::table('lupon_case_parties', function (Blueprint $table) {
            $table->dropIndex('lupon_parties_resident_assoc_idx');
            $table->dropForeign(['verified_by']);
            $table->dropForeign(['associated_by']);
            $table->dropColumn([
                'association_status',
                'associated_by',
                'verified_by',
                'verified_at',
                'verification_notes',
            ]);
        });

        Schema::table('lupon_cases', function (Blueprint $table) {
            $table->dropColumn('origin');
            $table->foreignId('complaint_id')->nullable(false)->change();
        });
    }
};
