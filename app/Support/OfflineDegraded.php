<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The degraded-offline contract: the local application answered, but the account database
 * did not. A connectivity loss is reported as an unavailable state instead of an exception,
 * so the browser can serve this account's own saved copy without logging anyone out and
 * without issuing or revoking an offline scope.
 */
final class OfflineDegraded
{
    public const HEADER = 'X-KaBarangay-Offline';

    public const UNAVAILABLE = 'unavailable';

    /**
     * Connection-level failures only. A missing column, a syntax error or a constraint
     * violation is a real defect and must stay a 500, never a cached-copy hand-off.
     */
    private const LOSS_MARKERS = [
        'SQLSTATE[08',                    // connection exception class: 08001, 08003, 08004, 08006ΓÇª
        'SQLSTATE[HY000] [2002]',          // socket-level connect failure
        'SQLSTATE[HYT00',                  // connection or query timeout
        'could not translate host name',
        'php_network_getaddresses',
        'Connection refused',
        'actively refused',
        'No route to host',
        'server closed the connection unexpectedly',
        'SSL connection has been closed unexpectedly',
        'Failed to start the connection',
        'Connection lost',
        'gone away',
        // Instance-availability FATALs. PostgreSQL reports these while refusing the
        // connection, so they carry no SQLSTATE[08] class even though nothing is wrong
        // with the query. 'remaining connection slots are reserved' is the wording the
        // server actually emits; the shorter 'slots reserved' form never matches.
        'too many clients already',
        'remaining connection slots are reserved',
        'the database system is starting up',
        'the database system is in recovery mode',
        'SSL SYSCALL error: EOF detected',
    ];

    public static function causedByDatabaseLoss(Throwable $exception): bool
    {
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            foreach (self::LOSS_MARKERS as $marker) {
                if (str_contains($cause->getMessage(), $marker)) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function respond(Request $request, ?Throwable $exception = null): Response
    {
        if ($exception !== null) {
            // The message carries the host name, the SQL and bound resident values; only the class is safe.
            Log::warning('A degraded offline response was served because the account database was unreachable.', [
                'reason' => get_class($exception),
                'path' => $request->path(),
            ]);
        }

        $headers = [
            self::HEADER => self::UNAVAILABLE,
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'same-origin',
        ];

        if ($request->expectsJson() || $request->header('X-Inertia')) {
            return response()->json([
                'error' => self::UNAVAILABLE,
                'identity' => OfflineIdentity::UNRESOLVED,
                'message' => 'KaBarangay cannot reach the barangay data service right now. Saved pages and queued requests on this device are unaffected.',
            ], 503, $headers);
        }

        return response()->view('errors.503', [
            'retry' => '/'.ltrim($request->path(), '/'),
        ], 503, $headers);
    }
}
