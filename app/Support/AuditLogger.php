<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Explicit, append-only audit writer.
 *
 * Call sites pass typed event data directly; nothing is inferred from the
 * request, and arbitrary payloads are never persisted. Context is filtered
 * through a fixed allowlist of small, non-sensitive keys. Ordinary writes
 * report failures instead of breaking the triggering operation; recordOrFail()
 * throws so an enclosing transaction cannot commit without its audit trail.
 */
final class AuditLogger
{
    /**
     * Context keys that may ever be persisted. Everything else (passwords,
     * tokens, session data, free-text notes, whole request payloads, ...) is
     * dropped by the writer even if a call site passes it.
     *
     * Keys follow the existing announcement trail naming (previous_status /
     * new_status) so future batches stay consistent.
     */
    private const CONTEXT_ALLOWLIST = [
        'action',
        'amount',
        'certificate_reference',
        'certificate_request_uuid',
        'collection_uuid',
        'department',
        'entity_reference',
        'entity_uuid',
        'new_role',
        'new_status',
        'or_number',
        'previous_role',
        'previous_status',
        'reason_code',
        'verified_collection_id',
    ];

    private const EVENT_MAX_LENGTH = 60;

    private const ENTITY_TYPE_MAX_LENGTH = 40;

    private const CONTEXT_VALUE_MAX_LENGTH = 255;

    public static function record(
        string $event,
        ?int $barangayId = null,
        ?int $actorId = null,
        ?string $entityType = null,
        ?int $entityId = null,
        array $context = [],
        ?string $ipAddress = null,
    ): void {
        self::persist($event, $barangayId, $actorId, $entityType, $entityId, $context, $ipAddress, false);
    }

    public static function recordOrFail(
        string $event,
        ?int $barangayId = null,
        ?int $actorId = null,
        ?string $entityType = null,
        ?int $entityId = null,
        array $context = [],
        ?string $ipAddress = null,
    ): void {
        self::persist($event, $barangayId, $actorId, $entityType, $entityId, $context, $ipAddress, true);
    }

    private static function persist(
        string $event,
        ?int $barangayId,
        ?int $actorId,
        ?string $entityType,
        ?int $entityId,
        array $context,
        ?string $ipAddress,
        bool $strict,
    ): void {
        try {
            AuditLog::create([
                'barangay_id' => $barangayId,
                'actor_id' => $actorId,
                'event' => self::sanitizeEvent($event),
                'entity_type' => self::sanitizeEntityType($entityType),
                'entity_id' => $entityId,
                'context' => self::sanitizeContext($context),
                'ip_address' => self::sanitizeIp($ipAddress),
            ]);
        } catch (Throwable $exception) {
            if ($strict) {
                throw $exception;
            }

            report($exception);
        }
    }

    private static function sanitizeEvent(string $event): string
    {
        $event = trim($event);

        if ($event === '') {
            throw new InvalidArgumentException(
                'The audit event name must not be empty.'
            );
        }

        if (mb_strlen($event) > self::EVENT_MAX_LENGTH) {
            throw new InvalidArgumentException(
                'The audit event name must not exceed '.self::EVENT_MAX_LENGTH.' characters.'
            );
        }

        return $event;
    }

    private static function sanitizeEntityType(?string $entityType): ?string
    {
        if ($entityType === null) {
            return null;
        }

        $entityType = trim($entityType);

        if ($entityType === '') {
            return null;
        }

        if (mb_strlen($entityType) > self::ENTITY_TYPE_MAX_LENGTH) {
            throw new InvalidArgumentException(
                'The audit entity type must not exceed '.self::ENTITY_TYPE_MAX_LENGTH.' characters.'
            );
        }

        return $entityType;
    }

    /**
     * Keep only allowlisted keys holding small scalar values. Arrays,
     * objects, floats, and any other unlisted key are dropped so request
     * payloads or nested structures can never leak into storage.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, bool|int|string|null>|null
     */
    private static function sanitizeContext(array $context): ?array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            if (! is_string($key) || ! in_array($key, self::CONTEXT_ALLOWLIST, true)) {
                continue;
            }

            if (in_array($key, ['entity_uuid', 'collection_uuid', 'certificate_request_uuid'], true)) {
                if (is_string($value) && Str::isUuid(trim($value))) {
                    $sanitized[$key] = strtolower(trim($value));
                }

                continue;
            }

            if ($value === null || is_bool($value) || is_int($value)) {
                $sanitized[$key] = $value;

                continue;
            }

            if (is_string($value)) {
                $sanitized[$key] = mb_substr(
                    trim($value),
                    0,
                    self::CONTEXT_VALUE_MAX_LENGTH
                );
            }
        }

        return $sanitized === [] ? null : $sanitized;
    }

    /**
     * Store only structurally valid IP addresses; anything else becomes null.
     */
    private static function sanitizeIp(?string $ipAddress): ?string
    {
        if ($ipAddress === null || trim($ipAddress) === '') {
            return null;
        }

        $ipAddress = trim($ipAddress);

        return filter_var($ipAddress, FILTER_VALIDATE_IP) === false
            ? null
            : $ipAddress;
    }
}
