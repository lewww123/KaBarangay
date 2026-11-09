<?php

namespace App\Models;

use App\Support\LuponCaseWorkflow;
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
        'mediation_started_at',
        'mediation_deadline_at',
        'pangkat_formed_at',
        'conciliation_started_at',
        'conciliation_deadline_at',
        'conciliation_extended_until',
        'settled_at',
        'repudiated_at',
        'dismissed_at',
        'cfa_issued_at',
        'summons_due_at',
        'pangkat_members',
        'pangkat_chair_name',
        'pangkat_secretary_name',
        'acting_captain_id',
        'intake_narrative',
        'intake_requested_action',
        'incident_date',
        'incident_location',
        'payment_verification',
        'arbitration_record',
        'disposition_record',
    ];

    protected function casts(): array
    {
        return [
            'filed_date' => 'date',
            'promoted_at' => 'datetime',
            'pangkat_members' => 'array',
            'incident_date' => 'date',
            'mediation_started_at' => 'date',
            'mediation_deadline_at' => 'date',
            'pangkat_formed_at' => 'date',
            'conciliation_started_at' => 'date',
            'conciliation_deadline_at' => 'date',
            'conciliation_extended_until' => 'date',
            'settled_at' => 'date',
            'repudiated_at' => 'date',
            'dismissed_at' => 'date',
            'cfa_issued_at' => 'date',
            'summons_due_at' => 'date',
            'fee_amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'form_7_issued_at' => 'datetime',
            'payment_verification' => 'array',
            'arbitration_record' => 'array',
            'disposition_record' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $case): void {
            $case->status = ($case->status === 'Active' && $case->phase === 'Lupon') ? 'pangkat_formed' : LuponCaseWorkflow::normalize($case->status ?: 'filed');
            $case->phase = LuponCaseWorkflow::phase($case->status);
        });
    }

    public function events(): HasMany
    {
        return $this->hasMany(LuponCaseEvent::class, 'case_id')->orderBy('occurred_at')->orderBy('id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(LuponEvidence::class, 'case_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(LuponDocument::class, 'case_id')->latest('id');
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
