<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    public function purok(): BelongsTo
    {
        return $this->belongsTo(Purok::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function sectors(): HasMany
    {
        return $this->hasMany(RegistrationSector::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(RegistrationDocument::class);
    }
}