<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Trusted writer for suspicious-login alerts (Batch E5.2).
 *
 * Consumes a read-only {@see LoginAnomalyResult} and persists at most one
 * alert per deduplication bucket. Like LoginAttemptRecorder, this writer never
 * throws: any failure is reported and the caller's login flow continues
 * unchanged.
 *
 * The only field derived from login input is the HMAC identifier_hash carried
 * by the result; raw identifiers, passwords, tokens, and session data are
 * never accepted or persisted.
 *
 * Deduplication authority is the database: the writer performs an atomic
 * insert-or-ignore against the unique dedup_key index, so concurrent crossings
 * in the same bucket insert exactly one row and later inserts are silently
 * skipped. Because every NOT NULL column is always supplied, the only
 * constraint that can be ignored for this payload is the unique dedup_key.
 *
 * The alert model applies a null-blocking barangay global scope for ordinary
 * reads. This writer is the single trusted, unauthenticated write path and uses
 * the raw query builder, which is not subject to that Eloquent global scope. No
 * read path may use this bypass.
 */
final class SuspiciousAlertRecorder
{
    public const ALERT_TYPE = 'failed_login_threshold';

    private const TABLE = 'suspicious_login_alerts';

    /**
     * Persist an alert for a detection result. Never throws.
     *
     * No alert is written until the detector reports the threshold reached.
     * A duplicate dedup_key is treated as an already-active alert and is
     * never used to update the existing row.
     */
    public static function record(LoginAnomalyResult $result): void
    {
        if (! $result->thresholdReached) {
            return;
        }

        if ($result->identifierHash === null) {
            return;
        }

        try {
            DB::table(self::TABLE)->insertOrIgnore([
                'barangay_id' => $result->barangayId,
                'channel' => $result->channel,
                'identifier_hash' => $result->identifierHash,
                'alert_type' => self::ALERT_TYPE,
                'attempt_count' => $result->count,
                'window_seconds' => $result->windowSeconds,
                'first_attempt_at' => $result->firstAttemptAt ?? $result->evaluatedAt,
                'last_attempt_at' => $result->lastAttemptAt ?? $result->evaluatedAt,
                'ip_address' => self::sanitizeIp($result->ipAddress),
                'dedup_key' => $result->dedupKey(),
                'created_at' => Carbon::now(),
            ]);
        } catch (Throwable $exception) {
            // The atomic insert-or-ignore already treats a duplicate dedup_key
            // as "do nothing". Any exception reaching here is unexpected (a
            // malformed statement, an unsupported driver, or a genuine
            // constraint failure) and is reported rather than swallowed.
            report($exception);
        }
    }

    /**
     * Store only structurally valid IP addresses; anything else becomes null.
     *
     * Mirrors the login-attempt recorder rule locally so a private method is
     * not widened solely for reuse. Forwarded headers are never consulted and
     * no IP-based detection is performed here.
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
