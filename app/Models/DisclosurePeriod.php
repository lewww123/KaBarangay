<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $barangay_id
 * @property int $year
 * @property int $month
 * @property bool $is_published
 * @property int|null $published_by
 * @property Carbon|null $published_at
 * @property-read User|null $publishedBy
 * @property-read \App\Models\Barangay $barangay
 */
class DisclosurePeriod extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'barangay_id',
        'year',
        'month',
        'is_published',
        'published_by',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Barangay::class);
    }
}
