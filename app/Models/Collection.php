<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property Carbon $collection_date
 * @property string $collection_type
 * @property string $amount
 * @property string|null $or_number
 * @property string $payee_name
 * @property int|null $certificate_request_id
 * @property bool $is_waived
 * @property string|null $waive_reason
 * @property string|null $status
 * @property int|null $encoded_by
 * @property int|null $verified_by
 * @property Carbon|null $verified_at
 * @property string|null $void_reason
 * @property-read CertificateRequest|null $certificateRequest
 * @property-read CertificateRequest|null $linkedCertificateRequest
 * @property-read User|null $encodedBy
 * @property-read User|null $verifiedBy
 */
class Collection extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'collection_date',
        'collection_type',
        'amount',
        'or_number',
        'payee_name',
        'certificate_request_id',
        'is_waived',
        'waive_reason',
        'status',
        'encoded_by',
        'verified_by',
        'verified_at',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'collection_date' => 'date',
            'amount' => 'decimal:2',
            'is_waived' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CertificateRequest, $this>
     */
    public function certificateRequest(): BelongsTo
    {
        return $this->belongsTo(CertificateRequest::class);
    }

    /**
     * @return HasOne<CertificateRequest, $this>
     */
    public function linkedCertificateRequest(): HasOne
    {
        return $this->hasOne(CertificateRequest::class, 'collection_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function encodedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'encoded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
