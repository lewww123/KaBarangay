<?php

namespace App\Support;

use App\Models\AuditLog;
use InvalidArgumentException;
use Throwable;

/**
 * Explicit, append-only audit writer.
 *
 * Call sites pass typed event data directly; nothing is inferred from the
 * request, and arbitrary payloads are never persisted. Context is filtered
 * through a fixed allowlist of small, non-sensitive keys, and a writer
 * failure is reported through Laravel's exception reporting instead of
 * breaking the business operation that triggered it.
 *
 * Catching failures here keeps workflows available; it does not make the
 * audit trail tamper-proof — a failed write is reported, not retried.
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
        'department',
        'entity_reference',
        'new_role',
        'new_status',
        'previous_role',
        'previous_status',
        'reason_code',
    ];

    private const EVENT_MAX_LENGTH = 60;

    private const ENTITY_TYPE_MAX_LENGTH = 40;

    private const CONTEXT_VALUE_MAX_LENGTH = 255;

    /**
     * Persist one audit record. Never throws: on any failure the exception is
     * reported and the caller's operation continues.
     *
     * @param  array<string, mixed>  $context  allowlisted, non-sensitive metadata only
     */
    public static function record(
        string $event,
        ?int $barangayId = null,
        ?int $actorId = null,
        ?string $entityType = null,
        ?int $entityId = null,
        array $context = [],
        ?string $ipAddress = null,
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
