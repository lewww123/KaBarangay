<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Schema;

class ResidentRegistration extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (self $registration): void {
            if (! $registration->isDirty('email')) {
                return;
            }

            if (Schema::hasColumn('resident_registrations', 'email_verified_at')) {
                $registration->setAttribute('email_verified_at', null);
            }

            if (Schema::hasTable('resident_registration_email_verification_tokens')) {
                ResidentRegistrationEmailVerificationToken::query()
                    ->where('resident_registration_id', $registration->getKey())
                    ->whereNull('consumed_at')
                    ->update([
                        'consumed_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
        });
    }

    protected $fillable = [
        'public_id',
        'barangay_id',
        'purok_id',
        'matched_resident_id',
        'matched_at',
        'matched_by',
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
            'matched_at' => 'datetime',
            'email_verified_at' => 'datetime',
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
    | Originating Resident
    |--------------------------------------------------------------------------
    |
    | Historical lineage to a Resident originally created from this
    | registration. Identity matching uses matchedResident() and must never
    | rewrite this original relationship.
    |
    */

    public function resident(): HasOne
    {
        return $this->hasOne(
            Resident::class,
            'resident_registration_id'
        );
    }

    /**
     * Official Resident selected by an administrator during registration review.
     */
    public function matchedResident(): BelongsTo
    {
        return $this->belongsTo(Resident::class, 'matched_resident_id');
    }

    public function qrCredential(): BelongsTo
    {
        return $this->belongsTo(ResidentQrCredential::class, 'qr_credential_id');
    }

    public function emailVerificationTokens(): HasMany
    {
        return $this->hasMany(
            ResidentRegistrationEmailVerificationToken::class,
            'resident_registration_id'
        );
    }

    public function correctionCycles(): HasMany
    {
        return $this->hasMany(
            ResidentRegistrationCorrectionCycle::class,
            'resident_registration_id'
        )->orderBy('cycle_number');
    }
}
