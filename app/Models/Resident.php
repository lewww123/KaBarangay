<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Resident extends Model
{
    /**
     * Residents whose account has been approved through a registration review.
     */
    public function scopeApprovedAccount(Builder $query): void
    {
        $query
            ->where('status', 'active')
            ->whereHas('account', fn (Builder $account) => $account->where('is_active', true))
            ->where(function (Builder $query) {
            $query->whereHas('registration', fn (Builder $registration) => $registration
                ->where('status', 'approved')
                ->whereColumn('resident_registrations.barangay_id', 'residents.barangay_id'))
                ->orWhereHas('matchedRegistrations', fn (Builder $registration) => $registration
                    ->where('status', 'approved')
                    ->whereColumn('resident_registrations.barangay_id', 'residents.barangay_id'));
        });
    }

    protected $fillable = [
        'public_id',
        'resident_registration_id',
        'barangay_id',
        'purok_id',
        'household_id',
        'household_relationship',
        'resident_number',
        'source',
        'created_by',
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
        'email_verified_at',
        'status',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
            'email_verified_at' => 'datetime',
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

    /**
     * Registrations explicitly matched to this official Resident. This is
     * separate from the immutable originating registration relationship.
     */
    public function matchedRegistrations(): HasMany
    {
        return $this->hasMany(ResidentRegistration::class, 'matched_resident_id');
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

    /**
     * @return BelongsTo<Purok, $this>
     */
    public function purok(): BelongsTo
    {
        return $this->belongsTo(Purok::class);
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
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

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function staffAssignments(): HasMany
    {
        return $this->hasMany(StaffAssignment::class);
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
    | Claims / Verification / Notifications
    |--------------------------------------------------------------------------
    */

    public function emailVerificationTokens(): HasMany
    {
        return $this->hasMany(ResidentEmailVerificationToken::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(ResidentNotification::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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

    /*
    |--------------------------------------------------------------------------
    | Sector Applications
    |--------------------------------------------------------------------------
    */

    public function sectorApplications(): HasMany
    {
        return $this->hasMany(SectorApplication::class);
    }
}
