<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CertificateIssuance extends Model
{
    public const STATUS_ISSUED = 'issued';

    protected $attributes = ['status' => self::STATUS_ISSUED];

    protected $fillable = [
        'barangay_id',
        'certificate_request_id',
        'resident_id',
        'reference',
        'control_no',
        'certificate_type',
        'status',
        'resident_name',
        'resident_number',
        'purok',
        'fee',
        'issued_by',
        'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'fee' => 'decimal:2',
            'issued_at' => 'datetime',
        ];
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    /**
     * The resident request this issuance was generated for, when explicitly
     * linked. Manual issuances have no request link.
     */
    public function certificateRequest(): BelongsTo
    {
        return $this->belongsTo(CertificateRequest::class);
    }

    /**
     * The resident, when a registered resident was identified.
     */
    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    /**
     * The staff user who generated the document.
     */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
