<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Immutable result of one suspicious-login detection evaluation (Batch E5.1).
 *
 * Carries everything the trusted alert writer (E5.2) needs to build an alert
 * payload, without storing or mutating anything itself. Contains no raw
 * identifiers: only the HMAC identifier_hash is retained.
 *
 * Dates are typed against CarbonInterface so both mutable and immutable Carbon
 * values are accepted; the application configures immutable dates globally
 * (Date::use(CarbonImmutable::class)), so Eloquent casts the retyped
 * attempted_at values as CarbonImmutable.
 */
final class LoginAnomalyResult
{
    public function __construct(
        public readonly string $channel,
        public readonly string $identifierHash,
        public readonly ?int $barangayId,
        public readonly int $count,
        public readonly bool $thresholdReached,
        public readonly int $windowSeconds,
        public readonly CarbonInterface $evaluatedAt,
        public readonly CarbonInterface $since,
        public readonly ?CarbonInterface $firstAttemptAt,
        public readonly ?CarbonInterface $lastAttemptAt,
        public readonly ?string $ipAddress,
    ) {}

    /**
     * Fixed 30-minute deduplication identity for this detection.
     *
     * The bucket is derived from the same evaluation timestamp that produced
     * the detection window, so detection and deduplication never disagree
     * about which 30-minute bucket an alert belongs to.
     */
    public function dedupKey(): string
    {
        return LoginAnomalyDetector::dedupKey(
            $this->channel,
            $this->identifierHash,
            $this->barangayId,
            $this->evaluatedAt,
        );
    }
}
