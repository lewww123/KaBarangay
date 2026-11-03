<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable([
    'name',
    'email',
    'password',
    'barangay_id',
    'resident_id',
    'status',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    public function staffAssignments(): HasMany
    {
        return $this->hasMany(StaffAssignment::class);
    }

    /** @param array<int, string> $departments */
    public function hasActiveAssignment(array $departments): bool
    {
        if ($this->status !== 'active' || $this->barangay_id === null || $this->resident_id === null) {
            return false;
        }

        return $this->staffAssignments()
            ->where('barangay_id', $this->barangay_id)
            ->where('resident_id', $this->resident_id)
            ->whereIn('department', $departments)
            ->where('is_active', true)
            ->whereHas('user', fn ($query) => $query
                ->where('status', 'active')
                ->where('barangay_id', $this->barangay_id)
                ->where('resident_id', $this->resident_id))
            ->whereHas('barangay', fn ($query) => $query->where('is_active', true))
            ->whereHas('resident', fn ($query) => $query
                ->where('barangay_id', $this->barangay_id)
                ->where('status', 'active')
                ->whereHas('account', fn ($account) => $account->where('is_active', true)))
            ->exists();
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
