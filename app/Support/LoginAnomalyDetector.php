<?php

namespace App\Support;

use App\Models\LoginAttempt;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Detection-only service for suspicious login activity (Batch E5.1).
 *
 * Counts failed login attempts for an identifier/channel/barangay within a
 * rolling window and reports whether the threshold has been reached. This
 * class never writes alerts, never modifies login attempts, and never changes
 * authentication behavior; the trusted alert writer (E5.2) consumes its
 * result.
 *
 * Detection semantics:
 * - only status = 'failed' rows are counted (success and rate_limited are
 *   excluded);
 * - null identifier_hash rows are excluded so unresolved attempts never form
 *   a phantom group;
 * - grouping is by identifier_hash and channel, adding barangay_id only when
 *   it is known; unresolved (null barangay) attempts are grouped by
 *   identifier_hash and channel alone and remain hidden from ordinary
 *   barangay-admin views;
 * - the window is the half-open range [now - 30 minutes, now].
 *
 * IP-based detection is deliberately NOT implemented here; it is deferred
 * until trusted-proxy handling is hardened.
 */
final class LoginAnomalyDetector
{
    public const THRESHOLD = 5;

    public const WINDOW_MINUTES = 30;

    /**
     * Evaluate the failed-login threshold for one identifier/channel/barangay.
     *
     * Returns a result describing the count and window even when the threshold
     * is not reached, so callers can decide how (and whether) to persist an
     * alert. Returns null when the input cannot be grouped (null hash).
     *
     * The supplied (or current) evaluation time drives both the rolling window
     * and the fixed deduplication bucket, so a result can never report one
     * bucket while having been detected in another.
     */
    public static function detect(
        string $channel,
        ?string $identifierHash,
        ?int $barangayId = null,
        ?CarbonInterface $now = null,
    ): ?LoginAnomalyResult {
        if ($identifierHash === null) {
            return null;
        }

        $evaluatedAt = $now ?? Carbon::now();

        $since = $evaluatedAt->copy()->subMinutes(self::WINDOW_MINUTES);

        $matches = LoginAttempt::query()
            ->where('status', LoginAttemptRecorder::STATUS_FAILED)
            ->whereNotNull('identifier_hash')
            ->where('channel', $channel)
            ->where('identifier_hash', $identifierHash)
            ->where('attempted_at', '>=', $since)
            ->when(
                $barangayId !== null,
                fn ($q) => $q->where('barangay_id', $barangayId)
            )
            ->orderBy('attempted_at')
            ->get(['attempted_at', 'ip_address', 'barangay_id']);

        $count = $matches->count();

        return new LoginAnomalyResult(
            channel: $channel,
            identifierHash: $identifierHash,
            barangayId: $barangayId,
            count: $count,
            thresholdReached: $count >= self::THRESHOLD,
            windowSeconds: self::WINDOW_MINUTES * 60,
            evaluatedAt: $evaluatedAt,
            since: $since,
            firstAttemptAt: $matches->first()?->attempted_at,
            lastAttemptAt: $matches->last()?->attempted_at,
            ipAddress: $matches->last()?->ip_address,
        );
    }

    /**
     * Compute the fixed 30-minute deduplication bucket for a detection.
     *
     * Alerts within the same bucket share an identity so the trusted writer
     * can deduplicate atomically. Because the bucket boundary is fixed, a new
     * alert can be raised less than a full window after the previous one; this
     * is an accepted consequence of fixed buckets.
     *
     * The evaluation time defaults to the current time so ordinary callers can
     * compute a key without threading a timestamp, but callers that evaluated a
     * window at a specific instant must pass that same instant so detection and
     * deduplication stay aligned.
     */
    public static function dedupKey(
        string $channel,
        string $identifierHash,
        ?int $barangayId,
        ?CarbonInterface $now = null,
    ): string {
        $evaluatedAt = $now ?? Carbon::now();

        $bucket = intdiv($evaluatedAt->getTimestamp(), self::WINDOW_MINUTES * 60);

        return hash(
            'sha256',
            $identifierHash.'|'.$channel.'|'.($barangayId ?? 'null').'|'.$bucket
        );
    }
}
