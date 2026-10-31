<?php

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class CachedEloquentUserProvider extends EloquentUserProvider
{
    public function retrieveById($identifier)
    {
        $model = $this->createModel();
        $seconds = (int) config('auth.user_cache.ttl_seconds', 30);

        // Never publish uncommitted identity changes or reuse an entry for a
        // caller that has customized the provider's retrieval constraints.
        if ($seconds <= 0 || $this->queryCallback !== null || $model->getConnection()->transactionLevel() > 0) {
            return parent::retrieveById($identifier);
        }

        $attributes = self::cache()->remember(
            self::cacheKey($model, $identifier),
            $seconds,
            fn () => parent::retrieveById($identifier)?->getAttributes(),
        );

        // Store raw attributes, not mutable Eloquent instances or relationships.
        return $attributes === null ? null : $model->newFromBuilder($attributes);
    }

    public static function forget(Model $model): void
    {
        self::cache()->forget(self::cacheKey($model, $model->getKey()));

        if ($model->getOriginal($model->getKeyName()) !== null) {
            self::cache()->forget(self::cacheKey($model, $model->getOriginal($model->getKeyName())));
        }
    }

    private static function cache(): Repository
    {
        return Cache::store(config('auth.user_cache.store', 'file'));
    }

    private static function cacheKey(Model $model, mixed $identifier): string
    {
        return 'auth:user:v1:'.hash('sha256', implode('|', [
            $model::class,
            $model->getConnection()->getName(),
            $model->getConnection()->getDatabaseName(),
            $model->getTable(),
            (string) $identifier,
        ]));
    }
}
