<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CertificateRequest extends Model
{
    protected $fillable = [
        'barangay_id',
        'resident_id',
        'certificate_id',
        'reference',
        'purpose',
        'fee',
        'status',
        'business_name',
        'business_address',
        'additional_details',
        'admin_note',
        'submitted_at',
        'reviewed_at',
        'processing_at',
        'ready_for_claim_at',
        'released_at',
        'rejected_at',
    ];

    protected function casts(): array
    {
        return [
            'fee' => 'decimal:2',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'processing_at' => 'datetime',
            'ready_for_claim_at' => 'datetime',
            'released_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class);
    }
}