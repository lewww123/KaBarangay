<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lupon_complaints', function (Blueprint $table) {
            // Non-resident walk-in parties retain their original intake snapshot.
            $table->foreignId('resident_id')->nullable()->change();
            $table->json('complainant_snapshot')->nullable();
            $table->uuid('submission_token')->nullable();
            $table->unique(['resident_id', 'submission_token'], 'lupon_submission_token_unique');
            $table->json('payment_verification')->nullable();
        });
        Schema::table('lupon_cases', function (Blueprint $table) {
            $table->json('payment_verification')->nullable();
            $table->json('arbitration_record')->nullable();
            $table->json('disposition_record')->nullable();
        });
        Schema::table('lupon_documents', function (Blueprint $table) {
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE lupon_cases DROP CONSTRAINT IF EXISTS lupon_case_lifecycle_check');
            DB::statement("ALTER TABLE lupon_cases ADD CONSTRAINT lupon_case_lifecycle_check CHECK (status IN ('filed','mediation_active','pangkat_formed','conciliation_active','settled','repudiated','dismissed','certified_to_file_action','arbitration_active','arbitration_awarded','executed','court_forwarded'))");
        }
    }

    public function down(): void
    {
        // Intentionally retain payment, issuance, and proceeding history.
        // Any rollback requires a reviewed data-preserving migration.
    }
};
