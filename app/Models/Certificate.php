<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Certificate extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope('approved_barangay_catalog', function ($query) {
            $query->where(function ($q) {
                $q->where(function ($q) {
                    $q->whereHas('barangay', fn ($b) => $b->whereIn('name', ['San Jose', 'Barangay San Jose']))
                        ->whereNotIn('code', ['business-clearance', 'barangay-certification', 'sicsican-indigency', 'sicsican-clearance']);
                })->orWhere(function ($q) {
                    $q->whereHas('barangay', fn ($b) => $b->whereIn('name', ['Sicsican', 'Barangay Sicsican']))
                        ->whereIn('code', ['business-clearance', 'barangay-certification', 'sicsican-indigency', 'sicsican-clearance']);
                })->orWhereDoesntHave('barangay', fn ($b) => $b->whereIn('name', [
                    'San Jose', 'Barangay San Jose', 'Sicsican', 'Barangay Sicsican',
                ]));
            });
        });
    }

    protected $fillable = [
        'barangay_id',
        'code',
        'name',
        'description',
        'fee',
        'requirements',
        'form_schema',
        'requires_business_details',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'fee' => 'decimal:2',
            'requirements' => 'array',
            'form_schema' => 'array',
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
