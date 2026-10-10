<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Purok extends Model
{
    use HasFactory;

    protected $fillable = [
        'barangay_id',
        'name',
        'code',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Barangay
    |--------------------------------------------------------------------------
    */

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Resident Registrations
    |--------------------------------------------------------------------------
    */

    public function residentRegistrations(): HasMany
    {
        return $this->hasMany(ResidentRegistration::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Permanent Residents
    |--------------------------------------------------------------------------
    */

    public function residents(): HasMany
    {
        return $this->hasMany(Resident::class);
    }
}