<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sector extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'requires_proof',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'requires_proof' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Registration Sectors
    |--------------------------------------------------------------------------
    */

    public function registrationSectors(): HasMany
    {
        return $this->hasMany(RegistrationSector::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Registration Documents
    |--------------------------------------------------------------------------
    */

    public function registrationDocuments(): HasMany
    {
        return $this->hasMany(RegistrationDocument::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Permanent Residents
    |--------------------------------------------------------------------------
    */

    public function residents(): BelongsToMany
    {
        return $this->belongsToMany(
            Resident::class,
            'resident_sector'
        )->withTimestamps();
    }
}