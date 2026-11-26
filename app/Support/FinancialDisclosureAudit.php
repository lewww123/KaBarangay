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
            self::context(
                $event,
                $entityReference,
                $entityUuid,
                $previousStatus,
                $newStatus,
                $reasonCode,
            ),
            $request->ip(),
        );
    }

    public static function recordStrict(
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
        array $context = [],
    ): void {
        AuditLogger::recordOrFail(
            FinancialDisclosureStatus::PUBLIC_EVENT_PREFIX.$event,
            $barangayId,
            $actorId,
            'financial_'.$entityType,
            null,
            array_merge(self::context(
                $event,
                $entityReference,
                $entityUuid,
                $previousStatus,
                $newStatus,
                $reasonCode,
            ), $context),
            $request->ip(),
        );
    }

    private static function context(
        string $event,
        string $entityReference,
        ?string $entityUuid,
        ?string $previousStatus,
        ?string $newStatus,
        ?string $reasonCode,
    ): array {
        return array_filter([
            'action' => $event,
            'entity_reference' => $entityReference,
            'entity_uuid' => $entityUuid,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'reason_code' => $reasonCode,
        ], static fn ($value) => $value !== null && $value !== '');
    }
}
