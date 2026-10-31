<?php

namespace App\Http\Controllers;

use App\Support\ReferenceData;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

abstract class Controller
{
    protected function rememberReference(string $key, int $seconds, Closure $callback): mixed
    {
        return ReferenceData::remember($key, $seconds, $callback);
    }

    /** Avoid sibling queries inside legacy composite resolvers on partial visits. */
    protected function resolvesProp(string $component, string $prop): bool
    {
        $request = request();
        if ($request->header('X-Inertia-Partial-Component') !== $component) {
            return true;
        }

        $only = array_filter(array_map('trim', explode(',', (string) $request->header('X-Inertia-Partial-Data', ''))));
        $except = array_filter(array_map('trim', explode(',', (string) $request->header('X-Inertia-Partial-Except', ''))));

        return ($only === [] || in_array($prop, $only, true)) && ! in_array($prop, $except, true);
    }

    /** Resolve publication counters in one remote database round trip. */
    protected function publicationCounts(Builder $query): array
    {
        $counts = (clone $query)->reorder()->selectRaw('
            COUNT(*) as total,
            COUNT(CASE WHEN is_published = false AND archived_at IS NULL THEN 1 END) as draft,
            COUNT(CASE WHEN is_published = true AND archived_at IS NULL THEN 1 END) as published,
            COUNT(CASE WHEN archived_at IS NOT NULL THEN 1 END) as archived
        ')->first();

        return [
            'total' => (int) $counts->total,
            'draft' => (int) $counts->draft,
            'published' => (int) $counts->published,
            'archived' => (int) $counts->archived,
        ];
    }

    /**
     * Cache an expensive aggregate outside the test environment, where
     * in-memory fixtures change between assertions.
     */
    protected function rememberAggregate(string $key, int $seconds, Closure $callback): mixed
    {
        if (app()->environment('testing')) {
            return $callback();
        }

        return Cache::remember($key, $seconds, $callback);
    }

    /**
     * Defer expensive page data in the browser while keeping the historical
     * eager contract available to assertions and local diagnostics.
     */
    protected function deferProp(Closure $callback): mixed
    {
        return config('inertia.defer_heavy_props', true)
            ? Inertia::defer($callback)
            : $callback();
    }
}
