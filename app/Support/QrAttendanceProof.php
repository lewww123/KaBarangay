<?php

namespace App\Support;

use App\Models\Announcement;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Server-issued, single-use proof that a valid KaBarangay QR credential was
 * presented to the staff QR resolution endpoint for one specific
 * (staff, resident, announcement) attendance operation.
 *
 * The proof is an authenticated-encrypted payload (Laravel Crypt, AES-256 +
 * HMAC) carrying a random nonce (jti) and a short expiry. On consumption the
 * nonce is claimed with an atomic compare-and-set (Cache::add), so of two
 * concurrent submissions carrying the same proof exactly one succeeds and the
 * other is rejected as already used. The binding to staff/resident/announcement
 * means a proof minted for one operation is rejected for any other resident,
 * event, or staff account.
 */
final class QrAttendanceProof
{
    /**
     * How long, in seconds, a freshly issued proof remains valid.
     */
    private const TTL_SECONDS = 120;

    private const CACHE_PREFIX = 'qr-attendance-proof:';

    public static function issue(User $staff, Resident $resident, Announcement $announcement): string
    {
        $jti = (string) Str::uuid();

        return Crypt::encryptString(json_encode([
            'jti' => $jti,
            'staff_id' => $staff->id,
            'resident_id' => $resident->id,
            'announcement_id' => $announcement->id,
            'expires_at' => now()->addSeconds(self::TTL_SECONDS)->getTimestamp(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Validate a proof and atomically claim its nonce so it cannot be reused.
     *
     * @throws ValidationException
     */
    public static function consume(string $proof, User $staff, int $residentId, int $announcementId): void
    {
        $payload = self::decode($proof);

        $expiresAt = (int) ($payload['expires_at'] ?? 0);

        if ($expiresAt < now()->getTimestamp()) {
            throw self::invalid('The QR proof has expired. Please scan again.');
        }

        if (
            (int) ($payload['staff_id'] ?? 0) !== (int) $staff->id
            || (int) ($payload['resident_id'] ?? 0) !== $residentId
            || (int) ($payload['announcement_id'] ?? 0) !== $announcementId
        ) {
            throw self::invalid('The QR proof does not match this attendance record.');
        }

        $jti = (string) ($payload['jti'] ?? '');

        if ($jti === '') {
            throw self::invalid('The QR proof is invalid.');
        }

        // Atomically claim the nonce. Cache::add() is a compare-and-set on the
        // configured store: the database store performs an insertOrIgnore
        // against the cache table's unique key, so of two concurrent
        // submissions carrying the same proof exactly one wins this call and
        // the other receives false. The claim lives only as long as the proof
        // itself, so it can never be replayed afterwards.
        $remaining = max(1, $expiresAt - now()->getTimestamp());

        if (! Cache::add(self::CACHE_PREFIX.$jti, true, $remaining)) {
            throw self::invalid('The QR proof has already been used. Please scan again.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $proof): array
    {
        try {
            $decoded = json_decode(Crypt::decryptString($proof), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw self::invalid('The QR proof is invalid.');
        }

        if (! is_array($decoded)) {
            throw self::invalid('The QR proof is invalid.');
        }

        return $decoded;
    }

    private static function invalid(string $message): ValidationException
    {
        return ValidationException::withMessages(['qr_proof' => $message]);
    }
}
