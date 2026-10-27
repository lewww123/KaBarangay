<?php

namespace App\Models;

use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmergencyContact extends Model
{
    public const CATEGORY_BARANGAY_HOTLINE = 'barangay_hotline';

    public const CATEGORY_EMERGENCY_HOTLINE = 'emergency_hotline';

    public const CATEGORIES = [
        self::CATEGORY_BARANGAY_HOTLINE,
        self::CATEGORY_EMERGENCY_HOTLINE,
    ];

    public const EMERGENCY_SUBCATEGORIES = [
        'City Emergency',
        'Ambulance',
        'Fire',
        'Police',
        'Hospital',
        'Disaster',
    ];

    protected $fillable = [
        'barangay_id',
        'category',
        'sub_category',
        'name',
        'label',
        'phone_number',
        'can_call',
        'can_sms',
        'display_order',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'can_call' => 'boolean',
        'can_sms' => 'boolean',
        'display_order' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * @return BelongsTo<Barangay, $this>
     */
    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
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
    public function scopeForBarangay(Builder $query, int $barangayId): Builder
    {
        return $query->where('barangay_id', $barangayId);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeBarangayHotlines(Builder $query): Builder
    {
        return $query->where('category', self::CATEGORY_BARANGAY_HOTLINE);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeEmergencyHotlines(Builder $query): Builder
    {
        return $query->where('category', self::CATEGORY_EMERGENCY_HOTLINE);
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
     * Normalize the phone number for tel: or sms: URI (digits and leading + only).
     */
    public static function normalizePhoneNumber(string $phone): string
    {
        return PhoneNumber::normalize($phone);
    }
}
