<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResidentRegistrationCorrectionCycle extends Model
{
    protected $fillable = [
        'resident_registration_id',
        'cycle_number',
        'instructions',
        'field_targets',
        'document_targets',
        'prior_field_snapshot',
        'reviewer_id',
        'requested_at',
        'resubmitted_at',
    ];

    protected function casts(): array
    {
        return [
            'cycle_number' => 'integer',
            'field_targets' => 'array',
            'document_targets' => 'array',
            'prior_field_snapshot' => 'array',
            'requested_at' => 'datetime',
            'resubmitted_at' => 'datetime',
        ];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(ResidentRegistration::class, 'resident_registration_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
