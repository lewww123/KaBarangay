<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Resident extends Model
{
    protected $fillable = [
        'public_id',
        'resident_registration_id',
        'barangay_id',
        'purok_id',
        'resident_number',
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
        'status',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Source Registration
    |--------------------------------------------------------------------------
    */

    public function registration(): BelongsTo
    {
        return $this->belongsTo(
            ResidentRegistration::class,
            'resident_registration_id'
        );
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
    | Login Account
    |--------------------------------------------------------------------------
    */

    public function account(): HasOne
    {
        return $this->hasOne(ResidentAccount::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Resident Sectors
    |--------------------------------------------------------------------------
    |
    | Regular Resident = no sector row
    | Senior Citizen   = Senior sector
    | PWD              = PWD sector
    |
    */

    public function sectors(): BelongsToMany
    {
        return $this->belongsToMany(
            Sector::class,
            'resident_sector'
        )->withTimestamps();
    }

    /*
    |--------------------------------------------------------------------------
    | QR Credential
    |--------------------------------------------------------------------------
    */

    public function qrCredential(): HasOne
    {
        return $this->hasOne(ResidentQrCredential::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Full Name
    |--------------------------------------------------------------------------
    */

    public function getFullNameAttribute(): string
    {
        return collect([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
            $this->suffix,
        ])
            ->filter()
            ->implode(' ');
    }

    /*
    |--------------------------------------------------------------------------
    | Certificate Requests
    |--------------------------------------------------------------------------
    */

    public function certificateRequests(): HasMany
    {
        return $this->hasMany(CertificateRequest::class);
    }
}