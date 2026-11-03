<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class RegistrationDocument extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        // Keep private uploads available when a deployment replaces local storage.
        // Separate content from metadata so review lists never load file binaries.
        static::created(function (self $document): void {
            $disk = Storage::disk($document->disk ?: 'local');
            if ($disk->exists($document->path)) {
                DB::table('registration_document_contents')->insert([
                    'registration_document_id' => $document->id,
                    'encrypted_content' => Crypt::encryptString(base64_encode($disk->get($document->path))),
                ]);
            }
        });
    }

    public function backedUpContent(): ?string
    {
        $encrypted = DB::table('registration_document_contents')
            ->where('registration_document_id', $this->id)->value('encrypted_content');

        return $encrypted === null ? null : base64_decode(Crypt::decryptString($encrypted), true);
    }

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
        'supersedes_document_id',
        'superseded_at',
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
            'superseded_at' => 'datetime',
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

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_document_id');
    }

    public function successor(): HasOne
    {
        return $this->hasOne(self::class, 'supersedes_document_id');
    }
}
