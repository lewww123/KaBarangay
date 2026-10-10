<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResidentQrCredential extends Model
{
    protected $fillable = [
        'resident_id',
        'token',
        'is_active',
        'generated_at',
        'revoked_at',
    ];

    protected $hidden = [
        'token',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'generated_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Resident
    |--------------------------------------------------------------------------
    */

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }
}