<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A raised suspicious-login alert.
 *
 * One row represents a detected failed-login threshold crossing for an
 * identifier/channel/barangay within a fixed deduplication bucket. The
 * attempted identifier is stored only as an HMAC digest; raw identifiers,
 * passwords, password hashes, tokens, session data, and device fingerprints
 * are never persisted.
 *
 * channel:    department | staff | resident
 * alert_type: failed_login_threshold
 *
 * Rows are written exclusively through the trusted alert writer (E5.2), which
 * performs an explicitly authorized global-scope bypass for its write path.
 * Ordinary reads are tenant-scoped and null-blocking via the barangay global
 * scope; controller/UI read paths must never bypass that scope.
 */
class SuspiciousLoginAlert extends Model
{
    /**
     * Append-only: alert rows have no meaningful update timestamp.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'barangay_id',
        'channel',
        'identifier_hash',
        'alert_type',
        'attempt_count',
        'window_seconds',
        'first_attempt_at',
        'last_attempt_at',
        'ip_address',
        'dedup_key',
    ];

    protected function casts(): array
    {
        return [
            'attempt_count' => 'integer',
            'window_seconds' => 'integer',
            'first_attempt_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Defense-in-depth barangay scope
        |--------------------------------------------------------------------------
        |
        | Reads are restricted to the authenticated account's own barangay so a
        | controller can never accidentally expose another barangay's alerts.
        | Rows with a NULL barangay_id are never matched: Laravel turns
        | where('barangay_id', null) into IS NULL, which would widen access, so
        | an unresolved (null) alert is scoped to match nothing instead.
        | Unauthenticated reads also match nothing.
        |
        | The trusted alert writer bypasses this scope explicitly on its write
        | path; this scope is the read-side control.
        |
        */
        static::addGlobalScope('barangay', function (Builder $builder) {
            if (! auth()->check()) {
                $builder->whereRaw('1 = 0');

                return;
            }

            $barangayId = auth()->user()->barangay_id;

            if ($barangayId === null) {
                $builder->whereRaw('1 = 0');

                return;
            }

            $builder->where('barangay_id', $barangayId);
        });
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }
}
