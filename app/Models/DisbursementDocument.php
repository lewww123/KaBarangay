<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $barangay_id
 * @property int|null $uploaded_by
 * @property string $title
 * @property string|null $description
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $file_size
 * @property string|null $sha256
 * @property bool $is_published
 * @property Carbon|null $published_at
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Barangay|null $barangay
 * @property-read User|null $uploader
 */
class DisbursementDocument extends Model
{
    protected $fillable = [
        'barangay_id',
        'uploaded_by',
        'title',
        'description',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'file_size',
        'sha256',
        'is_published',
        'published_at',
        'archived_at',
    ];

    protected $hidden = [
        'disk',
        'path',
        'sha256',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Barangay, $this>
     */
    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeVisibleToResidents(Builder $query): Builder
    {
        return $query
            ->where('is_published', true)
            ->whereNull('archived_at');
    }

    public function isPdf(): bool
    {
        return $this->extension() === 'pdf'
            || $this->mime_type === 'application/pdf';
    }

    public function isCsv(): bool
    {
        return $this->extension() === 'csv';
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->original_name, PATHINFO_EXTENSION));
    }

    public function status(): string
    {
        if ($this->archived_at !== null) {
            return 'archived';
        }

        if ($this->is_published) {
            return 'published';
        }

        return 'draft';
    }
}
