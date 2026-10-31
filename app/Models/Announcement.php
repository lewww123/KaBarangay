<?php

namespace App\Models;

use App\Support\AnnouncementType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Announcement extends Model
{
    public const IMPORTANCE_LOW = 'low';

    public const IMPORTANCE_NORMAL = 'normal';

    public const IMPORTANCE_HIGH = 'high';

    public const IMPORTANCE_URGENT = 'urgent';

    public const IMPORTANCE_LEVELS = [
        self::IMPORTANCE_LOW,
        self::IMPORTANCE_NORMAL,
        self::IMPORTANCE_HIGH,
        self::IMPORTANCE_URGENT,
    ];

    protected $fillable = [
        'barangay_id',
        'created_by',
        'title',
        'content',
        'category',
        'program_status',
        'importance',
        'target_audience',
        'target_sector_id',
        'requires_attendance',
        'event_start_at',
        'location',
        'is_published',
        'approval_status',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
        'completed_at',
        'completed_by',
    ];

    protected $casts = [
        'requires_attendance' => 'boolean',
        'is_published' => 'boolean',
        'event_start_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function attendanceIsAvailable(): bool
    {
        if (AnnouncementType::isEvent($this) && $this->program_status === 'draft') {
            return false;
        }

        return $this->approval_status === 'approved' && $this->requires_attendance === true;
    }

    public function getProgramStatusAttribute(?string $value): ?string
    {
        if (! AnnouncementType::isEvent($this)) {
            return null;
        }

        return $value ?? ($this->completed_at !== null ? 'done' : ($this->approval_status === 'approved' && $this->is_published ? 'ongoing' : 'draft'));
    }

    public function revisionToken(): string
    {
        $attributes = $this->getAttributes();

        ksort($attributes, SORT_STRING);

        return hash_hmac(
            'sha256',
            json_encode($attributes, JSON_THROW_ON_ERROR),
            (string) config('app.key'),
        );
    }

    protected static function booted()
    {
        static::addGlobalScope('barangay', function (Builder $builder) {
            if (auth()->check()) {
                $builder->where('barangay_id', auth()->user()->barangay_id);
            }
        });

        static::creating(function ($model) {
            if (auth()->check()) {
                $model->barangay_id = $model->barangay_id ?? auth()->user()->barangay_id;
                $model->created_by = $model->created_by ?? auth()->id();
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * @return BelongsTo<Sector, $this>
     */
    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class, 'target_sector_id');
    }

    /**
     * @return HasMany<AnnouncementAttendance, $this>
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(AnnouncementAttendance::class);
    }

    /**
     * @return HasMany<AnnouncementApprovalLog, $this>
     */
    public function approvalLogs(): HasMany
    {
        return $this->hasMany(AnnouncementApprovalLog::class)
            ->latest('created_at')
            ->latest('id');
    }
}
