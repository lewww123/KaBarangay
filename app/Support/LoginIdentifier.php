<?php

namespace App\Support;

use App\Models\Barangay;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Shared identifier hashing and rate-limit key helpers for login attempts.
 *
 * The HMAC implementation here is the single source of truth used by both
 * {@see LoginAttemptRecorder} and the named login rate limiters, so a stored
 * identifier_hash and its limiter key always correlate. Identifiers are
 * normalized in memory and only ever persisted/used as an HMAC-SHA256 digest;
 * raw identifiers, passwords, tokens, and session data are never stored.
 *
 * Fail-closed key handling (Batch E3.2): when the application key is missing,
 * empty, or malformed, a non-empty identifier raises instead of hashing with
 * an empty or default key. Empty/absent identifiers return null and are never
 * grouped together.
 */
final class LoginIdentifier
{
    /**
     * Placeholder used when the client address cannot be trusted as a real
     * IP (loopback, invalid, or missing REMOTE_ADDR).
     */
    public const LOCAL_ADDRESS = 'local';

    /**
     * Normalize the identifier and return its HMAC-SHA256 hex digest. The raw
     * value never leaves this method. Empty or absent identifiers yield null.
     */
    public static function fingerprint(?string $identifier): ?string
    {
        if ($identifier === null) {
            return null;
        }

        $normalized = mb_strtolower(trim($identifier));

        if ($normalized === '') {
            return null;
        }

        $key = self::hmacKey();

        if ($key === null) {
            throw new InvalidArgumentException(
                'Login attempt HMAC key is not configured.'
            );
        }

        return hash_hmac('sha256', $normalized, $key);
    }

    /**
     * Rate-limit key for an identifier-based limit. Callers must only build
     * this key for identifiers that produce a fingerprint.
     */
    public static function identifierKey(string $channel, string $identifier): string
    {
        return 'login:'.$channel.':uid:'.self::fingerprint($identifier);
    }

    /**
     * Rate-limit key for the shared per-IP limit.
     */
    public static function ipKey(string $channel, string $ip): string
    {
        return 'login:'.$channel.':ip:'.$ip;
    }

    /**
     * Rate-limit key for the resident-specific controller-managed counter.
     *
     * The key is derived from the server-resolved resident database ID, never
     * from the submitted resident number, mobile number, or email, so every
     * identifier form for the same resident shares one bucket.
     *
     * This counter is managed by the controller (RateLimiter::tooManyAttempts/
     * hit/clear) rather than by ThrottleRequests middleware, so callers must
     * pass this raw key to all three operations directly and must NOT route it
     * through {@see middlewareKey()}. The "rid" namespace is distinct from the
     * "uid" and "ip" namespaces, so it never collides with E4 keys.
     */
    public static function residentKey(int $residentId): string
    {
        return 'login:resident:rid:'.$residentId;
    }

    /**
     * Reproduce the cache key that Laravel's ThrottleRequests middleware
     * derives for a named limiter.
     *
     * The middleware hashes each key as md5($limiterName.$limitKey) because
     * key hashing is enabled by default. RateLimiter::clear() does not hash,
     * so callers that clear a limiter must clear this derived key, not the
     * raw key passed to Limit::by().
     */
    public static function middlewareKey(string $limiterName, string $rawKey): string
    {
        return md5($limiterName.$rawKey);
    }

    /**
     * Resolve the client address from REMOTE_ADDR only.
     *
     * This deliberately ignores X-Forwarded-For, Request::ip(), and
     * Request::getClientIp(), which honor forwarded headers because the app
     * trusts all proxies. Local or untrustworthy values collapse to the
     * constant "local" bucket. Deployment proxy hardening (an explicit
     * trusted-proxy list) is tracked as a separate future batch.
     */
    public static function clientIp(Request $request): string
    {
        $remoteAddress = $request->server->get('REMOTE_ADDR');

        if (! is_string($remoteAddress) || trim($remoteAddress) === '') {
            return self::LOCAL_ADDRESS;
        }

        $remoteAddress = trim($remoteAddress);

        if (filter_var($remoteAddress, FILTER_VALIDATE_IP) === false) {
            return self::LOCAL_ADDRESS;
        }

        if ($remoteAddress === '127.0.0.1' || $remoteAddress === '::1') {
            return self::LOCAL_ADDRESS;
        }

        return $remoteAddress;
    }

    /**
     * Resolve the session barangay for recording only when it points at an
     * active barangay; otherwise null so unresolved attempts stay out of
     * ordinary barangay admin views.
     */
    public static function barangayIdFromSession(Request $request): ?int
    {
        $barangayId = $request->session()->get('selected_barangay_id');

        if (is_string($barangayId) && ctype_digit($barangayId)) {
            $barangayId = (int) $barangayId;
        }

        if (! is_int($barangayId)) {
            return null;
        }

        $isPermitted = Barangay::query()
            ->where('id', $barangayId)
            ->where('is_active', true)
            ->exists();

        return $isPermitted ? $barangayId : null;
    }

    /**
     * Use the application key as raw HMAC key material, decoding the
     * conventional "base64:" prefix when present. Returns null when the key
     * is missing, empty, or cannot be decoded strictly, so callers never
     * hash with an unkeyed or default key.
     */
    private static function hmacKey(): ?string
    {
        $key = config('app.key');

        if (! is_string($key) || trim($key) === '') {
            return null;
        }

        if (! str_starts_with($key, 'base64:')) {
            return $key;
        }

        $decoded = base64_decode(substr($key, 7), true);

        if ($decoded === false || $decoded === '') {
            return null;
        }

        return $decoded;
    }
}
