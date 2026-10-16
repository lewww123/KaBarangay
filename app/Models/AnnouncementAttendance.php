<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class AnnouncementAttendance extends Model
{
    use HasFactory;

    protected $fillable = [
        'barangay_id',
        'announcement_id',
        'resident_id',
        'status',
        'method',
        'attended_at',
        'notes',
        'verified_by',
    ];

    protected $casts = [
        'attended_at' => 'datetime',
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
            }
        });
    }

    public function announcement()
    {
        return $this->belongsTo(Announcement::class);
    }

    public function resident()
    {
        return $this->belongsTo(Resident::class);
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}