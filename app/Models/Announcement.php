<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Announcement extends Model
{
    use HasFactory;

    protected $fillable = [
        'barangay_id',
        'created_by',
        'title',
        'content',
        'category',
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
    ];

    protected $casts = [
        'requires_attendance' => 'boolean',
        'is_published' => 'boolean',
        'event_start_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::addGlobalScope('barangay', function (Builder $builder) {
            if (auth()->check() && auth()->user()->role !== 'super_admin') {
                $builder->where('barangay_id', auth()->user()->barangay_id);
            }
        });

        static::creating(function ($model) {
            if (auth()->check() && auth()->user()->role !== 'super_admin') {
                $model->barangay_id = $model->barangay_id ?? auth()->user()->barangay_id;
                $model->created_by = $model->created_by ?? auth()->id();
            }
        });
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function sector()
    {
        return $this->belongsTo(Sector::class, 'target_sector_id');
    }

    public function attendances()
    {
        return $this->hasMany(AnnouncementAttendance::class);
    }

    public function approvalLogs()
    {
        return $this->hasMany(AnnouncementApprovalLog::class)->latest();
    }
}