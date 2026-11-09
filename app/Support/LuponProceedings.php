<?php

namespace App\Support;

use App\Models\LuponCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Records actual proceedings; never infers consent, attendance, or an award. */
final class LuponProceedings
{
    public static function record(LuponCase $case, int $actor, array $data): void
    {
        DB::transaction(function () use ($case, $actor, $data) {
            $case = LuponCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            $action = $data['action'];
            $date = $data['event_date'];
            if ($date < $case->filed_date->toDateString()) {
                self::fail('The event cannot precede filing.');
            }
            $previous = $case->status;
            $record = ['actor_id' => $actor, 'recorded_at' => now()->toIso8601String(), 'event_date' => $date, 'notes' => $data['notes']];
            if (! empty($data['evidence_id']) && ! $case->evidence()->whereKey($data['evidence_id'])->exists()) {
                self::fail('Choose supporting evidence belonging to this case.');
            }
            $record['evidence_id'] = $data['evidence_id'] ?? null;
            if ($action === 'arbitration_agreement') {
                if (! in_array($previous, ['mediation_active', 'conciliation_active'], true) || empty($data['parties_agreed']) || ! $record['evidence_id']) {
                    self::fail('Record both parties’ actual arbitration agreement and its supporting document during mediation or conciliation.');
                }
                $case->update(['status' => 'arbitration_active', 'phase' => 'Arbitration', 'arbitration_record' => ['agreement' => $record, 'sessions' => []]]);
            } elseif ($action === 'arbitration_session') {
                if ($previous !== 'arbitration_active' || empty($data['attendance'])) {
                    self::fail('An active arbitration and actual attendance/proceedings are required.');
                }
                $arbitration = $case->arbitration_record;
                if ($date < $arbitration['agreement']['event_date']) {
                    self::fail('An arbitration session cannot precede the recorded agreement.');
                }
                $arbitration['sessions'][] = [...$record, 'attendance' => $data['attendance']];
                $case->update(['arbitration_record' => $arbitration]);
            } elseif ($action === 'arbitration_award') {
                $arbitration = $case->arbitration_record;
                if ($previous !== 'arbitration_active' || empty($arbitration['sessions']) || ! $record['evidence_id']) {
                    self::fail('Record actual arbitration proceedings and attach the award before recording its issuance.');
                }
                $latest = collect($arbitration['sessions'])->max('event_date');
                if ($date < $latest) {
                    self::fail('Award issuance cannot precede the recorded proceedings.');
                }
                $arbitration['award'] = $record;
                $case->update(['status' => 'arbitration_awarded', 'phase' => 'Closed', 'arbitration_record' => $arbitration]);
            } else {
                if (! in_array($previous, ['settled', 'arbitration_awarded', 'executed'], true) || ! $record['evidence_id']) {
                    self::fail('A recorded settlement or award and a supporting document are required.');
                }
                $basisDate = $case->disposition_record['event_date'] ?? $case->arbitration_record['award']['event_date'] ?? $case->settled_at?->toDateString();
                if ($basisDate && $date < $basisDate) {
                    self::fail('Disposition cannot precede the recorded settlement, award, or execution.');
                }
                $target = $action === 'execution' ? 'executed' : 'court_forwarded';
                if ($previous === $target) {
                    self::fail('This disposition is already recorded.');
                }
                $case->update(['status' => $target, 'phase' => 'Closed', 'disposition_record' => [...$record, 'action' => $action]]);
            }
            LuponCaseWorkflow::event($case, $previous, $case->status, $actor, $date, $data['notes'], ['action' => $action, 'evidence_id' => $record['evidence_id']]);
            AuditLogger::record('lupon_case.proceeding_recorded', barangayId: $case->barangay_id, actorId: $actor,
                entityType: 'lupon_case', entityId: $case->id, context: ['action' => $action, 'entity_reference' => $case->case_number, 'previous_status' => $previous, 'new_status' => $case->status]);
        });
    }

    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['proceeding' => $message]);
    }
}
