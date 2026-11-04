<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SectorApplication extends Model
{
    use HasFactory;

    public const STATUS_PENDING_STAFF_REVIEW = 'pending_staff_review';

    public const STATUS_UNDER_ADMIN_REVIEW = 'under_admin_review';

    public const STATUS_REQUIRES_CORRECTION = 'requires_correction';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const ACTIVE_STATUSES = [
        self::STATUS_PENDING_STAFF_REVIEW,
        self::STATUS_UNDER_ADMIN_REVIEW,
        self::STATUS_REQUIRES_CORRECTION,
    ];

    public const SUBMISSION_STAFF_ASSISTED = 'staff_assisted';

    public const SUBMISSION_SELF_SERVICE = 'self_service';

    protected $fillable = [
        'public_id',
        'tracking_number',
        'barangay_id',
        'resident_id',
        'sector_id',
        'submission_type',
        'submitted_by_user_id',
        'status',
        'details',
        'staff_reviewed_by',
        'staff_reviewed_at',
        'staff_review_notes',
        'staff_checklist',
        'staff_checklist_completed',
        'admin_decided_by',
        'admin_decided_at',
        'admin_decision_notes',
        'correction_notes',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'staff_checklist' => 'array',
            'staff_checklist_completed' => 'boolean',
            'staff_reviewed_at' => 'datetime',
            'admin_decided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $application): void {
            if (empty($application->public_id)) {
                $application->public_id = (string) Str::uuid();
            }
        });
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    public function submittedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function staffReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_reviewed_by');
    }

    public function adminDecidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_decided_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(SectorApplicationDocument::class);
    }

    public function scopeForBarangay(Builder $query, int $barangayId): Builder
    {
        return $query->where('barangay_id', $barangayId);
    }

    public function scopeForSector(Builder $query, string $sectorSlug): Builder
    {
        return $query->whereHas('sector', function (Builder $q) use ($sectorSlug) {
            $q->where('slug', $sectorSlug);
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }
}
