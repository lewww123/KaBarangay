<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\CertificateRequest;
use App\Models\Resident;
use App\Support\OfflineCertificateContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class OfflineCertificateSyncTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Resident $resident;

    private Certificate $certificate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $barangay = $this->createBarangay('Sync Barangay', 'SYNC');
        $this->resident = $this->createResident($barangay, $this->createPurok($barangay));
        $this->certificate = Certificate::create(['barangay_id' => $barangay->id, 'code' => 'SYNC-INDIGENCY', 'name' => 'Certificate of Indigency', 'fee' => 50, 'is_active' => true]);
        $this->withSession(['resident_id' => $this->resident->id]);
    }

    private function payload(array $changes = []): array
    {
        return [
            'client_request_id' => (string) Str::uuid(),
            'offline_owner' => OfflineCertificateContext::owner($this->resident),
            'certificate_id' => (string) $this->certificate->id,
            'catalog_revision' => OfflineCertificateContext::revision($this->certificate),
            'purpose' => 'Medical assistance', 'details' => [], ...$changes,
        ];
    }

    public function test_context_contains_only_the_authenticated_residents_catalog_and_stable_unlock_identity(): void
    {
        $other = $this->createBarangay('Other Sync Barangay', 'SYNC-OTHER');
        Certificate::create(['barangay_id' => $other->id, 'code' => 'OTHER-CERT', 'name' => 'Foreign certificate', 'fee' => 80, 'is_active' => true]);
        $response = $this->getJson('/resident/certificates/offline-context')->assertOk()
            ->assertJsonCount(1, 'certificates')
            ->assertJsonPath('certificates.0.id', (string) $this->certificate->id)
            ->assertJsonPath('owner', OfflineCertificateContext::owner($this->resident))
            ->assertDontSee('Foreign certificate');
        $this->assertSame(32, strlen(base64_decode($response->json('key'))));
        $this->getJson('/offline/session')->assertJsonPath('owner', $response->json('owner'));
    }

    public function test_sync_creates_one_official_pending_request_using_the_database_fee(): void
    {
        $data = $this->payload(['fee' => 1, 'status' => 'Released', 'resident_id' => 999]);
        $response = $this->postJson('/resident/certificates/sync', $data)->assertCreated()->assertJsonPath('status', 'Pending')->assertJsonPath('replayed', false);
        $request = CertificateRequest::sole();
        $this->assertSame($request->reference, $response->json('reference'));
        $this->assertEquals(50, $request->fee);
        $this->assertSame($this->resident->id, $request->resident_id);
        $this->assertSame($data['client_request_id'], $request->client_request_id);
        $this->assertNotNull($request->submitted_at);
    }

    public function test_retry_after_a_lost_response_reuses_the_existing_reference_even_if_the_catalog_later_changes(): void
    {
        $data = $this->payload();
        $first = $this->postJson('/resident/certificates/sync', $data)->assertCreated()->json('reference');
        $this->certificate->update(['fee' => 90, 'is_active' => false]);
        $this->postJson('/resident/certificates/sync', $data)->assertOk()->assertJsonPath('reference', $first)->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('certificate_requests', 1);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'certificate_request.created')->count());
    }

    public function test_reusing_an_id_with_different_details_is_a_conflict_not_an_overwrite(): void
    {
        $data = $this->payload();
        $this->postJson('/resident/certificates/sync', $data)->assertCreated();
        $this->postJson('/resident/certificates/sync', [...$data, 'purpose' => 'Different purpose'])->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
        $this->assertDatabaseCount('certificate_requests', 1);
        $this->assertSame('Medical assistance', CertificateRequest::sole()->purpose);
    }

    public function test_changed_fees_require_explicit_review_before_a_new_request_is_created(): void
    {
        $data = $this->payload();
        $this->certificate->update(['fee' => 75]);
        $this->postJson('/resident/certificates/sync', $data)->assertConflict()->assertJsonPath('code', 'catalog_changed');
        $this->assertDatabaseCount('certificate_requests', 0);
        $data['catalog_revision'] = OfflineCertificateContext::revision($this->certificate->fresh());
        $this->postJson('/resident/certificates/sync', $data)->assertCreated();
        $this->assertEquals(75, CertificateRequest::sole()->fee);
    }

    public function test_other_account_cannot_submit_the_first_accounts_queued_request(): void
    {
        $data = $this->payload();
        $other = $this->createResident($this->resident->barangay, $this->resident->purok, ['mobile' => '09179990031']);
        $this->withSession(['resident_id' => $other->id])->postJson('/resident/certificates/sync', $data)->assertForbidden();
        $this->assertDatabaseCount('certificate_requests', 0);
    }

    public function test_foreign_or_disabled_certificates_cannot_be_synced(): void
    {
        $data = $this->payload();
        $this->certificate->update(['is_active' => false]);
        $this->postJson('/resident/certificates/sync', $data)->assertConflict();
        $this->assertDatabaseCount('certificate_requests', 0);
    }

    public function test_sync_uses_the_same_field_validation_as_the_regular_online_form(): void
    {
        $this->postJson('/resident/certificates/sync', $this->payload(['purpose' => '']))->assertUnprocessable()->assertJsonValidationErrors('purpose');
        $this->assertDatabaseCount('certificate_requests', 0);
    }
}
