<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResidentNotification extends Model
{
    protected $table = 'resident_notification_outbox';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DEFERRED = 'deferred';

    public const TYPE_APPROVAL_CONFIRMATION = 'approval_confirmation';

    public const TYPE_REGISTRATION_INVITATION = 'registration_invitation';

    public const TYPE_REGISTRATION_DECISION = 'registration_decision';

    public const TYPE_LUPON_SUMMONS_ISSUED = 'lupon_summons_issued';

    protected $fillable = [
        'event_key',
        'type',
        'resident_id',
        'resident_claim_id',
        'recipient_email',
        'recipient_name',
        'status',
        'attempts',
        'sent_at',
        'failed_at',
        'last_error',
        'resident_registration_id',
        'correction_cycle_id',
        'decision_type',
        'deferred_at',
        'lease_token',
        'lease_expires_at',
        'failure_class',
        'email_safe_reason',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'deferred_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'attempts' => 'integer',
            'context' => 'array',
        ];
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(ResidentRegistration::class, 'resident_registration_id');
    }

    public function correctionCycle(): BelongsTo
    {
        return $this->belongsTo(ResidentRegistrationCorrectionCycle::class, 'correction_cycle_id');
    }
}
