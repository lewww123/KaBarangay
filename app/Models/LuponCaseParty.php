<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LuponCaseParty extends Model
{
    protected $fillable = [
        'case_id',
        'party_type',
        'resident_id',
        'name',
        'is_resident',
        'association_status',
        'associated_by',
        'verified_by',
        'verified_at',
        'verification_notes',
        'address',
        'contact_number',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'is_resident' => 'boolean',
            'is_primary' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(LuponCase::class, 'case_id');
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class, 'resident_id');
    }

    public function associatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'associated_by');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
