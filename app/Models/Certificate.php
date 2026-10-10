<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Certificate extends Model
{
    protected $fillable = [
        'barangay_id',
        'code',
        'name',
        'description',
        'fee',
        'requirements',
        'requires_business_details',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'fee' => 'decimal:2',
            'requirements' => 'array',
            'requires_business_details' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(
            CertificateRequest::class
        );
    }
}