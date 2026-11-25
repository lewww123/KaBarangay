<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $record_type
 * @property string|null $record_id
 * @property string $title
 * @property string $file_path
 * @property int|null $uploaded_by
 * @property Carbon $uploaded_at
 * @property-read Model|null $record
 * @property-read User|null $uploadedBy
 */
class FinancialAttachment extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'record_type',
        'record_id',
        'title',
        'file_path',
        'uploaded_by',
        'uploaded_at',
    ];

    protected $hidden = [
        'file_path',
    ];

    protected function casts(): array
    {
        return [
            'uploaded_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function record(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
