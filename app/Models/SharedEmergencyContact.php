<?php

namespace App\Models;

use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SharedEmergencyContact extends Model
{
    use SoftDeletes;

    public const SERVICE_TYPES = [
        'City Emergency',
        'Ambulance',
        'Fire',
        'Police',
        'Hospital',
        'Disaster',
    ];

    protected $fillable = [
        'source_key',
        'service_type',
        'name',
        'label',
        'phone_number',
        'normalized_phone',
        'coverage_area',
        'can_call',
        'can_sms',
        'display_order',
        'is_active',
        'verified_at',
        'verified_by',
        'verification_source',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'can_call' => 'boolean',
        'can_sms' => 'boolean',
        'display_order' => 'integer',
        'is_active' => 'boolean',
        'verified_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeVerified(Builder $query): Builder
    {
        return $query
            ->whereNotNull('verified_at')
            ->whereNotNull('verification_source');
    }

    public function telUri(): string
    {
        return 'tel:'.$this->normalized_phone;
    }

    public function smsUri(): string
    {
        return 'sms:'.$this->normalized_phone;
    }

    public static function normalizedPhone(string $phone): string
    {
        return PhoneNumber::normalize($phone);
    }
}
