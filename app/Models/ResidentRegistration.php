<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ResidentRegistration extends Model
{
    use HasFactory;

    protected $fillable = [
        'public_id',
        'barangay_id',
        'purok_id',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'birthdate',
        'sex',
        'civil_status',
        'address_line',
        'mobile',
        'email',
        'password',
        'status',
        'review_notes',
        'reviewed_by',
        'submitted_at',
        'reviewed_at',
        'last_resubmitted_at',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'last_resubmitted_at' => 'datetime',
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
    | Purok
    |--------------------------------------------------------------------------
    */

    public function purok(): BelongsTo
    {
        return $this->belongsTo(Purok::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Reviewer
    |--------------------------------------------------------------------------
    */

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Registration Sectors
    |--------------------------------------------------------------------------
    */

    public function sectors(): HasMany
    {
        return $this->hasMany(RegistrationSector::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Registration Documents
    |--------------------------------------------------------------------------
    */

    public function documents(): HasMany
    {
        return $this->hasMany(RegistrationDocument::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Permanent Resident
    |--------------------------------------------------------------------------
    |
    | Once this registration is approved, it can create exactly one
    | permanent resident record.
    |
    */

    public function resident(): HasOne
    {
        return $this->hasOne(
            Resident::class,
            'resident_registration_id'
        );
    }
}