<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LuponSettlement extends Model
{
    protected $fillable = [
        'case_id',
        'barangay_id',
        'settlement_type',
        'settlement_date',
        'terms',
        'status',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'settlement_date' => 'date',
        ];
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(LuponCase::class, 'case_id');
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
