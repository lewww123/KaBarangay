<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LuponHearing extends Model
{
    protected $fillable = [
        'case_id',
        'barangay_id',
        'hearing_number',
        'phase',
        'hearing_date',
        'hearing_time',
        'venue',
        'status',
        'outcome',
        'notes',
        'officer_id',
    ];

    protected function casts(): array
    {
        return [
            'hearing_date' => 'date',
            'hearing_number' => 'integer',
        ];
    }

    public function getPhaseNumberAttribute(): int
    {
        return $this->newQuery()->where('case_id', $this->case_id)->where('phase', $this->phase)
            ->where('hearing_number', '<=', $this->hearing_number)->count();
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(LuponCase::class, 'case_id');
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    public function officer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'officer_id');
    }
}
