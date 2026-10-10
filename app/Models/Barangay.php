<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Barangay extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'city_municipality',
        'province',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Users / Staff
    |--------------------------------------------------------------------------
    */

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Puroks
    |--------------------------------------------------------------------------
    */

    public function puroks(): HasMany
    {
        return $this->hasMany(Purok::class);
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