<?php

namespace App\Support;

use Illuminate\Http\Request;

final class FinancialDisclosureAudit
{
    public static function record(
        Request $request,
        int $barangayId,
        int $actorId,
        string $event,
        string $entityType,
        string $entityReference,
        ?string $entityUuid = null,
        ?string $previousStatus = null,
        ?string $newStatus = null,
        ?string $reasonCode = null,
    ): void {
        AuditLogger::record(
            FinancialDisclosureStatus::PUBLIC_EVENT_PREFIX.$event,
            $barangayId,
            $actorId,
            'financial_'.$entityType,
            null,
            array_filter([
                'action' => $event,
                'entity_reference' => $entityReference,
                'entity_uuid' => $entityUuid,
                'previous_status' => $previousStatus,
                'new_status' => $newStatus,
                'reason_code' => $reasonCode,
            ], static fn ($value) => $value !== null && $value !== ''),
            $request->ip(),
        );
    }
}
