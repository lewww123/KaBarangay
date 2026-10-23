<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single recorded authentication attempt.
 *
 * Security record for staff, department, and resident logins. The attempted
 * identifier is stored only as an HMAC hash; raw identifiers, passwords,
 * password hashes, tokens, session data, and device fingerprints are never
 * persisted. Unlike AuditLog, this model applies no barangay global scope so
 * unresolved (null barangay) attempts remain queryable by system-level code;
 * barangay-scoped reads must filter by barangay_id explicitly.
 *
 * actor_type: staff | resident
 * channel:    department | staff | resident
 * status:     success | failed | rate_limited
 */
class LoginAttempt extends Model
{
    protected $fillable = [
        'barangay_id',
        'user_id',
        'resident_id',
        'actor_type',
        'channel',
        'identifier_hash',
        'status',
        'ip_address',
        'attempted_at',
    ];

    protected function casts(): array
    {
        return [
            'attempted_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }
}
