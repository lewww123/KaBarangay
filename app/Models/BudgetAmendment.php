<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $allocation_id
 * @property string $amount
 * @property string|null $reference_no
 * @property string|null $reason
 * @property int|null $created_by
 * @property Carbon $created_at
 * @property-read Allocation $allocation
 * @property-read User|null $createdBy
 */
class BudgetAmendment extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'allocation_id',
        'amount',
        'reference_no',
        'reason',
        'created_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'created_at' => 'datetime',
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
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
