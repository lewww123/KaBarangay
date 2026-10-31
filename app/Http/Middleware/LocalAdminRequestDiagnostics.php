<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/** Temporary, local-only timing for allowlisted HTML/Inertia page navigation. */
class LocalAdminRequestDiagnostics
{
    public const METRICS_ATTRIBUTE = '_local_page_request_diagnostics';

    public const SLOW_QUERY_LIMIT = 5;

    public const TARGET_SOURCE_FINGERPRINT = '8459fd14078e6a7e';

    public const TARGET_SOURCE_MIN_DURATION_MS = 500.0;

    public const TARGET_SOURCE_LIMIT = 3;

    private const ROUTE_PREFIXES = [
        'admin.', 'clerk.', 'pwd.', 'senior.', 'lupon.', 'resident.',
    ];

    private const EXACT_ROUTES = [
        'resident.qr.verify',
    ];

    private const EXCLUDED_ROUTE_SUFFIXES = [
        '.download', '.file', '.export', '.photo', '.qr-lookup', '.qr.credential',
        '.details', '.candidates', '.resident-details',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $routeName = $request->route()?->getName();
        $isAllowlistedRoute = is_string($routeName)
            && (in_array($routeName, self::EXACT_ROUTES, true)
                || self::hasAllowlistedPrefix($routeName))
            && ! self::hasExcludedSuffix($routeName);
        $isPageNavigation = $request->header('X-Inertia') === 'true'
            || str_contains((string) $request->header('Accept'), 'text/html');

        if (! app()->environment('local')
            || $request->method() !== 'GET'
            || ! $isAllowlistedRoute
            || ! $isPageNavigation) {
            return $next($request);
        }

        $request->attributes->set(self::METRICS_ATTRIBUTE, [
            'started_at_ns' => hrtime(true),
            'query_count' => 0,
            'query_duration_ms' => 0.0,
            'slow_queries' => [],
        ]);

        try {
            return $next($request);
        } finally {
            $metrics = $request->attributes->get(self::METRICS_ATTRIBUTE, []);
            $requestDurationMs = (hrtime(true) - ($metrics['started_at_ns'] ?? hrtime(true))) / 1_000_000;

            $diagnostics = [
                'route' => $routeName,
                'request_duration_ms' => round($requestDurationMs, 3),
                'query_count' => (int) ($metrics['query_count'] ?? 0),
                'query_duration_ms' => round((float) ($metrics['query_duration_ms'] ?? 0.0), 3),
                'slow_queries' => array_values($metrics['slow_queries'] ?? []),
            ];

            if (is_array($metrics['target_query_sources'] ?? null)
                && $metrics['target_query_sources'] !== []) {
                $diagnostics['target_query_sources'] = array_values($metrics['target_query_sources']);
            }

            Log::info('local_page_request_diagnostics', $diagnostics);

            $request->attributes->remove(self::METRICS_ATTRIBUTE);
        }
    }

    /**
     * Classify only the leading SQL command. The SQL text itself is never retained or logged.
     */
    public static function classifyQuery(string $sql): string
    {
        if (preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE|WITH|CREATE|ALTER|DROP|TRUNCATE|PRAGMA|SHOW|SET)\b/i', $sql, $matches) !== 1) {
            return 'OTHER';
        }

        return strtoupper($matches[1]);
    }

    /** Return a one-way fingerprint after removing SQL literal values. */
    public static function fingerprintQuery(string $sql): string
    {
        $normalized = preg_replace("/'(?:''|[^'])*'/s", '?', $sql) ?? '';
        $normalized = preg_replace('/\b\d+(?:\.\d+)?\b/', '?', $normalized) ?? '';
        $normalized = preg_replace('/\s+/', ' ', trim($normalized)) ?? '';

        return substr(hash('sha256', strtoupper($normalized)), 0, 16);
    }

    /**
     * Capture a small, argument-free subset of frames that can identify the
     * application call site or Laravel authentication provider for the
     * specifically targeted slow query.
     *
     * @return list<array{class: string, method: string, file?: string, line?: int}>
     */
    public static function targetQuerySourceFrames(): array
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 32);
        $authenticationSources = [];
        $applicationSources = [];

        foreach ($frames as $frame) {
            $class = $frame['class'] ?? null;
            $method = $frame['function'] ?? null;

            if (! is_string($class) || ! is_string($method) || ! self::isRelevantSourceClass($class)) {
                continue;
            }

            $source = [
                'class' => $class,
                'method' => $method,
            ];

            $relativeFile = self::repositoryRelativePath($frame['file'] ?? null);
            if ($relativeFile !== null) {
                $source['file'] = $relativeFile;
            }

            if (isset($frame['line']) && is_int($frame['line'])) {
                $source['line'] = $frame['line'];
            }

            if (self::isAuthenticationSourceClass($class)) {
                $authenticationSources[] = $source;
            } else {
                $applicationSources[] = $source;
            }
        }

        return array_slice([...$authenticationSources, ...$applicationSources], 0, 4);
    }

    private static function isRelevantSourceClass(string $class): bool
    {
        if (self::isAuthenticationSourceClass($class)
            || in_array($class, [
                'App\\Http\\Middleware\\EnsureStaffRole',
                'App\\Http\\Middleware\\HandleInertiaRequests',
            ], true)) {
            return true;
        }

        foreach ([
            'App\\Http\\Controllers\\',
            'App\\Models\\',
            'App\\Support\\',
        ] as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function isAuthenticationSourceClass(string $class): bool
    {
        return in_array($class, [
            'Illuminate\\Auth\\EloquentUserProvider',
            'Illuminate\\Auth\\SessionGuard',
            'Illuminate\\Auth\\Middleware\\Authenticate',
        ], true);
    }

    private static function repositoryRelativePath(mixed $file): ?string
    {
        if (! is_string($file) || $file === '') {
            return null;
        }

        $repository = realpath(base_path());
        $path = realpath($file);

        if ($repository === false || $path === false) {
            return null;
        }

        $repositoryPrefix = rtrim($repository, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (! str_starts_with(strtolower($path), strtolower($repositoryPrefix))) {
            return null;
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($repositoryPrefix)));
    }

    private static function hasAllowlistedPrefix(string $routeName): bool
    {
        foreach (self::ROUTE_PREFIXES as $prefix) {
            if (str_starts_with($routeName, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function hasExcludedSuffix(string $routeName): bool
    {
        foreach (self::EXCLUDED_ROUTE_SUFFIXES as $suffix) {
            if (str_ends_with($routeName, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
