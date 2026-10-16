<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class AnnouncementApprovalLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'barangay_id',
        'announcement_id',
        'actor_id',
        'action',
        'previous_status',
        'new_status',
        'notes',
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

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function announcement()
    {
        return $this->belongsTo(Announcement::class);
    }
}