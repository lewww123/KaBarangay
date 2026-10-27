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

    public const TYPE_APPROVAL_CONFIRMATION = 'approval_confirmation';

    public const TYPE_CLAIM_INVITATION = 'claim_invitation';

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
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(ResidentClaim::class, 'resident_claim_id');
    }
}
