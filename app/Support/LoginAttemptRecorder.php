<?php

namespace App\Support;

use App\Models\LoginAttempt;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Throwable;

/**
 * Recorder for authentication attempts (staff, department, resident).
 *
 * Like AuditLogger, this writer never throws: a validation or database
 * failure is reported and the caller's login flow continues unchanged. The
 * attempted identifier is normalized in memory and stored only as an
 * HMAC-SHA256 hash; raw identifiers, passwords, password hashes, tokens,
 * session data, and device fingerprints are never persisted.
 *
 * Records are written to login_attempts, a separate trail from audit_logs,
 * so existing audit behavior is untouched.
 */
final class LoginAttemptRecorder
{
    public const ACTOR_STAFF = 'staff';

    public const ACTOR_RESIDENT = 'resident';

    public const CHANNEL_DEPARTMENT = 'department';

    public const CHANNEL_STAFF = 'staff';

    public const CHANNEL_RESIDENT = 'resident';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_RATE_LIMITED = 'rate_limited';

    /**
     * @var list<string>
     */
    private const ACTOR_TYPES = [
        self::ACTOR_STAFF,
        self::ACTOR_RESIDENT,
    ];

    /**
     * @var list<string>
     */
    private const CHANNELS = [
        self::CHANNEL_DEPARTMENT,
        self::CHANNEL_STAFF,
        self::CHANNEL_RESIDENT,
    ];

    /**
     * @var list<string>
     */
    private const STATUSES = [
        self::STATUS_SUCCESS,
        self::STATUS_FAILED,
        self::STATUS_RATE_LIMITED,
    ];

    /**
     * Persist one login attempt. Never throws: on any failure the exception
     * is reported and the caller's login flow continues.
     *
     * @param  string|null  $identifier  the raw attempted identifier; only its HMAC hash is stored
     */
    public static function record(
        string $actorType,
        string $channel,
        string $status,
        ?string $identifier = null,
        ?int $barangayId = null,
        ?int $userId = null,
        ?int $residentId = null,
        ?string $ipAddress = null,
        ?DateTimeInterface $attemptedAt = null,
    ): void {
        $identifierHash = null;
        $attemptedAt = $attemptedAt ?? now();

        try {
            self::assertSupported($actorType, $channel, $status);

            // HMAC remains fail-closed: a missing or malformed key with a
            // non-empty identifier raises here and is reported below, so no
            // unhashed identifier or unkeyed digest is ever stored.
            $identifierHash = LoginIdentifier::fingerprint($identifier);

            LoginAttempt::create([
                'barangay_id' => $barangayId,
                'user_id' => $userId,
                'resident_id' => $residentId,
                'actor_type' => $actorType,
                'channel' => $channel,
                'identifier_hash' => $identifierHash,
                'status' => $status,
                'ip_address' => self::sanitizeIp($ipAddress),
                'attempted_at' => $attemptedAt,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            // The attempt was not persisted, so there is nothing to detect.
            return;
        }

        // Only failed attempts with a resolvable identifier can form a
        // suspicious-login group. Success and rate_limited attempts are never
        // detected.
        if ($status !== self::STATUS_FAILED || $identifierHash === null) {
            return;
        }

        self::detectAndAlert($channel, $identifierHash, $barangayId, $attemptedAt);
    }

    /**
     * Evaluate the failed-login threshold for the just-persisted attempt and
     * raise a deduplicated alert when it is reached.
     *
     * Detection and alert writing are independently fail-open: each runs in its
     * own guard and a failure is reported without escaping, so neither can
     * affect the authentication outcome or the recorded attempt.
     */
    private static function detectAndAlert(
        string $channel,
        string $identifierHash,
        ?int $barangayId,
        DateTimeInterface $attemptedAt,
    ): void {
        $result = null;

        try {
            $result = LoginAnomalyDetector::detect(
                $channel,
                $identifierHash,
                $barangayId,
                Carbon::instance($attemptedAt),
            );
        } catch (Throwable $exception) {
            report($exception);
        }

        if ($result === null) {
            return;
        }

        try {
            SuspiciousAlertRecorder::record($result);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private static function assertSupported(
        string $actorType,
        string $channel,
        string $status
    ): void {
        if (! in_array($actorType, self::ACTOR_TYPES, true)) {
            throw new InvalidArgumentException(
                'Unsupported login attempt actor type: '.$actorType
            );
        }

        if (! in_array($channel, self::CHANNELS, true)) {
            throw new InvalidArgumentException(
                'Unsupported login attempt channel: '.$channel
            );
        }

        if (! in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException(
                'Unsupported login attempt status: '.$status
            );
        }
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
