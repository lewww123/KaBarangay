<?php

namespace App\Http\Controllers\Lupon;

use App\Http\Controllers\Controller;
use App\Models\LuponCase;
use App\Models\LuponHearing;
use App\Support\AuditLogger;
use App\Support\LuponAccess;
use App\Support\LuponCache;
use App\Support\LuponCaseWorkflow;
use App\Support\LuponDocuments;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LuponHearingController extends Controller
{
    private const STATUSES = ['Scheduled', 'Held', 'Cancelled', 'Rescheduled'];

    private const OUTCOMES = ['Ongoing', 'Settled', 'Unresolved', 'Reset to Next Hearing'];

    /**
     * List hearing records for the authenticated Lupon staff member's barangay.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        if (! $user->staffAssignments()->where('department', 'lupon')->where('is_active', true)->exists()) {
            abort(403, 'Unauthorized.');
        }

        $search = $request->query('search');
        $phase = $request->query('phase');
        $status = $request->query('status');
        $outcome = $request->query('outcome');

        $query = LuponHearing::query()
            ->where('barangay_id', $user->barangay_id)
            ->whereHas('case', fn ($caseQuery) => $caseQuery->where('barangay_id', $user->barangay_id))
            ->with(['case:id,barangay_id,case_number,title,phase,status']);

        if (is_string($search) && trim($search) !== '') {
            $term = trim($search);
            $query->where(function ($hearingQuery) use ($term) {
                $hearingQuery
                    ->whereHas('case', function ($caseQuery) use ($term) {
                        $caseQuery->where('case_number', 'like', "%{$term}%")
                            ->orWhere('title', 'like', "%{$term}%");
                    })
                    ->orWhereHas('case.parties', fn ($partyQuery) => $partyQuery->where('name', 'like', "%{$term}%"));
            });
        }

        if (is_string($phase) && in_array($phase, ['Kapitan', 'Lupon', 'Arbitration'], true)) {
            $query->where('phase', $phase);
        } else {
            $phase = '';
        }

        if (is_string($status) && in_array($status, self::STATUSES, true)) {
            $query->where('status', $status);
        } else {
            $status = '';
        }

        if (is_string($outcome) && in_array($outcome, self::OUTCOMES, true)) {
            $query->where('outcome', $outcome);
        } else {
            $outcome = '';
        }

        return Inertia::render('Lupon/Hearings/Index', [
            'hearings' => $this->deferProp(fn () => $query
                ->orderByDesc('hearing_date')
                ->orderByDesc('hearing_number')
                ->paginate(15)
                ->withQueryString()
                ->through(fn (LuponHearing $hearing) => [
                    'id' => $hearing->id,
                    'caseId' => $hearing->case_id,
                    'caseNumber' => $hearing->case?->case_number,
                    'caseTitle' => $hearing->case?->title,
                    'caseStatus' => $hearing->case?->status,
                    'hearingNumber' => $hearing->phase_number,
                    'phase' => $hearing->phase,
                    'date' => $hearing->hearing_date->format('F j, Y'),
                    'time' => $hearing->hearing_time,
                    'venue' => $hearing->venue,
                    'status' => $hearing->status,
                    'outcome' => $hearing->outcome,
                ])),
            'filters' => [
                'search' => is_string($search) ? $search : '',
                'phase' => $phase,
                'status' => $status,
                'outcome' => $outcome,
            ],
            'phases' => ['Kapitan', 'Lupon', 'Arbitration'],
            'statuses' => self::STATUSES,
            'outcomes' => self::OUTCOMES,
        ]);
    }

    /**
     * Schedule a new hearing for the case.
     */
    public function store(Request $request, LuponCase $case): RedirectResponse
    {
        LuponAccess::staff($request, $case);
        $data = $request->validate(['hearing_date' => ['required', 'date', 'after_or_equal:'.LuponCaseWorkflow::today()], 'hearing_time' => ['required', 'date_format:H:i'], 'venue' => ['nullable', 'string', 'max:255'], 'phase' => ['nullable', 'in:Kapitan,Lupon,Arbitration'], 'previous_hearing_id' => ['nullable', 'integer'], 'previous_hearing_notes' => ['nullable', 'string', 'max:5000']]);
        DB::transaction(function () use ($request, $case, $data) {
            $locked = LuponCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['filed', 'mediation_active', 'pangkat_formed', 'conciliation_active', 'arbitration_active'], true)) {
                throw ValidationException::withMessages(['hearing_date' => 'This case is closed to new hearings.']);
            }
            $phase = LuponCaseWorkflow::phase($locked->status);
            if (isset($data['phase']) && $data['phase'] !== $phase) {
                throw ValidationException::withMessages(['phase' => 'The hearing phase must match the current case stage.']);
            }
            if ($phase !== 'Arbitration' && $locked->hearings()->where('phase', $phase)->count() >= 3) {
                throw ValidationException::withMessages(['hearing_date' => 'Tatlo lamang ang pagdinig sa Kapitan phase. Itala ang resulta ng mediation at ang pagbuo ng Pangkat bago magtakda sa Lupon phase. Para baguhin ang petsa, i-reschedule ang existing hearing.']);
            }
            if (! empty($data['previous_hearing_id'])) {
                $previous = $locked->hearings()->where('phase', $phase)->whereKey($data['previous_hearing_id'])->first();
                $latest = $locked->hearings()->where('phase', $phase)->reorder('hearing_number', 'desc')->first();
                if (! $previous || $previous->id !== $latest?->id || ! in_array($previous->status, ['Scheduled', 'Rescheduled', 'Held'], true) || $previous->outcome === 'Settled') {
                    throw ValidationException::withMessages(['hearing_date' => 'Only the latest unresolved hearing can proceed to a next schedule.']);
                }
                $meeting = Carbon::parse($previous->hearing_date->toDateString().' '.($previous->hearing_time ?? '00:00'), config('lupon.timezone', 'Asia/Manila'));
                if (! config('lupon.hearing_actions_unlocked', true) && $meeting->isFuture()) {
                    throw ValidationException::withMessages(['hearing_date' => 'Wait until the current hearing has started.']);
                }
                $previous->update(['status' => 'Held', 'outcome' => 'Unresolved', 'notes' => $data['previous_hearing_notes'] ?? $previous->notes]);
                if (in_array($locked->status, ['filed', 'pangkat_formed'], true)) {
                    $locked = LuponCaseWorkflow::transition($locked, $phase === 'Kapitan' ? 'mediation_active' : 'conciliation_active', $request->user()->id, ['event_date' => min($previous->hearing_date->toDateString(), LuponCaseWorkflow::today()), 'notes' => 'Hearing recorded unresolved; next meeting scheduled.']);
                }
            }
            if ($locked->hearings()->where('phase', $phase)->whereIn('status', ['Scheduled', 'Rescheduled'])->exists()) {
                throw ValidationException::withMessages(['hearing_date' => 'Record the current hearing outcome before scheduling another hearing.']);
            }
            self::validatePhaseDate($locked, $phase, $data['hearing_date']);
            $hearing = LuponHearing::create(['case_id' => $locked->id, 'barangay_id' => $locked->barangay_id, 'hearing_number' => ($locked->hearings()->max('hearing_number') ?? 0) + 1, 'phase' => $phase, 'hearing_date' => $data['hearing_date'], 'hearing_time' => $data['hearing_time'], 'venue' => $data['venue'] ?? 'Barangay Hall', 'status' => 'Scheduled', 'officer_id' => $request->user()->id]);
            $locked->documents()->whereNull('hearing_id')->where('status', 'pending')->whereIn('kind', ['notice_of_hearing', 'summons'])->update(['hearing_id' => $hearing->id]);
            LuponDocuments::queueNotices($locked, $hearing, $request->user()->id);
            AuditLogger::record('lupon_hearing.scheduled', barangayId: $locked->barangay_id, actorId: $request->user()->id, entityType: 'lupon_hearing', entityId: $hearing->id, context: ['case_number' => $locked->case_number, 'hearing_number' => $hearing->hearing_number]);
        });
        LuponCache::forget((int) $case->barangay_id);

        return back()->with('success', 'Hearing scheduled; notice and summons prepared for review.');
    }

    private static function validatePhaseDate(LuponCase $case, string $phase, string $date): void
    {
        if ($phase === 'Arbitration') { return; }
        $first = $case->hearings()->where('phase', $phase)->orderBy('hearing_number')->first();
        $start = $phase === 'Kapitan' ? $case->mediation_started_at : $case->conciliation_started_at;
        $deadline = $phase === 'Kapitan' ? $case->mediation_deadline_at : ($case->conciliation_extended_until ?? $case->conciliation_deadline_at);
        if ($phase === 'Lupon' && ! $first && $case->pangkat_formed_at) {
            $deadline = $case->pangkat_formed_at->copy()->addDays(3)->endOfDay();
            $start = $case->pangkat_formed_at;
        }
        $deadline ??= $first?->hearing_date->copy()->addDays(15)->endOfDay();
        if (($start && Carbon::parse($date)->lt($start)) || ($deadline && Carbon::parse($date)->gt($deadline))) {
            throw ValidationException::withMessages(['hearing_date' => 'Choose a date within the current phase period (15 days).']);
        }
    }

    public function update(Request $request, LuponHearing $hearing): RedirectResponse
    {
        $hearing->loadMissing('case');
        LuponAccess::staff($request, $hearing->case);
        abort_unless((int) $hearing->barangay_id === (int) $request->user()->barangay_id, 403);
        $data = $request->validate(['status' => ['sometimes', 'required', 'in:'.implode(',', self::STATUSES)], 'outcome' => ['nullable', 'in:'.implode(',', self::OUTCOMES)], 'notes' => ['nullable', 'string', 'max:5000'],
            'hearing_date' => ['nullable', 'required_if:status,Rescheduled', 'date', 'after_or_equal:'.LuponCaseWorkflow::today()], 'hearing_time' => ['nullable', 'required_with:hearing_date', 'date_format:H:i'], 'venue' => ['nullable', 'string', 'max:255']]);
        $data += ['status' => $hearing->status];
        DB::transaction(function () use ($hearing, $request, $data) {
            $case = LuponCase::whereKey($hearing->case_id)->lockForUpdate()->firstOrFail();
            $allowed = ['Scheduled' => ['Scheduled', 'Held', 'Cancelled', 'Rescheduled'], 'Rescheduled' => ['Rescheduled', 'Held', 'Cancelled'], 'Held' => ['Held'], 'Cancelled' => ['Cancelled']];
            $hearing->refresh();
            if (! in_array($data['status'], $allowed[$hearing->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'A completed or cancelled hearing cannot be reopened. Schedule a new hearing instead.']);
            }
            if ($data['status'] !== 'Rescheduled' && (! empty($data['hearing_date']) || ! empty($data['hearing_time']))) {
                throw ValidationException::withMessages(['hearing_date' => 'Use the reschedule action to change a meeting date or time.']);
            }
            if (! in_array($case->status, ['filed', 'mediation_active', 'pangkat_formed', 'conciliation_active', 'arbitration_active'], true) && $data['status'] !== $hearing->status) {
                throw ValidationException::withMessages(['status' => 'A closed case cannot change its hearing schedule.']);
            }
            if (! config('lupon.hearing_actions_unlocked', true) && $data['status'] === 'Held' && Carbon::parse($hearing->hearing_date->toDateString().' '.($hearing->hearing_time ?? '00:00'), config('lupon.timezone', 'Asia/Manila'))->isFuture()) {
                throw ValidationException::withMessages(['status' => 'A future hearing cannot be marked held. Record the actual meeting date.']);
            }
            if (($data['outcome'] ?? null) === 'Settled' && ! $case->settlement()->exists()) {
                throw ValidationException::withMessages(['outcome' => 'Record the written settlement before marking this hearing settled.']);
            }
            if ($hearing->phase !== LuponCaseWorkflow::phase($case->status)) {
                throw ValidationException::withMessages(['status' => 'This phase is completed. Records remain available for viewing.']);
            }
            if ($data['status'] === 'Rescheduled' && ! empty($data['hearing_date'])) {
                self::validatePhaseDate($case, $hearing->phase, $data['hearing_date']);
            }
            $hearing->update($data);
            if ($data['status'] === 'Held') {
                if ($case->status === 'filed' && $hearing->phase === 'Kapitan') {
                    LuponCaseWorkflow::transition($case, 'mediation_active', $request->user()->id, ['event_date' => config('lupon.hearing_actions_unlocked', true) ? min($hearing->hearing_date->toDateString(), LuponCaseWorkflow::today()) : $hearing->hearing_date->toDateString(), 'notes' => 'First mediation meeting recorded from hearing #'.$hearing->hearing_number]);
                }
                if ($case->status === 'pangkat_formed' && $hearing->phase === 'Lupon') {
                    LuponCaseWorkflow::transition($case, 'conciliation_active', $request->user()->id, ['event_date' => config('lupon.hearing_actions_unlocked', true) ? min($hearing->hearing_date->toDateString(), LuponCaseWorkflow::today()) : $hearing->hearing_date->toDateString(), 'notes' => 'First Pangkat conciliation meeting recorded from hearing #'.$hearing->hearing_number]);
                }
            }
            if ($data['status'] === 'Cancelled' || ($data['status'] === 'Rescheduled' && ! empty($data['hearing_date']))) {
                $case->documents()->where('hearing_id', $hearing->id)->whereIn('kind', ['notice_of_hearing', 'summons'])->where('status', 'ready')->update(['status' => 'superseded']);
                if ($data['status'] === 'Rescheduled') {
                    LuponDocuments::queueNotices($case, $hearing, $request->user()->id);
                }
            }
            AuditLogger::record('lupon_hearing.updated', barangayId: $case->barangay_id, actorId: $request->user()->id, entityType: 'lupon_hearing', entityId: $hearing->id, context: ['case_number' => $case->case_number, 'status' => $data['status']]);
        });
        LuponCache::forget((int) $hearing->barangay_id);

        return back()->with('success', 'Hearing record updated.');
    }
}
