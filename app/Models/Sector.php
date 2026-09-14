<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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

    public function registrationSectors(): HasMany
    {
        return $this->hasMany(RegistrationSector::class);
    }

    public function registrationDocuments(): HasMany
    {
        return $this->hasMany(RegistrationDocument::class);
    }
}