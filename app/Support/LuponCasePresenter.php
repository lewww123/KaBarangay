<?php

namespace App\Support;

use App\Models\LuponCase;
use App\Models\OrgChartPosition;
use App\Models\Resident;
use App\Models\StaffAssignment;

final class LuponCasePresenter
{
    public static function details(LuponCase $case, ?Resident $viewer = null): array
    {
        $staff = $viewer === null;
        $evidence = $case->evidence()->when(! $staff, function ($query) use ($viewer, $case) {
            $query->where(function ($query) use ($viewer, $case) {
                $query->where('resident_id', $viewer->id)->orWhere('visible_to_parties', true);
                if ($case->complaint?->resident_id === $viewer->id) {
                    $query->orWhere('complaint_id', $case->complaint_id);
                }
            });
        })->get();
        $documents = $case->documents()->when(! $staff, function ($query) {
            $query->where('status', 'ready')->where(function ($q) {
                $q->whereNotIn('kind', ['notice_of_hearing', 'summons'])->orWhereNotNull('served_at');
            });
        })->get();

        return ['luponMembers' => $staff ? StaffAssignment::query()->where('barangay_id', $case->barangay_id)->where('department', 'lupon')->where('is_active', true)->with(['user', 'resident'])->get()->map(fn ($assignment) => $assignment->user?->name ?? $assignment->resident?->full_name)->filter()->merge(OrgChartPosition::query()->where('barangay_id', $case->barangay_id)->where('status', 'published')->where(function ($query) {
            $query->where('title', 'like', '%lupon%')->orWhere('title', 'like', '%pangkat%');
        })->pluck('assigned_person'))->filter()->unique()->values()->all() : [], 'hearingActionsUnlocked' => (bool) config('lupon.hearing_actions_unlocked', true), 'workflow' => LuponCaseWorkflow::timeline($case, $staff), 'trackingStatus' => LuponCaseWorkflow::tracking($case), 'actingCaptainId' => $case->acting_captain_id, 'paymentStatus' => $case->payment_status,
            'documents' => $documents->map(fn ($doc) => LuponDocuments::summary($doc, $staff ? 'lupon' : 'resident'))->all(),
            'evidence' => $evidence->map(fn ($file) => LuponEvidenceStore::summary($file, $staff ? 'lupon' : 'resident'))->all(),
            'intake' => ['narrative' => $staff || LuponAccess::isComplainant($viewer, $case) ? $case->intake_narrative : null, 'requestedAction' => $staff || LuponAccess::isComplainant($viewer, $case) ? $case->intake_requested_action : null, 'incidentDate' => $case->incident_date?->format('F j, Y'), 'incidentLocation' => $case->incident_location],
        ];
    }
}
