<?php

namespace App\Support;

use App\Http\Middleware\LocalAdminRequestDiagnostics;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Temporary SQL diagnostics for the four admin navigation endpoints. */
class AdminQueryLogger
{
    public function __invoke(QueryExecuted $query): void
    {
        if (! config('logging.admin_queries.enabled')) {
            return;
        }

        $request = app('request');

        // Match paths before route/auth middleware runs, so session and auth
        // queries are included alongside controller and deferred-prop queries.
        if (! ($request instanceof Request)
            || ! $request->isMethod('GET')
            || ! in_array($request->path(), ['admin', 'admin/residents', 'admin/staff', 'admin/sectors'], true)
            || $query->time < (float) config('logging.admin_queries.min_duration_ms', 0)) {
            return;
        }

        $requestId = $request->attributes->get('_admin_query_request_id');
        if ($requestId === null) {
            $requestId = (string) Str::uuid();
            $request->attributes->set('_admin_query_request_id', $requestId);
        }

        Log::channel('admin_queries')->info('admin_sql_query', [
            'request_id' => $requestId,
            'path' => $request->path(),
            'route' => $request->route()?->getName(),
            'inertia' => $request->header('X-Inertia') === 'true',
            'partial_data' => $request->header('X-Inertia-Partial-Data'),
            'connection' => $query->connectionName,
            'duration_ms' => round($query->time, 3),
            'fingerprint' => LocalAdminRequestDiagnostics::fingerprintQuery($query->sql),
            // Keep placeholders: bindings may contain resident data or credentials.
            'sql' => $query->sql,
        ]);
    }
}
