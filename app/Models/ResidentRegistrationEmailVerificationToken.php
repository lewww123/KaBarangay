<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResidentRegistrationEmailVerificationToken extends Model
{
    protected $table = 'resident_registration_email_verification_tokens';

    protected $fillable = [
        'resident_registration_id',
        'token_hash',
        'email_snapshot',
        'expires_at',
        'consumed_at',
        'last_sent_at',
        'send_count',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'send_count' => 'integer',
        ];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(
            ResidentRegistration::class,
            'resident_registration_id'
        );
    }
}
