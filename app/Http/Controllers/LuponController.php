<?php

namespace App\Http\Controllers;

use App\Models\LuponCase;
use App\Models\LuponDocument;
use App\Models\LuponHearing;
use App\Support\LuponCaseWorkflow;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class LuponController extends Controller
{
    public function dashboard(Request $request): Response
    {
        $user = $request->user();

        if (! $user->hasActiveAssignment(['lupon'])) {
            abort(403, 'Unauthorized access to Lupon Portal.');
        }

        $pangkatDue = DB::connection()->getDriverName() === 'pgsql' ? '(pangkat_formed_at + 3)' : "date(pangkat_formed_at, '+3 days')";
        $deadlineSql = "CASE WHEN status = 'filed' AND form_7_issued_at IS NULL THEN summons_due_at WHEN status = 'mediation_active' THEN mediation_deadline_at WHEN status = 'pangkat_formed' THEN {$pangkatDue} WHEN status = 'conciliation_active' THEN COALESCE(conciliation_extended_until, conciliation_deadline_at) END";

        return Inertia::render('Lupon/Dashboard', [
            'userName' => $user->name,
            'barangayName' => $user->barangay?->name,
            'metrics' => $this->deferProp(fn () => $this->rememberAggregate(
                "lupon:dashboard:metrics:{$user->barangay_id}",
                30,
                function () use ($user): array {
                    $counts = LuponCase::query()
                        ->where('barangay_id', $user->barangay_id)
                        ->selectRaw("COUNT(*) as filed, COUNT(CASE WHEN status IN ('filed', 'mediation_active') THEN 1 END) as mediation, COUNT(CASE WHEN status IN ('pangkat_formed', 'conciliation_active') THEN 1 END) as conciliation, COUNT(CASE WHEN status = 'settled' THEN 1 END) as settled")
                        ->first();

                    return [
                        'filed' => (int) $counts->filed,
                        'mediation' => (int) $counts->mediation,
                        'conciliation' => (int) $counts->conciliation,
                        'settled' => (int) $counts->settled,
                        'certificatesIssued' => LuponDocument::whereHas('case', fn ($q) => $q->where('barangay_id', $user->barangay_id))->where('kind', 'cfa')->where('status', 'ready')->count(),
                    ];
                },
            )),
            'deadlines' => $this->deferProp(fn () => LuponCase::query()
                ->where('barangay_id', $user->barangay_id)
                ->whereIn('status', ['filed', 'mediation_active', 'pangkat_formed', 'conciliation_active'])
                ->selectRaw('lupon_cases.*, '.$deadlineSql.' AS current_deadline_at')
                ->whereRaw('('.$deadlineSql.') <= ?', [Carbon::parse(LuponCaseWorkflow::today())->addDays(15)->toDateString()])
                ->orderByRaw($deadlineSql)
                ->limit(8)
                ->get()
                ->map(fn (LuponCase $case) => [
                    'id' => $case->id,
                    'caseNumber' => $case->case_number,
                    'title' => $case->title,
                    'phase' => $case->phase,
                    'dueDate' => Carbon::parse($case->current_deadline_at)->format('M j, Y'),
                    'overdue' => Carbon::parse($case->current_deadline_at)->toDateString() < LuponCaseWorkflow::today(),
                ])),
            'schedule' => $this->deferProp(fn () => LuponHearing::query()
                ->where('barangay_id', $user->barangay_id)
                ->whereIn('status', ['Scheduled', 'Rescheduled'])
                ->whereBetween('hearing_date', [now()->startOfDay(), now()->addDays(7)->endOfDay()])
                ->with('case:id,case_number,title')
                ->select(['id', 'case_id', 'hearing_number', 'phase', 'hearing_date', 'hearing_time', 'venue'])
                ->orderBy('hearing_date')
                ->orderBy('hearing_time')
                ->limit(10)
                ->get()
                ->map(fn (LuponHearing $hearing) => [
                    'id' => $hearing->id,
                    'caseId' => $hearing->case_id,
                    'caseNumber' => $hearing->case?->case_number,
                    'caseTitle' => $hearing->case?->title,
                    'phase' => $hearing->phase,
                    'date' => $hearing->hearing_date->format('D, M j'),
                    'time' => $hearing->hearing_time,
                    'venue' => $hearing->venue,
                ])),
        ]);
    }
}
