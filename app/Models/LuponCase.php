<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LuponCase extends Model
{
    protected $fillable = [
        'barangay_id',
        'complaint_id',
        'origin',
        'case_number',
        'title',
        'case_type',
        'phase',
        'status',
        'payment_status',
        'fee_amount',
        'or_number',
        'paid_at',
        'form_7_issued_at',
        'created_by',
        'assigned_to',
        'filed_date',
        'promoted_at',
    ];

    protected function casts(): array
    {
        return [
            'filed_date' => 'date',
            'promoted_at' => 'datetime',
            'fee_amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'form_7_issued_at' => 'datetime',
        ];
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(LuponComplaint::class, 'complaint_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignedStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function parties(): HasMany
    {
        return $this->hasMany(LuponCaseParty::class, 'case_id');
    }

    public function complainants(): HasMany
    {
        return $this->hasMany(LuponCaseParty::class, 'case_id')->where('party_type', 'complainant');
    }

    public function respondents(): HasMany
    {
        return $this->hasMany(LuponCaseParty::class, 'case_id')->where('party_type', 'respondent');
    }

    public function hearings(): HasMany
    {
        return $this->hasMany(LuponHearing::class, 'case_id')->orderBy('hearing_number');
    }

    public function settlement(): HasOne
    {
        return $this->hasOne(LuponSettlement::class, 'case_id');
    }
}
