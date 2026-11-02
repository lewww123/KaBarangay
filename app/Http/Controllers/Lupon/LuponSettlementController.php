<?php

namespace App\Http\Controllers\Lupon;

use App\Http\Controllers\Controller;
use App\Models\LuponCase;
use App\Models\LuponDocument;
use App\Models\LuponSettlement;
use App\Support\AuditLogger;
use App\Support\LuponAccess;
use App\Support\LuponCaseWorkflow;
use App\Support\LuponDocuments;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LuponSettlementController extends Controller
{
    public function store(Request $request, LuponCase $case)
    {
        LuponAccess::staff($request, $case);
        $data = $request->validate(['settlement_type' => ['required', 'in:Amicable Settlement'], 'settlement_date' => ['required', 'date', 'before_or_equal:'.LuponCaseWorkflow::today()], 'terms' => ['required', 'string', 'max:10000']]);
        DB::transaction(function () use ($case, $request, $data) {
            $locked = LuponCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            $hearing = $locked->hearings()->where('phase', LuponCaseWorkflow::phase($locked->status))->whereNotIn('status', ['Cancelled'])->orderByDesc('hearing_number')->first();
            $meeting = $hearing ? Carbon::parse($hearing->hearing_date->toDateString().' '.($hearing->hearing_time ?? '00:00'), config('lupon.timezone', 'Asia/Manila')) : null;
            if ((! config('lupon.hearing_actions_unlocked', true) && $meeting && $meeting->isFuture()) || (! $meeting && in_array($locked->status, ['filed', 'pangkat_formed'], true))) {
                throw ValidationException::withMessages(['terms' => 'The scheduled meeting must have started before recording settlement.']);
            }
            if (in_array($locked->status, ['filed', 'pangkat_formed'], true)) {
                $locked = LuponCaseWorkflow::transition($locked, $locked->status === 'filed' ? 'mediation_active' : 'conciliation_active', $request->user()->id, ['event_date' => config('lupon.hearing_actions_unlocked', true) ? min($hearing->hearing_date->toDateString(), $data['settlement_date']) : $hearing->hearing_date->toDateString()]);
            }
            if (! in_array($locked->status, ['mediation_active', 'pangkat_formed', 'conciliation_active'], true) || $locked->settlement()->exists()) {
                throw ValidationException::withMessages(['terms' => 'A written settlement can be recorded once during active mediation or conciliation. Existing records cannot be silently overwritten.']);
            }
            if (Carbon::parse($data['settlement_date'])->lt($locked->mediation_started_at ?? $locked->filed_date)) {
                throw ValidationException::withMessages(['settlement_date' => 'The settlement cannot precede the filing date.']);
            }
            LuponSettlement::create([...$data, 'case_id' => $locked->id, 'barangay_id' => $locked->barangay_id, 'recorded_by' => $request->user()->id, 'status' => 'Active']);
            $locked = LuponCaseWorkflow::transition($locked, 'settled', $request->user()->id, ['event_date' => $data['settlement_date'], 'notes' => 'Written '.$data['settlement_type'].' recorded.']);
            if (LuponDocuments::signers($locked)['punong_barangay']) {
                LuponDocuments::generate($locked, 'amicable_settlement', $request->user()->id);
            } else {
                LuponDocument::create(['case_id' => $locked->id, 'kind' => 'amicable_settlement', 'status' => 'pending', 'created_by' => $request->user()->id]);
            }
            $hearing?->update(['status' => 'Held', 'outcome' => 'Settled', 'notes' => $data['terms']]);
            AuditLogger::record('lupon_settlement.recorded', barangayId: $locked->barangay_id, actorId: $request->user()->id, entityType: 'lupon_case', entityId: $locked->id, context: ['case_number' => $locked->case_number, 'new_status' => 'settled']);
        });

        return back()->with('success', 'Written settlement recorded. The ten-day repudiation period is now tracked.');
    }
}
