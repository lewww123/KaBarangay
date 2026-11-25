<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property Carbon $disbursement_date
 * @property string $allocation_id
 * @property string $payee
 * @property string $purpose
 * @property string $amount
 * @property string|null $dv_number
 * @property string|null $status
 * @property int|null $prepared_by
 * @property int|null $approved_by
 * @property int|null $released_by
 * @property string|null $void_reason
 * @property-read Allocation $allocation
 * @property-read User|null $preparedBy
 * @property-read User|null $approvedBy
 * @property-read User|null $releasedBy
 */
class Disbursement extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'disbursement_date',
        'allocation_id',
        'payee',
        'purpose',
        'amount',
        'dv_number',
        'status',
        'prepared_by',
        'approved_by',
        'released_by',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'disbursement_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Allocation, $this>
     */
    public function allocation(): BelongsTo
    {
        return $this->belongsTo(Allocation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }
}
