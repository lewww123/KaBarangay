<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lupon_cases', function (Blueprint $table) {
            foreach (['mediation_started_at', 'mediation_deadline_at', 'pangkat_formed_at', 'conciliation_started_at', 'conciliation_deadline_at', 'conciliation_extended_until', 'settled_at', 'repudiated_at', 'dismissed_at', 'cfa_issued_at', 'summons_due_at'] as $field) {
                $table->date($field)->nullable();
            }
            $table->json('pangkat_members')->nullable();
            $table->string('pangkat_chair_name')->nullable();
            $table->string('pangkat_secretary_name')->nullable();
            $table->foreignId('acting_captain_id')->nullable()->constrained('org_chart_positions')->nullOnDelete();
            $table->text('intake_narrative')->nullable();
            $table->text('intake_requested_action')->nullable();
            $table->date('incident_date')->nullable();
            $table->string('incident_location', 500)->nullable();
            $table->index(['barangay_id', 'mediation_deadline_at'], 'lupon_mediation_deadline_idx');
            $table->index(['barangay_id', 'conciliation_deadline_at'], 'lupon_conciliation_deadline_idx');
        });
        DB::table('lupon_cases')->where('status', 'Active')->where('phase', 'Lupon')->update(['status' => 'pangkat_formed']);
        foreach (['Active' => 'filed', 'Under Mediation' => 'mediation_active', 'Under Conciliation' => 'conciliation_active', 'Settled' => 'settled', 'Unresolved' => 'conciliation_active', 'Dismissed' => 'dismissed'] as $old => $new) {
            DB::table('lupon_cases')->where('status', $old)->update(['status' => $new]);
        }
        // A legacy label does not establish when parties actually met: leave those dates unasserted.
        DB::table('lupon_cases')->whereIn('status', ['settled', 'dismissed', 'certified_to_file_action'])->update(['phase' => 'Closed']);
        DB::table('lupon_cases')->where('status', 'conciliation_active')->update(['phase' => 'Lupon']);
        Schema::table('lupon_cases', fn (Blueprint $table) => $table->string('status', 50)->default('filed')->change());
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE lupon_cases ADD CONSTRAINT lupon_case_lifecycle_check CHECK (status IN ('filed','mediation_active','pangkat_formed','conciliation_active','settled','repudiated','dismissed','certified_to_file_action'))");
        }
        Schema::create('lupon_case_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('lupon_cases')->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['case_id', 'occurred_at']);
        });
        Schema::create('lupon_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barangay_id')->constrained('barangays')->cascadeOnDelete();
            $table->foreignId('complaint_id')->nullable()->constrained('lupon_complaints')->cascadeOnDelete();
            $table->foreignId('case_id')->nullable()->constrained('lupon_cases')->cascadeOnDelete();
            $table->foreignId('resident_id')->nullable()->constrained('residents')->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size');
            $table->boolean('visible_to_parties')->default(false);
            $table->timestamps();
            $table->index(['case_id', 'visible_to_parties']);
        });
        Schema::create('lupon_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('lupon_cases')->cascadeOnDelete();
            $table->foreignId('hearing_id')->nullable()->constrained('lupon_hearings')->nullOnDelete();
            $table->string('kind', 40);
            $table->string('status', 20)->default('pending');
            $table->string('path')->nullable();
            $table->string('file_name')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('signatories')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('served_at')->nullable();
            $table->foreignId('served_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('service_method', 20)->nullable();
            $table->text('service_note')->nullable();
            $table->timestamps();
            $table->index(['case_id', 'kind', 'status']);
        });
        // Laravel's server-side database role owns these tables. Supabase browser
        // roles must use the authorized Laravel endpoints, never PostgREST directly.
        if (DB::connection()->getDriverName() === 'pgsql') {
            foreach (['lupon_complaints', 'lupon_cases', 'lupon_case_parties', 'lupon_hearings', 'lupon_settlements', 'lupon_case_events', 'lupon_evidence', 'lupon_documents'] as $table) {
                DB::statement('ALTER TABLE '.$table.' ENABLE ROW LEVEL SECURITY');
            }
        }
        DB::table('lupon_settlements')->orderBy('id')->each(function ($settlement) {
            DB::table('lupon_cases')->where('id', $settlement->case_id)->where('status', 'settled')->update(['settled_at' => $settlement->settlement_date]);
        });
        DB::table('lupon_cases')->orderBy('id')->each(function ($case) {
            DB::table('lupon_case_events')->insert(['case_id' => $case->id, 'from_status' => null, 'to_status' => $case->status, 'actor_id' => null, 'occurred_at' => $case->created_at, 'notes' => 'Legacy case imported. Meeting dates require verification against original records.', 'metadata' => json_encode(['action' => 'legacy_import']), 'created_at' => now(), 'updated_at' => now()]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lupon_documents');
        Schema::dropIfExists('lupon_evidence');
        Schema::dropIfExists('lupon_case_events');
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE lupon_cases DROP CONSTRAINT IF EXISTS lupon_case_lifecycle_check');
        }
        Schema::table('lupon_cases', function (Blueprint $table) {
            $table->dropForeign(['acting_captain_id']);
            $table->dropIndex('lupon_mediation_deadline_idx');
            $table->dropIndex('lupon_conciliation_deadline_idx');
            $table->dropColumn(['mediation_started_at', 'mediation_deadline_at', 'pangkat_formed_at', 'conciliation_started_at', 'conciliation_deadline_at', 'conciliation_extended_until', 'settled_at', 'repudiated_at', 'dismissed_at', 'cfa_issued_at', 'summons_due_at', 'pangkat_members', 'pangkat_chair_name', 'pangkat_secretary_name', 'acting_captain_id', 'intake_narrative', 'intake_requested_action', 'incident_date', 'incident_location']);
        });
        foreach (['filed' => 'Active', 'mediation_active' => 'Under Mediation', 'pangkat_formed' => 'Under Conciliation', 'conciliation_active' => 'Under Conciliation', 'settled' => 'Settled', 'repudiated' => 'Unresolved', 'dismissed' => 'Dismissed', 'certified_to_file_action' => 'Unresolved'] as $current => $old) {
            DB::table('lupon_cases')->where('status', $current)->update(['status' => $old]);
        }
        Schema::table('lupon_cases', fn (Blueprint $table) => $table->string('status', 50)->default('Active')->change());
    }
};
