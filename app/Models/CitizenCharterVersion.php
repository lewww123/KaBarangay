<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $service_id
 * @property int $barangay_id
 * @property int $version_number
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $file_size
 * @property string|null $sha256
 * @property int|null $uploaded_by
 * @property string $status
 * @property Carbon|null $published_at
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CitizenCharterService|null $service
 * @property-read Barangay|null $barangay
 * @property-read User|null $uploader
 */
class CitizenCharterVersion extends Model
{
    protected $fillable = [
        'service_id',
        'barangay_id',
        'version_number',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'file_size',
        'sha256',
        'uploaded_by',
        'status',
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
            'version_number' => 'integer',
            'file_size' => 'integer',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CitizenCharterService, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(CitizenCharterService::class, 'service_id');
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

    public function isCurrent(): bool
    {
        return (int) $this->service?->current_version_id === (int) $this->id;
    }

    public function isPdf(): bool
    {
        return $this->extension() === 'pdf'
            || $this->mime_type === 'application/pdf';
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->original_name, PATHINFO_EXTENSION));
    }
}
