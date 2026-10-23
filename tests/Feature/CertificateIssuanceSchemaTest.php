<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Certificate;
use App\Models\CertificateIssuance;
use App\Models\CertificateRequest;
use App\Models\Purok;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class CertificateIssuanceSchemaTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Purok $purok;

    private Certificate $certificate;

    private int $residentMobileSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $this->purok = $this->createPurok($this->barangay, 'Purok 1');

        $this->certificate = Certificate::create([
            'barangay_id' => $this->barangay->id,
            'code' => 'BRGY-CLEARANCE',
            'name' => 'Barangay Clearance',
            'fee' => 50,
            'is_active' => true,
        ]);
    }

    public function test_migration_creates_the_expected_table_and_columns(): void
    {
        $this->assertTrue(Schema::hasTable('certificate_issuances'));

        $expected = [
            'id',
            'barangay_id',
            'certificate_request_id',
            'resident_id',
            'reference',
            'certificate_type',
            'resident_name',
            'resident_number',
            'purok',
            'fee',
            'issued_by',
            'issued_at',
            'created_at',
            'updated_at',
        ];

        foreach ($expected as $column) {
            $this->assertTrue(
                Schema::hasColumn('certificate_issuances', $column),
                "Expected column [{$column}] to exist on certificate_issuances.",
            );
        }

        // Catalog-mapping columns were deliberately omitted (no verified mapping).
        $this->assertFalse(Schema::hasColumn('certificate_issuances', 'certificate_id'));
        $this->assertFalse(Schema::hasColumn('certificate_issuances', 'certificate_code'));

        // Untracked lifecycle events were deliberately omitted.
        $this->assertFalse(Schema::hasColumn('certificate_issuances', 'printed_at'));
        $this->assertFalse(Schema::hasColumn('certificate_issuances', 'released_at'));
    }

    public function test_required_columns_are_present_on_a_persisted_record(): void
    {
        $issuance = $this->createManualIssuance('ISSUE-2026-AAAA0001');

        $this->assertDatabaseHas('certificate_issuances', [
            'id' => $issuance->id,
            'barangay_id' => $this->barangay->id,
            'reference' => 'ISSUE-2026-AAAA0001',
            'certificate_type' => 'Tricycle',
            'resident_name' => 'Manual Person',
        ]);

        $this->assertNull($issuance->certificate_request_id);
        $this->assertNull($issuance->resident_id);
        $this->assertNotNull($issuance->issued_at);
    }

    public function test_nullable_columns_accept_null(): void
    {
        $issuance = $this->createManualIssuance('ISSUE-2026-NULL0001', [
            'resident_number' => null,
            'purok' => null,
            'fee' => null,
            'issued_by' => null,
        ]);

        $this->assertNull($issuance->resident_number);
        $this->assertNull($issuance->purok);
        $this->assertNull($issuance->fee);
        $this->assertNull($issuance->issued_by);
    }

    public function test_unique_issuance_reference_rejects_duplicates(): void
    {
        $this->createManualIssuance('ISSUE-2026-DUPL0001');

        $this->expectException(QueryException::class);

        $this->createManualIssuance('ISSUE-2026-DUPL0001');
    }

    public function test_a_linked_request_cannot_have_two_issuances(): void
    {
        $request = $this->createRequest('LINK-001');

        $this->createManualIssuance('ISSUE-2026-LINK0001', [
            'certificate_request_id' => $request->id,
        ]);

        $this->expectException(QueryException::class);

        $this->createManualIssuance('ISSUE-2026-LINK0002', [
            'certificate_request_id' => $request->id,
        ]);
    }

    public function test_multiple_manual_issuances_with_null_request_id_are_allowed(): void
    {
        $first = $this->createManualIssuance('ISSUE-2026-MAN10001');
        $second = $this->createManualIssuance('ISSUE-2026-MAN20002');

        $this->assertNull($first->certificate_request_id);
        $this->assertNull($second->certificate_request_id);
        $this->assertSame(
            2,
            CertificateIssuance::query()->whereNull('certificate_request_id')->count(),
        );
    }

    public function test_issuance_history_survives_request_deletion_with_nulled_link(): void
    {
        $request = $this->createRequest('SURV-001');

        $issuance = $this->createManualIssuance('ISSUE-2026-SURV0001', [
            'certificate_request_id' => $request->id,
            'resident_id' => $request->resident_id,
        ]);

        $request->delete();

        $issuance->refresh();

        $this->assertNull($issuance->certificate_request_id);
        // resident_id is unaffected by request deletion; only the link is nulled.
        $this->assertNotNull($issuance->resident_id);
        $this->assertDatabaseHas('certificate_issuances', ['id' => $issuance->id]);
    }

    public function test_issuance_history_survives_resident_deletion_with_snapshots_intact(): void
    {
        $request = $this->createRequest('SNAP-001');

        $issuance = $this->createManualIssuance('ISSUE-2026-SNAP0001', [
            'certificate_request_id' => $request->id,
            'resident_id' => $request->resident_id,
            'resident_name' => 'Snapshot Name',
        ]);

        // Deleting the resident cascades to their certificate_requests, which
        // nulls the request link, but the issuance row and its snapshots remain.
        $request->resident->delete();

        $issuance->refresh();

        $this->assertNull($issuance->resident_id);
        $this->assertNull($issuance->certificate_request_id);
        $this->assertSame('Snapshot Name', $issuance->resident_name);
        $this->assertDatabaseHas('certificate_issuances', ['id' => $issuance->id]);
    }

    public function test_barangay_deletion_is_restricted_while_issuance_history_exists(): void
    {
        $this->createManualIssuance('ISSUE-2026-REST0001');

        $this->expectException(QueryException::class);

        // Raw delete bypasses Eloquent so the FK restriction is exercised.
        DB::table('barangays')->where('id', $this->barangay->id)->delete();
    }

    public function test_issued_by_is_nulled_when_the_issuing_user_is_deleted(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $issuance = $this->createManualIssuance('ISSUE-2026-USRD0001', [
            'issued_by' => $clerk->id,
        ]);

        $clerk->delete();

        $issuance->refresh();

        $this->assertNull($issuance->issued_by);
        $this->assertDatabaseHas('certificate_issuances', ['id' => $issuance->id]);
    }

    public function test_model_casts_fee_and_issued_at(): void
    {
        $issuance = $this->createManualIssuance('ISSUE-2026-CAST0001', [
            'fee' => '125.50',
        ]);

        $this->assertSame('125.50', $issuance->fee);
        $this->assertInstanceOf(\Carbon\CarbonImmutable::class, $issuance->issued_at);
    }

    public function test_model_relationships_resolve(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $request = $this->createRequest('REL-001');

        $issuance = $this->createManualIssuance('ISSUE-2026-REL00001', [
            'certificate_request_id' => $request->id,
            'resident_id' => $request->resident_id,
            'issued_by' => $clerk->id,
        ]);

        $this->assertSame($this->barangay->id, $issuance->barangay->id);
        $this->assertSame($request->id, $issuance->certificateRequest->id);
        $this->assertSame($request->resident_id, $issuance->resident->id);
        $this->assertSame($clerk->id, $issuance->issuer->id);
    }

    private function createManualIssuance(string $reference, array $attributes = []): CertificateIssuance
    {
        return CertificateIssuance::create([
            'barangay_id' => $this->barangay->id,
            'certificate_type' => 'Tricycle',
            'resident_name' => 'Manual Person',
            'issued_at' => now(),
            ...$attributes,
            'reference' => $reference,
        ]);
    }

    private function createRequest(string $reference): CertificateRequest
    {
        $resident = $this->createResident(
            $this->barangay,
            $this->purok,
            ['mobile' => '0917'.str_pad((string) $this->residentMobileSequence++, 7, '0', STR_PAD_LEFT)],
        );

        return CertificateRequest::create([
            'barangay_id' => $this->barangay->id,
            'resident_id' => $resident->id,
            'certificate_id' => $this->certificate->id,
            'reference' => $reference,
            'purpose' => 'Employment',
            'fee' => $this->certificate->fee,
            'status' => 'Pending',
            'submitted_at' => now(),
        ]);
    }
}
