<?php

namespace App\Support;

use Illuminate\Database\ConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PDOException;
use Throwable;

final class OfflineIdentity
{
    private const RESOLVED_ATTRIBUTE = 'kabarangay.offline_identity';

    /** The offline scope was confirmed against live account state. */
    public const VERIFIED = 'verified';

    /** The request carries no authenticated identity, so no scope can exist. */
    public const ANONYMOUS = 'anonymous';

    /** Account state could not be read, so no scope may be issued or revoked. */
    public const UNRESOLVED = 'unresolved';

    public static function scope(Request $request): ?string
    {
        return self::resolve($request)['scope'];
    }

    /**
     * @return array{scope: string|null, state: string}
     */
    public static function resolve(Request $request): array
    {
        $resolved = $request->attributes->get(self::RESOLVED_ATTRIBUTE);
        if (is_array($resolved) && isset($resolved['state'])) {
            return $resolved;
        }

        $result = self::doResolve($request);
        $request->attributes->set(self::RESOLVED_ATTRIBUTE, $result);

        return $result;
    }

    /**
     * @return array{scope: string|null, state: string}
     */
    private static function doResolve(Request $request): array
    {
        if (! $request->hasSession()) {
            return ['scope' => null, 'state' => self::ANONYMOUS];
        }

        try {
            $user = $request->user();
            $resident = $request->session()->has('resident_id') ? ResidentContext::find() : null;
        } catch (QueryException|PDOException|ConnectionException $exception) {
            self::log($exception, $request);

            return ['scope' => null, 'state' => self::UNRESOLVED];
        } catch (Throwable $exception) {
            // Fail closed for genuine database connectivity loss that may surface under a
            // different exception class, while preserving visibility for real defects.
            if (OfflineDegraded::causedByDatabaseLoss($exception)) {
                self::log($exception, $request);

                return ['scope' => null, 'state' => self::UNRESOLVED];
            }

            // Identity cannot be verified safely on any unexpected failure; fail closed so
            // no scope is issued/revoked incorrectly. Log minimally; do not include secrets.
            self::log($exception, $request);

            return ['scope' => null, 'state' => self::UNRESOLVED];
        }

        $staffId = $user?->status === 'active' ? $user->id : null;
        if ($staffId === null && $resident === null) {
            return ['scope' => null, 'state' => self::ANONYMOUS];
        }

        return [
            'scope' => hash_hmac('sha256', implode(':', [
                $request->session()->getId(), $staffId ?? '', $resident?->id ?? '',
            ]), (string) config('app.key')),
            'state' => self::VERIFIED,
        ];
    }

    /** Connection/identity-read failures must never expose host names, SQL, bindings or resident data. */
    private static function log(Throwable $exception, Request $request): void
    {
        Log::warning('Offline identity could not be resolved because account state was unreadable.', [
            'reason' => get_class($exception),
            'path' => $request->path(),
        ]);
    }
}
