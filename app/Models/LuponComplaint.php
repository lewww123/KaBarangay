<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LuponComplaint extends Model
{
    protected $fillable = [
        'barangay_id',
        'resident_id',
        'reference',
        'respondent_name',
        'respondent_resident_status',
        'respondent_address',
        'respondent_contact',
        'complaint_type',
        'incident_date',
        'incident_time',
        'incident_location',
        'narrative',
        'requested_action',
        'status',
        'lupon_note',
        'submitted_at',
        'reviewed_at',
        'rejected_at',
        'assessed_fee',
        'payment_requested_at',
        'submission_token',
        'payment_verification',
        'complainant_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'incident_date' => 'date',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'rejected_at' => 'datetime',
            'assessed_fee' => 'decimal:2',
            'payment_requested_at' => 'datetime',
            'payment_verification' => 'array',
            'complainant_snapshot' => 'array',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Barangay
    |--------------------------------------------------------------------------
    */

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Complainant
    |--------------------------------------------------------------------------
    */

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Promoted Case
    |--------------------------------------------------------------------------
    */

    public function evidence(): HasMany
    {
        return $this->hasMany(LuponEvidence::class, 'complaint_id');
    }

    public function case(): HasOne
    {
        return $this->hasOne(LuponCase::class, 'complaint_id');
    }
}
