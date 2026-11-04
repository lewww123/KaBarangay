<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrgChartPosition extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'org_chart_positions';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'barangay_id',
        'title',
        'assigned_person',
        'parent_id',
        'status',
        'photo_path',
        'created_by',
        'updated_by',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'status' => 'string',
    ];

    /**
     * Get the barangay this position belongs to.
     */
    public function barangay()
    {
        return $this->belongsTo(Barangay::class);
    }

    /**
     * Parent position (self‑referencing).
     */
    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Child positions.
     */
    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Creator user.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Updater user.
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Published positions visible on the resident chart.
     */
    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }
}
