<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Certificate;
use App\Models\CertificateIssuance;
use App\Models\CertificateRequest;
use App\Models\Purok;
use App\Support\CertificateRequestWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

/**
 * Clerk-owned certificate request lifecycle.
 *
 * Status processing belongs to the Clerk portal. Administrators keep aggregate
 * monitoring only and have no individual status route.
 */
class ClerkCertificateTransitionTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Purok $purok;

    private Certificate $certificate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $this->purok = $this->createPurok($this->barangay, 'Purok 1');

        $this->certificate = Certificate::create([
            'barangay_id' => $this->barangay->id,
            'code' => 'BRGY-INDIGENCY',
            'name' => 'Certificate of Indigency',
            'fee' => 50,
            'is_active' => true,
        ]);
    }

    public function test_valid_lifecycle_sets_each_timestamp_once(): void
    {
        $request = $this->createRequest('LIFE-001');
        $clerk = $this->createStaff($this->barangay, 'clerk');

        // 1. Pending -> Processing (Start Processing)
        $this->transition($clerk, $request, 'Processing');
        $processingAt = $request->refresh()->processing_at;
        $this->assertNotNull($processingAt);

        // 2. Issuance creation advances to For Payment (when fee > 0)
        $this->createIssuance($request, $clerk);
        CertificateRequestWorkflow::apply($request, 'For Payment', 'Certificate generated. Payment required before claiming.');
        $this->assertSame('For Payment', $request->refresh()->status);

        // 3. Dedicated payment confirmation advances to Ready for Claim
        $this->actingAs($clerk)
            ->postJson($this->confirmPaymentUrl($request), [
                'confirm_payment' => true,
            ])
            ->assertOk();
        $this->assertSame('Ready for Claim', $request->refresh()->status);
        $readyForClaimAt = $request->refresh()->ready_for_claim_at;
        $this->assertNotNull($readyForClaimAt);

        // 4. Dedicated release confirmation advances to Released
        $this->actingAs($clerk)
            ->postJson($this->releaseUrl($request), [
                'confirm_handover' => true,
            ])
            ->assertOk();

        $request->refresh();

        $this->assertSame('Released', $request->status);
        $this->assertNotNull($request->released_at);
        $this->assertEquals($processingAt, $request->processing_at);
        $this->assertEquals($readyForClaimAt, $request->ready_for_claim_at);
    }

    public function test_invalid_jump_forward_is_rejected(): void
    {
        $request = $this->createRequest('JUMP-001');
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->patchJson($this->statusUrl($request), ['status' => 'Released'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame('Pending', $request->refresh()->status);
    }

    public function test_released_status_is_terminal(): void
    {
        $request = $this->createRequest('TERM-001', ['status' => 'Released', 'released_at' => now()]);
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->patchJson($this->statusUrl($request), ['status' => 'Processing'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame('Released', $request->refresh()->status);
    }

    public function test_rejected_status_is_terminal(): void
    {
        $request = $this->createRequest('TERM-002', [
            'status' => 'Rejected',
            'admin_note' => 'Incomplete',
            'rejected_at' => now(),
        ]);
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->patchJson($this->statusUrl($request), ['status' => 'Processing'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame('Rejected', $request->refresh()->status);
    }

    public function test_reapplying_current_status_updates_the_note(): void
    {
        $request = $this->createRequest('NOTE-001');
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->patchJson($this->statusUrl($request), [
                'status' => 'Pending',
                'admin_note' => 'Waiting for ID',
            ])
            ->assertOk();

        $this->assertSame('Waiting for ID', $request->refresh()->admin_note);
    }

    public function test_omitted_note_is_cleared_on_the_next_transition(): void
    {
        $request = $this->createRequest('NOTE-002', ['admin_note' => 'Stale note']);
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->transition($clerk, $request, 'Processing');

        $this->assertNull($request->refresh()->admin_note);
    }

    public function test_correction_path_through_needs_action_is_allowed(): void
    {
        $request = $this->createRequest('CORR-001');
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->patchJson($this->statusUrl($request), [
                'status' => 'Needs Action',
                'admin_note' => 'Missing signature',
            ])
            ->assertOk();

        $this->transition($clerk, $request, 'Processing');

        $request->refresh();

        $this->assertSame('Processing', $request->status);
        $this->assertNotNull($request->reviewed_at);
    }

    public function test_rejection_requires_a_note_and_records_timestamp(): void
    {
        $request = $this->createRequest('REJ-002');
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->patchJson($this->statusUrl($request), ['status' => 'Rejected'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('admin_note');

        $this->assertSame('Pending', $request->refresh()->status);

        $this->actingAs($clerk)
            ->patchJson($this->statusUrl($request), [
                'status' => 'Rejected',
                'admin_note' => 'Duplicate request',
            ])
            ->assertOk();

        $request->refresh();

        $this->assertSame('Rejected', $request->status);
        $this->assertSame('Duplicate request', $request->admin_note);
        $this->assertNotNull($request->rejected_at);
    }

    public function test_failed_terminal_transition_preserves_note_and_timestamps(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $request = $this->createRequest('REL-MUT', [
            'status' => 'Released',
            'admin_note' => 'Original note',
            'reviewed_at' => now()->subDays(2),
            'released_at' => now()->subDay(),
        ]);

        $before = $this->snapshot($request);

        $this->actingAs($clerk)
            ->patchJson($this->statusUrl($request), [
                'status' => 'Processing',
                'admin_note' => 'Tampered note',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertUnchanged($request, $before, 'Released');
        $this->assertNull($request->refresh()->processing_at);
    }

    public function test_clerk_cannot_transition_another_barangays_request(): void
    {
        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $otherPurok = $this->createPurok($otherBarangay, 'Purok 2');
        $otherCertificate = Certificate::create([
            'barangay_id' => $otherBarangay->id,
            'code' => 'BRGY-INDIGENCY',
            'name' => 'Certificate of Indigency',
            'fee' => 50,
            'is_active' => true,
        ]);

        $resident = $this->createResident($otherBarangay, $otherPurok, [
            'mobile' => '09170000999',
        ]);
        $request = CertificateRequest::create([
            'barangay_id' => $otherBarangay->id,
            'resident_id' => $resident->id,
            'certificate_id' => $otherCertificate->id,
            'reference' => 'FOREIGN-TERM-001',
            'purpose' => 'Employment',
            'fee' => 50,
            'status' => 'Pending',
            'submitted_at' => now(),
        ]);

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->patchJson($this->statusUrl($request), ['status' => 'Processing'])
            ->assertNotFound();

        $this->assertSame('Pending', $request->refresh()->status);
    }

    public function test_for_payment_is_skipped_for_zero_fee_requests(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        // Zero fee request: after issuance, moves directly to Ready for Claim
        $zeroFeeRequest = $this->createRequest('ZERO-001', ['fee' => 0]);
        $this->transition($clerk, $zeroFeeRequest, 'Processing');
        $this->createIssuance($zeroFeeRequest, $clerk, 0);

        CertificateRequestWorkflow::apply($zeroFeeRequest, 'Ready for Claim', 'Certificate generated and ready for claiming.');
        $this->assertSame('Ready for Claim', $zeroFeeRequest->refresh()->status);
        $this->assertNotNull($zeroFeeRequest->ready_for_claim_at);

        // Paid fee request: after issuance, moves to For Payment
        $paidRequest = $this->createRequest('PAID-001', ['fee' => 50]);
        $this->transition($clerk, $paidRequest, 'Processing');
        $this->createIssuance($paidRequest, $clerk, 50);

        CertificateRequestWorkflow::apply($paidRequest, 'For Payment', 'Certificate generated. Payment required before claiming.');
        $this->assertSame('For Payment', $paidRequest->refresh()->status);
    }

    public function test_generic_status_cannot_set_protected_statuses(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $request = $this->createRequest('PROT-001');

        // Cannot set For Payment via generic update
        $this->actingAs($clerk)
            ->patchJson($this->statusUrl($request), ['status' => 'For Payment'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        // Cannot set Ready for Claim via generic update
        $this->actingAs($clerk)
            ->patchJson($this->statusUrl($request), ['status' => 'Ready for Claim'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        // Cannot set Released via generic update
        $this->actingAs($clerk)
            ->patchJson($this->statusUrl($request), ['status' => 'Released'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame('Pending', $request->refresh()->status);
    }

    public function test_confirm_payment_endpoint_rules(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $request = $this->createRequest('PAY-RULE-001');

        // 1. Fails if not in 'For Payment'
        $this->actingAs($clerk)
            ->postJson($this->confirmPaymentUrl($request), ['confirm_payment' => true])
            ->assertUnprocessable();

        // Move to For Payment without issuance
        $request->update(['status' => 'For Payment']);

        // 2. Fails if no linked issuance exists
        $this->actingAs($clerk)
            ->postJson($this->confirmPaymentUrl($request), ['confirm_payment' => true])
            ->assertUnprocessable();

        // Create issuance
        $this->createIssuance($request, $clerk);

        // 3. Fails without confirmation payload
        $this->actingAs($clerk)
            ->postJson($this->confirmPaymentUrl($request), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirm_payment');

        // 4. Succeeds with valid confirmation
        $this->actingAs($clerk)
            ->postJson($this->confirmPaymentUrl($request), [
                'confirm_payment' => true,
                'admin_note' => 'Paid in cash at desk',
            ])
            ->assertOk()
            ->assertJson([
                'status' => 'Ready for Claim',
            ]);

        $this->assertSame('Ready for Claim', $request->refresh()->status);
        $this->assertNotNull($request->ready_for_claim_at);

        // 5. Repeated confirmation fails because it is no longer in For Payment
        $this->actingAs($clerk)
            ->postJson($this->confirmPaymentUrl($request), ['confirm_payment' => true])
            ->assertUnprocessable();
    }

    public function test_confirm_release_endpoint_rules(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $request = $this->createRequest('REL-RULE-001');

        // 1. Fails if not in 'Ready for Claim'
        $this->actingAs($clerk)
            ->postJson($this->releaseUrl($request), ['confirm_handover' => true])
            ->assertUnprocessable();

        // Set to Ready for Claim without issuance
        $request->update(['status' => 'Ready for Claim', 'ready_for_claim_at' => now()]);

        // 2. Fails if no linked issuance exists
        $this->actingAs($clerk)
            ->postJson($this->releaseUrl($request), ['confirm_handover' => true])
            ->assertUnprocessable();

        // Create issuance
        $this->createIssuance($request, $clerk);

        // 3. Fails without handover confirmation flag
        $this->actingAs($clerk)
            ->postJson($this->releaseUrl($request), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirm_handover');

        // 4. Succeeds with valid confirmation
        $this->actingAs($clerk)
            ->postJson($this->releaseUrl($request), [
                'confirm_handover' => true,
                'admin_note' => 'Claimed by resident with valid ID',
            ])
            ->assertOk()
            ->assertJson([
                'status' => 'Released',
            ]);

        $this->assertSame('Released', $request->refresh()->status);
        $this->assertNotNull($request->released_at);

        // 5. Repeated release fails because it is already Released (terminal)
        $this->actingAs($clerk)
            ->postJson($this->releaseUrl($request), ['confirm_handover' => true])
            ->assertUnprocessable();
    }

    public function test_tenant_isolation_on_payment_and_release(): void
    {
        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $otherPurok = $this->createPurok($otherBarangay, 'Purok 2');
        $otherCertificate = Certificate::create([
            'barangay_id' => $otherBarangay->id,
            'code' => 'BRGY-INDIGENCY',
            'name' => 'Certificate of Indigency',
            'fee' => 50,
            'is_active' => true,
        ]);

        $resident = $this->createResident($otherBarangay, $otherPurok, [
            'mobile' => '09170000777',
        ]);
        $request = CertificateRequest::create([
            'barangay_id' => $otherBarangay->id,
            'resident_id' => $resident->id,
            'certificate_id' => $otherCertificate->id,
            'reference' => 'OTHER-BRGY-001',
            'purpose' => 'Employment',
            'fee' => 50,
            'status' => 'For Payment',
            'submitted_at' => now(),
        ]);

        $clerk = $this->createStaff($this->barangay, 'clerk');

        // Foreign clerk cannot confirm payment
        $this->actingAs($clerk)
            ->postJson($this->confirmPaymentUrl($request), ['confirm_payment' => true])
            ->assertNotFound();

        // Foreign clerk cannot release
        $this->actingAs($clerk)
            ->postJson($this->releaseUrl($request), ['confirm_handover' => true])
            ->assertNotFound();
    }

    public function test_show_endpoint_eager_loads_purok_correctly(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $request = $this->createRequest('PUROK-TEST-001');

        $response = $this->actingAs($clerk)
            ->getJson('/clerk/certificates/requests/'.$request->id)
            ->assertOk();

        $this->assertSame('Purok 1', $response->json('resident.purok'));
        $this->assertSame($request->resident_id, $response->json('resident.id'));
    }

    public function test_reapplying_protected_status_allows_note_update(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $request = $this->createRequest('NOTE-PROT-001', ['status' => 'For Payment']);

        $this->actingAs($clerk)
            ->patchJson($this->statusUrl($request), [
                'status' => 'For Payment',
                'admin_note' => 'Follow up on payment tomorrow',
            ])
            ->assertOk();

        $this->assertSame('Follow up on payment tomorrow', $request->refresh()->admin_note);
        $this->assertSame('For Payment', $request->status);
    }

    public function test_needs_action_cannot_return_to_pending(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $request = $this->createRequest('CORR-002');

        $this->transition($clerk, $request, 'Needs Action');

        $this->actingAs($clerk)
            ->patchJson($this->statusUrl($request), ['status' => 'Pending'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame('Needs Action', $request->refresh()->status);
    }

    public function test_resident_submission_cannot_set_a_status(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170000888',
        ]);

        $this->withSession(['resident_id' => $resident->id])
            ->post('/resident/certificates', [
                'certificate_id' => $this->certificate->id,
                'purpose' => 'Employment',
                'status' => 'Released',
            ])
            ->assertRedirect();

        $request = CertificateRequest::where('resident_id', $resident->id)->firstOrFail();

        $this->assertSame('Pending', $request->status);
    }

    private function snapshot(CertificateRequest $request): array
    {
        return [
            'status' => $request->status,
            'admin_note' => $request->admin_note,
            'reviewed_at' => $request->reviewed_at?->format('Y-m-d H:i:s'),
            'processing_at' => $request->processing_at?->format('Y-m-d H:i:s'),
            'ready_for_claim_at' => $request->ready_for_claim_at?->format('Y-m-d H:i:s'),
            'released_at' => $request->released_at?->format('Y-m-d H:i:s'),
            'rejected_at' => $request->rejected_at?->format('Y-m-d H:i:s'),
            'updated_at' => $request->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function assertUnchanged(CertificateRequest $request, array $before, string $status): void
    {
        $request->refresh();

        $this->assertSame($before, $this->snapshot($request));
        $this->assertSame($status, $request->status);
    }

    private function transition($clerk, CertificateRequest $request, string $status): void
    {
        $this->actingAs($clerk)
            ->patchJson($this->statusUrl($request), ['status' => $status])
            ->assertOk();
    }

    private function statusUrl(CertificateRequest $request): string
    {
        return '/clerk/certificates/requests/'.$request->id.'/status';
    }

    private function confirmPaymentUrl(CertificateRequest $request): string
    {
        return '/clerk/certificates/requests/'.$request->id.'/confirm-payment';
    }

    private function releaseUrl(CertificateRequest $request): string
    {
        return '/clerk/certificates/requests/'.$request->id.'/release';
    }

    private function createIssuance(CertificateRequest $request, $clerk, ?float $fee = null): CertificateIssuance
    {
        return CertificateIssuance::create([
            'barangay_id' => $request->barangay_id,
            'certificate_request_id' => $request->id,
            'resident_id' => $request->resident_id,
            'certificate_type' => 'Indigency',
            'resident_name' => $request->resident?->full_name ?? 'Test Resident',
            'purok' => $request->resident?->purok?->name ?? 'Purok 1',
            'fee' => $fee ?? $request->fee,
            'issued_by' => $clerk->id,
            'issued_at' => now(),
            'reference' => 'ISSUE-'.now()->year.'-'.strtoupper(bin2hex(random_bytes(4))),
        ]);
    }

    private function createRequest(string $reference, array $attributes = []): CertificateRequest
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '0917'.str_pad(
                (string) (abs(crc32($reference)) % 10000000),
                7,
                '0',
                STR_PAD_LEFT
            ),
        ]);

        return CertificateRequest::create([
            'barangay_id' => $this->barangay->id,
            'resident_id' => $resident->id,
            'certificate_id' => $this->certificate->id,
            'purpose' => 'Employment',
            'fee' => 50,
            'status' => 'Pending',
            'submitted_at' => now(),
            'reference' => $reference,
            ...$attributes,
        ]);
    }
}
