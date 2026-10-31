<?php

namespace App\Http\Controllers\Lupon;

use App\Http\Controllers\Controller;
use App\Models\LuponCase;
use App\Models\LuponHearing;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        if (is_string($phase) && in_array($phase, ['Kapitan', 'Lupon'], true)) {
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
                    'hearingNumber' => $hearing->hearing_number,
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
            'phases' => ['Kapitan', 'Lupon'],
            'statuses' => self::STATUSES,
            'outcomes' => self::OUTCOMES,
        ]);
    }

    /**
     * Schedule a new hearing for the case.
     */
    public function store(Request $request, LuponCase $case): RedirectResponse
    {
        $user = $request->user();

        if (! $user->staffAssignments()->where('department', 'lupon')->where('is_active', true)->exists()) {
            abort(403, 'Unauthorized.');
        }

        if ($case->barangay_id !== $user->barangay_id) {
            abort(403, 'Unauthorized.');
        }

        $validated = $request->validate([
            'hearing_date' => ['required', 'date', 'after_or_equal:today'],
            'hearing_time' => ['nullable', 'string', 'max:20'],
            'venue' => ['nullable', 'string', 'max:255'],
            'phase' => ['nullable', 'string', 'in:Kapitan,Lupon'],
        ]);

        $nextHearingNumber = ($case->hearings()->max('hearing_number') ?? 0) + 1;

        $hearing = LuponHearing::create([
            'case_id' => $case->id,
            'barangay_id' => $case->barangay_id,
            'hearing_number' => $nextHearingNumber,
            'phase' => $validated['phase'] ?? $case->phase,
            'hearing_date' => $validated['hearing_date'],
            'hearing_time' => $validated['hearing_time'] ?? null,
            'venue' => $validated['venue'] ?? 'Barangay Hall',
            'status' => 'Scheduled',
            'officer_id' => $user->id,
        ]);

        // Keep case status in sync if it was active
        if ($case->status === 'Active') {
            $case->update([
                'status' => $hearing->phase === 'Lupon' ? 'Under Conciliation' : 'Under Mediation',
            ]);
        }

        AuditLogger::record(
            event: 'lupon_hearing.scheduled',
            barangayId: $user->barangay_id,
            actorId: $user->id,
            entityType: 'lupon_hearing',
            entityId: $hearing->id,
            context: [
                'action' => 'scheduled',
                'entity_reference' => "{$case->case_number} #{$hearing->hearing_number}",
                'new_status' => 'Scheduled',
            ],
            ipAddress: $request->ip()
        );

        return redirect()->back()->with('success', "Hearing #{$nextHearingNumber} scheduled successfully.");
    }

    /**
     * Update hearing outcome, status, or notes.
     */
    public function update(Request $request, LuponHearing $hearing): RedirectResponse
    {
        $user = $request->user();

        if (! $user->staffAssignments()->where('department', 'lupon')->where('is_active', true)->exists()) {
            abort(403, 'Unauthorized.');
        }

        if ($hearing->barangay_id !== $user->barangay_id) {
            abort(403, 'Unauthorized.');
        }

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:' . implode(',', self::STATUSES)],
            'outcome' => ['nullable', 'string', 'in:' . implode(',', self::OUTCOMES)],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $hearing->update($validated);
        $hearing->loadMissing('case');

        // If outcome is Settled or Unresolved, sync case status if appropriate
        if ($validated['outcome'] === 'Settled') {
            $hearing->case->update(['status' => 'Settled']);
        } elseif ($validated['outcome'] === 'Unresolved' && $hearing->phase === 'Lupon') {
            $hearing->case->update(['status' => 'Unresolved']);
        }

        AuditLogger::record(
            event: 'lupon_hearing.updated',
            barangayId: $user->barangay_id,
            actorId: $user->id,
            entityType: 'lupon_hearing',
            entityId: $hearing->id,
            context: [
                'action' => 'updated',
                'entity_reference' => "{$hearing->case->case_number} #{$hearing->hearing_number}",
                'new_status' => $validated['status'],
            ],
            ipAddress: $request->ip()
        );

        return redirect()->back()->with('success', 'Hearing record updated.');
    }
}
