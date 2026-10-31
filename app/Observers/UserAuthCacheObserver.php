<?php

namespace App\Observers;

use App\Auth\CachedEloquentUserProvider;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class UserAuthCacheObserver implements ShouldHandleEventsAfterCommit
{
    public function saved(User $user): void
    {
        CachedEloquentUserProvider::forget($user);
    }

    public function deleted(User $user): void
    {
        CachedEloquentUserProvider::forget($user);
    }
}
