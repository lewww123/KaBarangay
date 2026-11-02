<?php

namespace App\Support;

use App\Models\LuponCase;
use App\Models\LuponCaseEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class LuponCaseWorkflow
{
    public const LABELS = [
        'filed' => 'Filed', 'mediation_active' => 'Mediation active', 'pangkat_formed' => 'Pangkat formed',
        'conciliation_active' => 'Conciliation active', 'settled' => 'Settled', 'repudiated' => 'Repudiated',
        'dismissed' => 'Dismissed', 'certified_to_file_action' => 'Certified to file action',
    ];

    public const TRANSITIONS = [
        'filed' => ['mediation_active', 'dismissed'],
        'mediation_active' => ['pangkat_formed', 'settled', 'dismissed'],
        'pangkat_formed' => ['conciliation_active', 'settled', 'dismissed'],
        'conciliation_active' => ['settled', 'dismissed', 'certified_to_file_action'],
        'settled' => ['repudiated'], 'repudiated' => ['certified_to_file_action'],
        'dismissed' => [], 'certified_to_file_action' => [],
    ];

    public static function today(): string
    {
        return now(config('lupon.timezone', 'Asia/Manila'))->toDateString();
    }

    public static function normalize(string $status): string
    {
        return ['Active' => 'filed', 'Under Mediation' => 'mediation_active', 'Under Conciliation' => 'conciliation_active', 'Settled' => 'settled', 'Unresolved' => 'conciliation_active', 'Dismissed' => 'dismissed'][$status] ?? $status;
    }

    public static function phase(string $status): string
    {
        return match ($status) {
            'filed','mediation_active' => 'Kapitan', 'pangkat_formed','conciliation_active' => 'Lupon', default => 'Closed'
        };
    }

    public static function intake(LuponCase $case, int $actor): void
    {
        $next = Carbon::parse($case->filed_date)->startOfDay()->addDay();
        while ($next->isWeekend() || in_array($next->toDateString(), config('lupon.holidays', []), true)) {
            $next->addDay();
        }
        $case->update(['status' => 'filed', 'phase' => 'Kapitan', 'summons_due_at' => $next->endOfDay()]);
        self::event($case, null, 'filed', $actor, now(), 'Case docketed.');
    }

    public static function transition(LuponCase $case, string $target, int $actor, array $data = []): LuponCase
    {
        $target = self::normalize($target);

        return DB::transaction(function () use ($case, $target, $actor, $data) {
            $locked = LuponCase::query()->whereKey($case->id)->where('barangay_id', $case->barangay_id)->lockForUpdate()->firstOrFail();
            $current = $locked->status;
            if (isset($data['expected_status']) && self::normalize($data['expected_status']) !== $current) {
                self::fail('This case changed while you were editing. Refresh and review the current status.');
            }
            if ($target === $current) {
                return $locked;
            }
            if (! in_array($target, self::TRANSITIONS[$current] ?? [], true)) {
                self::fail('This case cannot move from '.(self::LABELS[$current] ?? $current).' to '.(self::LABELS[$target] ?? $target).'.');
            }
            $date = Carbon::parse($data['event_date'] ?? self::today())->startOfDay();
            if ($date->isAfter(Carbon::parse(self::today())->endOfDay()) || $date->isBefore($locked->filed_date->startOfDay())) {
                self::fail('The event date must be between filing and today.');
            }
            $previousDate = $locked->repudiated_at ?? $locked->settled_at ?? $locked->conciliation_started_at ?? $locked->pangkat_formed_at ?? $locked->mediation_started_at ?? $locked->filed_date;
            if ($date->lt($previousDate)) {
                self::fail('The action date cannot precede the previous recorded case milestone.');
            }
            $changes = ['status' => $target, 'phase' => self::phase($target)];
            $notes = trim($data['notes'] ?? '');
            if ($target === 'mediation_active') {
                if (empty($data['event_date'])) {
                    self::fail('Record the date the parties first met for mediation.');
                }
                $changes += ['mediation_started_at' => $date, 'mediation_deadline_at' => $date->copy()->addDays(15)->endOfDay()];
            }
            if ($target === 'pangkat_formed') {
                if (! $locked->mediation_started_at || $date->lt($locked->mediation_started_at)) {
                    self::fail('Record the first mediation meeting before constituting the Pangkat.');
                }
                $members = array_map('trim', $data['pangkat_members'] ?? []);
                if (count($members) !== 3 || count(array_unique(array_map('mb_strtolower', $members))) !== 3 || in_array('', $members, true)) {
                    self::fail('Record three distinct appointed Pangkat members.');
                }
                if (! in_array($data['pangkat_chair_name'] ?? '', $members, true) || ! in_array($data['pangkat_secretary_name'] ?? '', $members, true) || $data['pangkat_chair_name'] === $data['pangkat_secretary_name']) {
                    self::fail('Choose different Pangkat chair and secretary from the three members.');
                }
                if ($notes === '') {
                    self::fail('Record the unsuccessful mediation and Pangkat constitution details.');
                }
                $changes += ['pangkat_formed_at' => $date, 'pangkat_members' => $members, 'pangkat_chair_name' => $data['pangkat_chair_name'], 'pangkat_secretary_name' => $data['pangkat_secretary_name']];
            }
            if ($target === 'conciliation_active') {
                if (! $locked->pangkat_formed_at || $date->lt($locked->pangkat_formed_at)) {
                    self::fail('Constitute the Pangkat before recording its first conciliation meeting.');
                }
                if (empty($data['event_date'])) {
                    self::fail('Record the first Pangkat meeting date.');
                }
                $changes += ['conciliation_started_at' => $date, 'conciliation_deadline_at' => $date->copy()->addDays(15)->endOfDay()];
            }
            if ($target === 'settled') {
                $settlement = $locked->settlement()->first();
                if (! $settlement || ! filled($settlement->terms)) {
                    self::fail('Record the written settlement and its actual date before marking the case settled.');
                }
                $changes['settled_at'] = $settlement->settlement_date->startOfDay();
            }
            if ($target === 'repudiated') {
                if (! $locked->settled_at || $date->lt($locked->settled_at) || $date->gt($locked->settled_at->copy()->addDays(10)->endOfDay())) {
                    self::fail('Repudiation must have been filed within ten days of the settlement.');
                }
                if (! in_array($data['repudiation_ground'] ?? '', ['fraud', 'violence', 'intimidation'], true) || empty($data['sworn_statement_received']) || $notes === '') {
                    self::fail('Record the sworn statement, the fraud/violence/intimidation ground, and the filing details.');
                }
                $changes['repudiated_at'] = $date;
                $locked->settlement()->update(['status' => 'Repudiated']);
            }
            if ($target === 'dismissed') {
                if ($notes === '') {
                    self::fail('Record the reason and authority for dismissal.');
                }
                $changes['dismissed_at'] = $date;
            }
            if ($target === 'certified_to_file_action') {
                if (empty($data['prerequisites_verified']) || $notes === '') {
                    self::fail('The authorized Lupon staff must verify and record the grounds for issuing a CFA.');
                }
                if ($current === 'conciliation_active') {
                    if (! $locked->mediation_started_at || ! $locked->conciliation_started_at || ! $locked->pangkat_chair_name || ! $locked->pangkat_secretary_name || ! $locked->hearings()->where('phase', 'Lupon')->where('status', 'Held')->exists()) {
                        self::fail('Record the Pangkat officials and a completed conciliation hearing before certifying failed conciliation.');
                    }
                    if ($locked->settlement()->where('status', 'Active')->exists()) {
                        self::fail('A case with an active settlement cannot be certified as failed conciliation.');
                    }
                }
                $changes['cfa_issued_at'] = $date;
            }
            $locked->update($changes);
            self::event($locked, $current, $target, $actor, $date, $notes, array_intersect_key($data, array_flip(['repudiation_ground', 'sworn_statement_received', 'prerequisites_verified'])));

            return $locked->fresh();
        });
    }

    public static function extend(LuponCase $case, int $actor, int $days, string $notes): void
    {
        DB::transaction(function () use ($case, $actor, $days, $notes) {
            $locked = LuponCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'conciliation_active' || ! $locked->conciliation_deadline_at || $locked->conciliation_extended_until || $days < 1 || $days > 15 || trim($notes) === '') {
                self::fail('Only active conciliation may receive a single, reasoned extension of up to fifteen days.');
            }
            $locked->update(['conciliation_extended_until' => $locked->conciliation_deadline_at->copy()->addDays($days)]);
            self::event($locked, $locked->status, $locked->status, $actor, now(), $notes, ['extension_days' => $days]);
        });
    }

    public static function completeHistory(LuponCase $case, int $actor, array $data): void
    {
        DB::transaction(function () use ($case, $actor, $data) {
            $locked = LuponCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['mediation_active', 'pangkat_formed', 'conciliation_active'], true)) {
                self::fail('Historical meeting dates may only be completed on active cases.');
            }
            if ($locked->status === 'mediation_active' && (! empty($data['pangkat_formed_at']) || ! empty($data['conciliation_started_at']) || ! empty($data['pangkat_members']))) {
                self::fail('Use the Pangkat constitution action to advance a mediation case.');
            }
            if ($locked->status === 'pangkat_formed' && ! empty($data['conciliation_started_at'])) {
                self::fail('Record the first conciliation meeting using the case action or hearing outcome.');
            }
            $changes = [];
            $previous = $locked->filed_date->copy()->startOfDay();
            foreach (['mediation_started_at', 'pangkat_formed_at', 'conciliation_started_at'] as $field) {
                $value = $locked->$field;
                if (! empty($data[$field])) {
                    $requested = Carbon::parse($data[$field])->startOfDay();
                    if ($value && ! $requested->isSameDay($value)) {
                        self::fail('Existing verified dates cannot be silently overwritten.');
                    }
                    if (! $value) {
                        $changes[$field] = $requested;
                    }
                    $value = $requested;
                }
                if ($value) {
                    if ($value->lt($previous) || $value->gt(Carbon::parse(self::today())->endOfDay())) {
                        self::fail('Historical milestones must be in chronological order between filing and today.');
                    }
                    $previous = $value->copy();
                }
            }
            if (isset($changes['mediation_started_at'])) {
                $changes['mediation_deadline_at'] = $changes['mediation_started_at']->copy()->addDays(15)->endOfDay();
            }
            if (isset($changes['conciliation_started_at'])) {
                $changes['conciliation_deadline_at'] = $changes['conciliation_started_at']->copy()->addDays(15)->endOfDay();
            }
            if (! $locked->pangkat_members && ! empty($data['pangkat_members'])) {
                $members = array_map('trim', $data['pangkat_members']);
                if (count($members) !== 3 || count(array_unique(array_map('mb_strtolower', $members))) !== 3 || in_array('', $members, true)
                    || ! in_array($data['pangkat_chair_name'] ?? '', $members, true) || ! in_array($data['pangkat_secretary_name'] ?? '', $members, true) || $data['pangkat_chair_name'] === $data['pangkat_secretary_name']) {
                    self::fail('Record three distinct Pangkat members and choose a different chair and secretary.');
                }
                $changes += ['pangkat_members' => $members, 'pangkat_chair_name' => $data['pangkat_chair_name'], 'pangkat_secretary_name' => $data['pangkat_secretary_name']];
            }
            if (! $changes || trim($data['notes'] ?? '') === '') {
                self::fail('Supply missing historical milestones and the source of the verified dates.');
            }
            $locked->update($changes);
            self::event($locked, $locked->status, $locked->status, $actor, now(), $data['notes'], ['action' => 'historical_dates_recorded']);
        });
    }

    public static function event(LuponCase $case, ?string $from, string $to, ?int $actor, $date, ?string $notes = null, array $metadata = []): void
    {
        LuponCaseEvent::create(['case_id' => $case->id, 'from_status' => $from, 'to_status' => $to, 'actor_id' => $actor, 'occurred_at' => $date, 'notes' => $notes, 'metadata' => $metadata]);
    }

    public static function tracking(LuponCase $case): string
    {
        return match ($case->status) {
            'settled' => 'Settled', 'certified_to_file_action' => 'CFA Issued', 'dismissed' => 'Dismissed', 'repudiated' => 'Under Review',
            default => $case->form_7_issued_at ? 'Summoned' : 'Under Review',
        };
    }

    public static function timeline(LuponCase $case, bool $staff = false): array
    {
        $case->loadMissing('events');
        $deadlines = [];
        foreach (['summons_due_at' => 'Summons target (next working day)', 'mediation_deadline_at' => 'Mediation — 15 days from first meeting', 'conciliation_deadline_at' => 'Conciliation — initial 15 days', 'conciliation_extended_until' => 'Conciliation — approved extension'] as $field => $label) {
            if ($case->$field) {
                $deadlines[] = ['label' => $label, 'date' => $case->$field->toDateString(), 'active' => match ($field) {
                    'summons_due_at' => $case->status === 'filed' && ! $case->form_7_issued_at, 'mediation_deadline_at' => $case->status === 'mediation_active', 'conciliation_deadline_at' => $case->status === 'conciliation_active' && ! $case->conciliation_extended_until, 'conciliation_extended_until' => $case->status === 'conciliation_active'
                }, 'daysRemaining' => (int) Carbon::parse(self::today())->startOfDay()->diffInDays($case->$field->copy()->startOfDay(), false)];
            }
        }
        if ($case->pangkat_formed_at) {
            $due = $case->pangkat_formed_at->copy()->addDays(3);
            $deadlines[] = ['label' => 'Pangkat convening target — 3 days from constitution', 'date' => $due->toDateString(), 'active' => $case->status === 'pangkat_formed', 'daysRemaining' => (int) Carbon::parse(self::today())->diffInDays($due, false)];
        }
        if ($case->settled_at) {
            $deadlines[] = ['label' => 'Settlement repudiation period — 10 days', 'date' => $case->settled_at->copy()->addDays(10)->toDateString(), 'active' => $case->status === 'settled' && Carbon::parse(self::today())->lte($case->settled_at->copy()->addDays(10)), 'daysRemaining' => (int) Carbon::parse(self::today())->startOfDay()->diffInDays($case->settled_at->copy()->addDays(10)->startOfDay(), false)];
        }

        return ['milestones' => ['mediation_started_at' => $case->mediation_started_at?->toDateString(), 'pangkat_formed_at' => $case->pangkat_formed_at?->toDateString(), 'conciliation_started_at' => $case->conciliation_started_at?->toDateString(), ...($staff ? ['pangkat_members' => $case->pangkat_members ?? [], 'pangkat_chair_name' => $case->pangkat_chair_name, 'pangkat_secretary_name' => $case->pangkat_secretary_name] : [])], 'status' => $case->status, 'statusLabel' => self::LABELS[$case->status] ?? $case->status, 'trackingStatus' => self::tracking($case), 'deadlines' => $deadlines,
            'transitions' => self::TRANSITIONS[$case->status] ?? [],
            'events' => $case->events->map(fn ($event) => ['id' => $event->id, 'from' => $event->from_status, 'to' => $event->to_status, 'date' => $event->occurred_at->toIso8601String(), ...($staff ? ['notes' => $event->notes, 'metadata' => $event->metadata] : [])])->all()];
    }

    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['status' => $message]);
    }
}
