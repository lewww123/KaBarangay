<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $barangay_id
 * @property int|null $current_version_id
 * @property string $title
 * @property string|null $category
 * @property string|null $description
 * @property int $display_order
 * @property string $status
 * @property bool $is_published
 * @property Carbon|null $published_at
 * @property Carbon|null $archived_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Barangay|null $barangay
 * @property-read User|null $creator
 * @property-read User|null $updater
 * @property-read CitizenCharterVersion|null $currentVersion
 * @property-read Collection<int, CitizenCharterVersion> $versions
 */
class CitizenCharterService extends Model
{
    protected $fillable = [
        'barangay_id',
        'current_version_id',
        'title',
        'category',
        'description',
        'display_order',
        'status',
        'is_published',
        'published_at',
        'archived_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The authoritative published document version for residents.
     *
     * @return BelongsTo<CitizenCharterVersion, $this>
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(CitizenCharterVersion::class, 'current_version_id');
    }

    /**
     * All document versions (revisions) retained for this service.
     *
     * @return HasMany<CitizenCharterVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(CitizenCharterVersion::class, 'service_id')
            ->orderByDesc('version_number');
    }

    /**
     * Scope for resident portal visibility.
     * Only published services with a valid current version and not archived.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeVisibleToResidents(Builder $query): Builder
    {
        return $query
            ->where('is_published', true)
            ->whereNull('archived_at')
            ->whereNotNull('current_version_id');
    }

    public function isDraft(): bool
    {
        return ! $this->is_published && $this->archived_at === null;
    }

    public function isPublished(): bool
    {
        return $this->is_published && $this->archived_at === null;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function computeStatus(): string
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
