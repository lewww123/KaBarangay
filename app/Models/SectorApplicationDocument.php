<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SectorApplicationDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'sector_application_id',
        'document_type',
        'title',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'file_size',
        'sha256',
        'verification_status',
        'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(SectorApplication::class, 'sector_application_id');
    }
}
