<?php

namespace App\Http\Controllers;

use App\Models\LuponCase;
use App\Models\LuponHearing;
use Illuminate\Http\Request;
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

        return Inertia::render('Lupon/Dashboard', [
            'userName' => $user->name,
            'barangayName' => $user->barangay?->name,
            'metrics' => $this->deferProp(fn () => $this->rememberAggregate(
                "lupon:dashboard:metrics:{$user->barangay_id}",
                30,
                function () use ($user): array {
                    $counts = LuponCase::query()
                        ->where('barangay_id', $user->barangay_id)
                        ->selectRaw("COUNT(*) as filed, COUNT(CASE WHEN phase = 'Kapitan' AND status IN ('Active', 'Under Mediation') THEN 1 END) as mediation, COUNT(CASE WHEN phase = 'Lupon' AND status IN ('Active', 'Under Conciliation') THEN 1 END) as conciliation, COUNT(CASE WHEN status = 'Settled' THEN 1 END) as settled")
                        ->first();

                    return [
                        'filed' => (int) $counts->filed,
                        'mediation' => (int) $counts->mediation,
                        'conciliation' => (int) $counts->conciliation,
                        'settled' => (int) $counts->settled,
                        // KP document issuance is not yet a persisted workflow.
                        'certificatesIssued' => 0,
                    ];
                },
            )),
            'deadlines' => $this->deferProp(fn () => LuponCase::query()
                ->where('barangay_id', $user->barangay_id)
                ->whereIn('status', ['Active', 'Under Mediation', 'Under Conciliation'])
                ->where('filed_date', '<=', now()->addDays(15)->toDateString())
                ->select(['id', 'case_number', 'title', 'phase', 'status', 'filed_date'])
                ->orderBy('filed_date')
                ->limit(8)
                ->get()
                ->map(fn (LuponCase $case) => [
                    'id' => $case->id,
                    'caseNumber' => $case->case_number,
                    'title' => $case->title,
                    'phase' => $case->phase,
                    'dueDate' => $case->filed_date->copy()->addDays(15)->format('M j, Y'),
                    'overdue' => $case->filed_date->copy()->addDays(15)->isPast(),
                ])),
            'schedule' => $this->deferProp(fn () => LuponHearing::query()
                ->where('barangay_id', $user->barangay_id)
                ->where('status', 'Scheduled')
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
