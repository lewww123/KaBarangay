<?php

namespace App\Http\Controllers;

use App\Models\LuponCase;
use App\Models\LuponCaseEvent;
use App\Models\LuponComplaint;
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
                "lupon:dashboard:metrics:v3:{$user->barangay_id}",
                30,
                function () use ($user): array {
                    $counts = LuponCase::query()
                        ->where('barangay_id', $user->barangay_id)
                        ->selectRaw("COUNT(*) as filed, COUNT(CASE WHEN status IN ('filed', 'mediation_active') THEN 1 END) as mediation, COUNT(CASE WHEN status = 'mediation_active' THEN 1 END) as active_mediation, COUNT(CASE WHEN status IN ('pangkat_formed', 'conciliation_active') THEN 1 END) as conciliation, COUNT(CASE WHEN status = 'conciliation_active' THEN 1 END) as active_conciliation, COUNT(CASE WHEN status = 'arbitration_active' THEN 1 END) as arbitration, COUNT(CASE WHEN phase = 'Closed' THEN 1 END) as resolved, COUNT(CASE WHEN status = 'settled' THEN 1 END) as settled")
                        ->first()?->getAttributes() ?? [];

                    $complaintCounts = LuponComplaint::query()
                        ->where('barangay_id', $user->barangay_id)
                        ->whereDoesntHave('case')
                        ->selectRaw("COUNT(CASE WHEN status NOT IN ('Accepted', 'Rejected') THEN 1 END) as pending, COUNT(CASE WHEN status IN ('Pending Payment', 'For Payment') THEN 1 END) as awaiting_payment, COUNT(CASE WHEN status IN ('Pending Payment', 'For Payment', 'Payment Verification') THEN 1 END) as pending_payment")
                        ->first()?->getAttributes() ?? [];

                    return [
                        'filed' => (int) ($counts['filed'] ?? 0),
                        'mediation' => (int) ($counts['mediation'] ?? 0),
                        'activeMediation' => (int) ($counts['active_mediation'] ?? 0),
                        'conciliation' => (int) ($counts['conciliation'] ?? 0),
                        'activeConciliation' => (int) ($counts['active_conciliation'] ?? 0),
                        'settled' => (int) ($counts['settled'] ?? 0),
                        'resolved' => (int) ($counts['resolved'] ?? 0),
                        'pendingPayment' => (int) ($complaintCounts['pending_payment'] ?? 0),
                        'pendingComplaints' => (int) ($complaintCounts['pending'] ?? 0),
                        'awaitingPayment' => (int) ($complaintCounts['awaiting_payment'] ?? 0),
                        'arbitration' => (int) ($counts['arbitration'] ?? 0),
                        'certificatesIssued' => LuponDocument::whereHas('case', fn ($q) => $q->where('barangay_id', $user->barangay_id))->where('kind', 'cfa')->whereNotNull('issued_at')->count(),
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
            'recentActivity' => $this->deferProp(fn () => LuponCaseEvent::query()
                ->join('lupon_cases', 'lupon_cases.id', '=', 'lupon_case_events.case_id')
                ->where('lupon_cases.barangay_id', $user->barangay_id)
                ->select(['lupon_case_events.*', 'lupon_cases.case_number', 'lupon_cases.title as case_title'])
                ->latest('lupon_case_events.occurred_at')
                ->latest('lupon_case_events.id')
                ->limit(8)
                ->get()
                ->map(function (LuponCaseEvent $event): array {
                    $attributes = $event->getAttributes();
                    $toStatus = (string) ($attributes['to_status'] ?? '');
                    $occurredAt = $attributes['occurred_at'] ?? null;

                    return [
                        'id' => (int) $attributes['id'],
                        'caseId' => (int) $attributes['case_id'],
                        'caseNumber' => $attributes['case_number'] ?? null,
                        'caseTitle' => $attributes['case_title'] ?? null,
                        'fromStatus' => $attributes['from_status'] ?? null,
                        'toStatus' => $toStatus,
                        'label' => LuponCaseWorkflow::LABELS[$toStatus] ?? $toStatus,
                        'notes' => $attributes['notes'] ?? null,
                        'occurredAt' => $occurredAt ? Carbon::parse($occurredAt)->format('M j, Y g:i A') : null,
                    ];
                })),
        ]);
    }

    public function tagapamayapa(Request $request): Response
    {
        $user = $request->user();

        if (! $user->hasActiveAssignment(['lupon'])) {
            abort(403, 'Unauthorized access to Lupon Portal.');
        }

        return Inertia::render('Lupon/Tagapamayapa', [
            'barangayName' => $user->barangay?->name,
        ]);
    }
}
