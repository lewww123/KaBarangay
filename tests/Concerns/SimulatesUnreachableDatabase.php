<?php

namespace Tests\Concerns;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;

/**
 * Reproduces the real outage: this application server keeps answering while the
 * Supabase PostgreSQL host cannot be resolved or reached.
 */
trait SimulatesUnreachableDatabase
{
    public const UNREACHABLE_HOST = 'pooler.supabase.com';

    /** Run a scenario as if the database host cannot be translated or reached. */
    private function withUnreachableDatabase(callable $test): void
    {
        $this->withFailedConnection(static function () {
            throw new QueryException(
                'pgsql',
                'select * from "residents" where "id" = ? limit 1',
                ['09170000001'],
                new PDOException('SQLSTATE[08006] [7] could not translate host name "'.static::UNREACHABLE_HOST.'" to address: Unknown host'),
            );
        }, $test);
    }

    /** Run a scenario with any connection-level failure, so defects stay distinguishable. */
    private function withFailedConnection(callable $failure, callable $test): void
    {
        $connection = DB::connection();
        $previous = (function () {
            return [$this->pdo, $this->readPdo, $this->transactions, $this->reconnecting];
        })->call($connection);

        // A lost-connection message makes Laravel reconnect, and under sqlite that
        // silently opens a fresh empty in-memory database. Pretend a reconnect is
        // already underway so the injected failure is what the application sees.
        (function () {
            $this->reconnecting = true;
        })->call($connection);
        $connection->setPdo($failure);
        $connection->setReadPdo($failure);

        try {
            $test();
        } finally {
            $restore = function (array $state) {
                [$this->pdo, $this->readPdo, $this->transactions, $this->reconnecting] = $state;
            };
            // setPdo() zeroes the transaction counter, which would leave RefreshDatabase's
            // own transaction open and make the next test begin a second one inside it.
            $restore->call($connection, $previous);
        }
    }
}
