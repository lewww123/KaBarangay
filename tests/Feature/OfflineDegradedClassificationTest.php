<?php

namespace Tests\Feature;

use App\Support\OfflineDegraded;
use App\Support\OfflineIdentity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDOException;
use RuntimeException;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\Concerns\SimulatesUnreachableDatabase;
use Tests\TestCase;
use Throwable;

/**
 * Only an instance that will not accept or keep a connection is an outage. PostgreSQL
 * reports several availability states without the SQLSTATE[08] connection class, so the
 * classifier has to read the message text; reading it too eagerly would quietly hand real
 * defects ΓÇö a dropped relation, a bad statement, a duplicate key ΓÇö to the offline cache.
 */
class OfflineDegradedClassificationTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase, SimulatesUnreachableDatabase;

    /**
     * Availability failures added after the first audit: the server refused or dropped
     * the connection, while the query itself was never executed.
     */
    public function test_postgreSQL_availability_failures_are_classified_as_an_outage(): void
    {
        foreach ([
            'FATAL: sorry, too many clients already',
            'FATAL: remaining connection slots are reserved for non-replication superuser connections',
            'FATAL: the database system is starting up',
            'FATAL: the database system is in recovery mode',
            'SSL SYSCALL error: EOF detected',
            // The classes this classifier already trusted must keep working.
            'SQLSTATE[08006] [7] connection to server at "pooler.supabase.com" failed: server closed the connection unexpectedly',
            'SQLSTATE[HY000] [2002] Connection refused',
        ] as $message) {
            $this->assertTrue(
                OfflineDegraded::causedByDatabaseLoss($this->connectionFailure($message)),
                "Expected an availability failure to degrade: {$message}",
            );
        }
    }

    public function test_ordinary_database_defects_are_never_classified_as_an_outage(): void
    {
        foreach ([
            'SQLSTATE[42P01] undefined_table: relation "residents" does not exist',
            'SQLSTATE[42601] syntax_error: unexpected end of input',
            'SQLSTATE[23505] unique_violation: duplicate key value violates unique constraint "residents_mobile_unique"',
            'SQLSTATE[22P02] invalid_text_representation: invalid input syntax for type integer: "abc"',
            'SQLSTATE[23503] foreign_key_violation: update or delete on a table',
            'SQLSTATE[42703] undefined_column: column "issued_by" of relation does not exist',
        ] as $message) {
            $this->assertFalse(
                OfflineDegraded::causedByDatabaseLoss($this->connectionFailure($message)),
                "A defect must stay a defect: {$message}",
            );
        }

        $this->assertFalse(OfflineDegraded::causedByDatabaseLoss(new RuntimeException('too many clients in the queue')));
        $this->assertFalse(OfflineDegraded::causedByDatabaseLoss(new PDOException('SQLSTATE[HY000] General error')));
    }

    public function test_an_exhausted_connection_pool_degrades_at_the_gateway(): void
    {
        $barangay = $this->createBarangay('Pool Barangay', 'POOL');
        $resident = $this->createResident($barangay, $this->createPurok($barangay));

        // A full pool is not a broken resident: the contract is the same unavailable
        // response, with the session kept and no scope issued or revoked.
        $this->withFailedConnection(fn () => throw $this->connectionFailure('FATAL: sorry, too many clients already'), function () use ($resident) {
            $this->withSession(['resident_id' => $resident->id])
                ->get('/resident/notifications')
                ->assertStatus(503)
                ->assertHeader(OfflineDegraded::HEADER, OfflineDegraded::UNAVAILABLE)
                ->assertHeaderMissing('X-KaBarangay-Offline-Scope')
                ->assertSessionHas('resident_id', $resident->id);
        });
    }

    public function test_a_starting_database_degrades_json_clients_with_unresolved_identity(): void
    {
        $barangay = $this->createBarangay('Pool Barangay', 'POOL');
        $resident = $this->createResident($barangay, $this->createPurok($barangay));

        $this->withFailedConnection(fn () => throw $this->connectionFailure('FATAL: the database system is in recovery mode'), function () use ($resident) {
            $this->withSession(['resident_id' => $resident->id])
                ->getJson('/resident/notifications')
                ->assertStatus(503)
                ->assertJsonPath('identity', OfflineIdentity::UNRESOLVED);
        });
    }

    public function test_a_duplicate_key_defect_still_reports_a_server_error(): void
    {
        $barangay = $this->createBarangay('Pool Barangay', 'POOL');
        $resident = $this->createResident($barangay, $this->createPurok($barangay));

        // If this degraded instead, the browser would serve a stale saved copy and the
        // defect would never surface.
        $this->withFailedConnection(fn () => throw $this->connectionFailure('SQLSTATE[23505] unique_violation: duplicate key value violates unique constraint "residents_mobile_unique"'), function () use ($resident) {
            $this->withSession(['resident_id' => $resident->id])
                ->get('/resident/notifications')
                ->assertStatus(500)
                ->assertHeaderMissing(OfflineDegraded::HEADER);
        });
    }

    private function connectionFailure(string $message): QueryException
    {
        return new QueryException('pgsql', 'select * from "residents" where "id" = ? limit 1', ['1'], new PDOException($message));
    }
}
