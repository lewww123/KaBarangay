<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property int $fiscal_year
 * @property string $name
 * @property string $fund_type
 * @property string $category
 * @property string $source
 * @property string $amount_approved
 * @property string|null $reference_no
 * @property string|null $status
 * @property int|null $created_by
 * @property-read User|null $createdBy
 * @property-read HasMany<BudgetAmendment, $this> $budgetAmendments
 * @property-read HasMany<Disbursement, $this> $disbursements
 */
class Allocation extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'fiscal_year',
        'name',
        'fund_type',
        'category',
        'source',
        'amount_approved',
        'reference_no',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'amount_approved' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<BudgetAmendment, $this>
     */
    public function budgetAmendments(): HasMany
    {
        return $this->hasMany(BudgetAmendment::class);
    }

    /**
     * @return HasMany<Disbursement, $this>
     */
    public function disbursements(): HasMany
    {
        return $this->hasMany(Disbursement::class);
    }
}
