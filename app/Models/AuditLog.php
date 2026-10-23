<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Append-only audit record.
 *
 * Rows are written exclusively through AuditLogger and are never edited or
 * deleted by application behaviour. Retention is indefinite: there is no
 * expiry mechanism, cleanup command, or delete endpoint.
 */
class AuditLog extends Model
{
    /**
     * Append-only: audit rows have no meaningful update timestamp.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'barangay_id',
        'actor_id',
        'event',
        'entity_type',
        'entity_id',
        'context',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Defense-in-depth barangay scope
        |--------------------------------------------------------------------------
        |
        | Reads are restricted to the authenticated account's own barangay so
        | a controller can never accidentally expose another barangay's audit
        | history. Rows with a NULL barangay_id are never matched: Laravel
        | turns where('barangay_id', null) into IS NULL, which would widen
        | access, so an ambiguous (null) actor barangay is scoped to match
        | nothing instead. Unauthenticated reads also match nothing; access is
        | only ever granted through the role:admin routes.
        |
        | The controller applies the same restriction explicitly; this scope is
        | a second layer, not the primary control.
        |
        */
        static::addGlobalScope('barangay', function (Builder $builder) {
            if (! auth()->check()) {
                $builder->whereRaw('1 = 0');

                return;
            }

            $barangayId = auth()->user()->barangay_id;

            if ($barangayId === null) {
                $builder->whereRaw('1 = 0');

                return;
            }

            $builder->where('barangay_id', $barangayId);
        });

        /*
        |--------------------------------------------------------------------------
        | Append-only guard
        |--------------------------------------------------------------------------
        |
        | Model-level updates and deletes raise an error so routine Eloquent
        | usage cannot mutate history. Known limitation: mass query-builder
        | calls (Model::query()->update() / ->delete() / truncate()) bypass
        | model events entirely and are not blocked by this guard.
        |
        */
        static::updating(function (): void {
            throw new RuntimeException(
                'Audit log records are append-only and cannot be updated.'
            );
        });

        static::deleting(function (): void {
            throw new RuntimeException(
                'Audit log records are append-only and cannot be deleted.'
            );
        });
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
