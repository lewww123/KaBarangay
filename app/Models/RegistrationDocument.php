<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegistrationDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'resident_registration_id',
        'sector_id',
        'document_type',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'file_size',
        'sha256',
        'verification_status',
        'review_notes',
        'verified_by',
        'verified_at',
    ];

    protected $hidden = [
        'path',
        'sha256',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'verified_at' => 'datetime',
        ];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(
            ResidentRegistration::class,
            'resident_registration_id'
        );
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}