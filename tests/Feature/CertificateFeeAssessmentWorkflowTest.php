<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Certificate;
use App\Models\CertificateIssuance;
use App\Models\CertificateRequest;
use App\Models\Collection;
use App\Models\Purok;
use App\Models\Resident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class CertificateFeeAssessmentWorkflowTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Purok $purok;

    private Certificate $certificate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-FEE-ONE');
        $this->purok = $this->createPurok($this->barangay, 'Purok 1');
        $this->certificate = Certificate::create([
            'barangay_id' => $this->barangay->id,
            'code' => 'FEE-CERT',
            'name' => 'Certificate of Indigency',
            'fee' => 50,
            'is_active' => true,
        ]);
    }

    public function test_clerk_can_assess_a_whole_number_fee_and_authoritative_details_show_it(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $request = $this->createRequest('FEE-001');

        $response = $this->actingAs($clerk)
            ->patchJson($this->assessFeeUrl($request), [
                ...$this->assessmentPayload($request, '75'),
            ])
            ->assertOk()
            ->assertJson([
                'message' => 'Certificate fee assessed.',
                'fee' => '75.00',
                'fee_assessment_allowed' => true,
            ]);

        $this->assertSame('75.00', $request->refresh()->fee);
        $this->assertSame($response->json('updated_at'), $request->updated_at?->toJSON());

        $details = $this->actingAs($clerk)
            ->getJson('/clerk/certificates/requests/'.$request->id)
            ->assertOk();

        $details->assertJson([
            'fee' => '75.00',
            'fee_assessment_allowed' => true,
        ]);

        $audit = DB::table('audit_logs')->where('event', 'certificate_request.fee_assessed')->firstOrFail();

        $this->assertSame($clerk->id, (int) $audit->actor_id);
        $this->assertSame($this->barangay->id, (int) $audit->barangay_id);
        $this->assertSame('certificate_request', $audit->entity_type);
        $this->assertSame($request->id, (int) $audit->entity_id);
        $this->assertSame([
            'action' => 'fee_assessed',
            'amount' => '75.00',
            'certificate_reference' => 'FEE-001',
        ], json_decode($audit->context, true));
    }

    public function test_non_clerk_and_foreign_clerk_cannot_assess_fees(): void
    {
        $request = $this->createRequest('FEE-002');
        $admin = $this->createStaff($this->barangay, 'admin');
        $localClerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($admin)
            ->patchJson($this->assessFeeUrl($request), [
                ...$this->assessmentPayload($request, '75'),
            ])
            ->assertForbidden();

        $this->assertSame('50.00', $request->refresh()->fee);

        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-FEE-TWO');
        $otherPurok = $this->createPurok($otherBarangay, 'Purok 2');
        $foreignCertificate = Certificate::create([
            'barangay_id' => $otherBarangay->id,
            'code' => 'FEE-CERT-TWO',
            'name' => 'Certificate of Indigency',
            'fee' => 50,
            'is_active' => true,
        ]);
        $foreignRequest = CertificateRequest::create([
            'barangay_id' => $otherBarangay->id,
            'resident_id' => $this->createResident($otherBarangay, $otherPurok, [
                'mobile' => '09170000099',
            ])->id,
            'certificate_id' => $foreignCertificate->id,
            'reference' => 'FEE-FOREIGN',
            'purpose' => 'Employment',
            'fee' => 50,
            'status' => 'Pending',
            'submitted_at' => now(),
        ]);

        $this->actingAs($localClerk)
            ->patchJson($this->assessFeeUrl($foreignRequest), [
                'fee' => '75',
                'expected_fee' => '50.00',
                'updated_at' => $foreignRequest->updated_at?->toJSON(),
                'assessment_version' => 0,
            ])
            ->assertNotFound();

        $this->assertSame('50.00', $foreignRequest->refresh()->fee);
    }

    public function test_fee_assessment_rejects_decimal_negative_non_numeric_and_malformed_amounts(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $request = $this->createRequest('FEE-003');

        foreach (['12.5', '-1', 'abc', '1e2', '', null] as $invalidFee) {
            $this->actingAs($clerk)
                ->patchJson($this->assessFeeUrl($request), [
                    ...$this->assessmentPayload($request, (string) $invalidFee),
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('fee');

            $this->assertSame('50.00', $request->refresh()->fee);
        }
    }

    public function test_stale_fee_assessment_token_cannot_overwrite_a_newer_assessment(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $request = $this->createRequest('FEE-004');
        $staleToken = $request->updated_at?->toJSON();

        $request->update(['fee' => 90]);

        $this->actingAs($clerk)
            ->patchJson($this->assessFeeUrl($request), [
                'fee' => '75',
                'expected_fee' => '50.00',
                'updated_at' => $staleToken,
                'assessment_version' => 0,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('updated_at');

        $this->assertSame('90.00', $request->refresh()->fee);
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'certificate_request.fee_assessed')->count());
    }

    public function test_assessment_version_rejects_a_competing_assessment_from_the_same_state(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $request = $this->createRequest('FEE-VERSION');

        $first = $this->actingAs($clerk)
            ->patchJson($this->assessFeeUrl($request), [
                ...$this->assessmentPayload($request, '75'),
            ])
            ->assertOk()
            ->assertJson(['assessment_version' => 1]);

        $this->actingAs($clerk)
            ->patchJson($this->assessFeeUrl($request), [
                'fee' => '80',
                'expected_fee' => $first->json('fee'),
                'updated_at' => $first->json('updated_at'),
                'assessment_version' => 0,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assessment_version');

        $this->assertSame('75.00', $request->refresh()->fee);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'certificate_request.fee_assessed')->count());
    }

    public function test_zero_fee_can_be_assessed(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $request = $this->createRequest('FEE-ZERO');

        $this->actingAs($clerk)
            ->patchJson($this->assessFeeUrl($request), [
                ...$this->assessmentPayload($request, '0'),
            ])
            ->assertOk()
            ->assertJson([
                'fee' => '0.00',
                'fee_assessment_allowed' => true,
                'assessment_version' => 1,
            ]);

        $this->assertSame('0.00', $request->refresh()->fee);
    }

    public function test_fee_assessment_is_rejected_after_the_request_reaches_payment(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $request = $this->createRequest('FEE-005', ['status' => 'For Payment']);
        $this->createIssuance($request, $clerk);

        $this->actingAs($clerk)
            ->patchJson($this->assessFeeUrl($request), [
                ...$this->assessmentPayload($request, '75'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fee');

        $this->assertSame('50.00', $request->refresh()->fee);
    }

    public function test_payment_confirmation_and_collection_use_the_persisted_assessed_fee(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $request = $this->createRequest('FEE-006');

        $this->actingAs($clerk)
            ->patchJson($this->assessFeeUrl($request), [
                ...$this->assessmentPayload($request, '75'),
            ])
            ->assertOk();

        $request->refresh()->update(['status' => 'For Payment']);
        $this->createIssuance($request, $clerk);

        $this->actingAs($clerk)
            ->postJson($this->confirmPaymentUrl($request), $this->paymentPayload($request))
            ->assertOk()
            ->assertJson([
                'status' => 'Ready for Claim',
                'collection' => ['amount' => '75.00'],
            ]);

        $collection = Collection::where('certificate_request_id', $request->id)->firstOrFail();

        $this->assertSame('75.00', $collection->amount);
        $this->assertSame('75.00', $request->refresh()->amount_paid);
        $this->assertSame('75.00', $request->fee);
    }

    public function test_payment_confirmation_rejects_a_mismatched_issuance_fee_snapshot(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $request = $this->createRequest('FEE-007', ['status' => 'For Payment']);
        $this->createIssuance($request, $clerk, 90);

        $this->actingAs($clerk)
            ->postJson($this->confirmPaymentUrl($request), $this->paymentPayload($request))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame(0, Collection::where('certificate_request_id', $request->id)->count());
    }

    public function test_payment_confirmation_revalidates_a_concurrently_changed_status(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $request = $this->createRequest('FEE-008', ['status' => 'For Payment']);
        $this->createIssuance($request, $clerk);

        $stopMutation = $this->mutateRequestOnFirstSelect($request->id, [
            'status' => 'Ready for Claim',
            'ready_for_claim_at' => now(),
        ]);

        try {
            $this->actingAs($clerk)
                ->postJson($this->confirmPaymentUrl($request), $this->paymentPayload($request))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('status');
        } finally {
            $stopMutation();
        }

        $this->assertSame(0, Collection::where('certificate_request_id', $request->id)->count());
    }

    public function test_generic_status_endpoint_cannot_enter_for_payment(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $request = $this->createRequest('FEE-009');

        $this->actingAs($clerk)
            ->patchJson($this->statusUrl($request), ['status' => 'For Payment'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame('Pending', $request->refresh()->status);
    }

    public function test_waiver_still_records_a_zero_collection_without_changing_the_assessed_fee(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $request = $this->createRequest('FEE-010');

        $this->actingAs($clerk)
            ->patchJson($this->assessFeeUrl($request), [
                ...$this->assessmentPayload($request, '75'),
            ])
            ->assertOk();

        $request->refresh()->update(['status' => 'For Payment']);
        $this->createIssuance($request, $clerk);

        $this->actingAs($clerk)
            ->postJson($this->confirmPaymentUrl($request), [
                'confirm_payment' => true,
                'amount' => '0',
                'is_waived' => true,
                'waive_reason' => 'Approved indigency waiver',
            ])
            ->assertOk()
            ->assertJson([
                'status' => 'Ready for Claim',
                'collection' => ['amount' => '0.00', 'is_waived' => true],
            ]);

        $request->refresh();

        $this->assertSame('75.00', $request->fee);
        $this->assertSame('0.00', $request->amount_paid);
    }

    public function test_clerk_page_exposes_catalog_fees_for_manual_issuance(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->get('/clerk/certificates')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Clerk/Certificates')
                ->where('certificateFees.Indigency', '50.00'));
    }

    public function test_resident_sees_the_persisted_assessed_fee_and_the_ui_uses_it(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $request = $this->createRequest('FEE-011');
        $resident = Resident::findOrFail($request->resident_id);

        $this->actingAs($clerk)
            ->patchJson($this->assessFeeUrl($request), [
                ...$this->assessmentPayload($request, '75'),
            ])
            ->assertOk();

        $request->refresh()->update(['status' => 'For Payment']);

        $this->withSession(['resident_id' => $resident->id])
            ->get('/resident/certificates')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Resident/Certificates')
                ->where('requests.data.0.id', $request->id)
                ->where('requests.data.0.status', 'For Payment')
                ->where('requests.data.0.fee', 75));

        $residentSource = file_get_contents(resource_path('js/pages/Resident/Certificates.tsx'));
        $this->assertSourceContainsString('Assessed Fee', $residentSource);
        $this->assertSourceContainsString('getStatusMessage(status, fee)', $residentSource);
        $this->assertSourceContainsString('formatAssessedFee', $residentSource);

        $clerkSource = file_get_contents(resource_path('js/pages/Clerk/Certificates.tsx'));
        $this->assertSourceContainsString('/assess-fee', $clerkSource);
        $this->assertSourceContainsString('step="1"', $clerkSource);
        $this->assertSourceContainsString('assessment_version', $clerkSource);
        $this->assertSourceContainsString('normalizeFeeDraft', $clerkSource);
        $this->assertSourceContainsString('setFeeAssessmentDraft(normalizeFeeDraft(response.data.fee))', $clerkSource);
        $this->assertSourceContainsString('certificateFees[type]', $clerkSource);
        $this->assertStringNotContainsString('CERTIFICATE_FEES', $clerkSource);
    }

    private function assessmentPayload(CertificateRequest $request, string $fee): array
    {
        return [
            'fee' => $fee,
            'expected_fee' => (string) $request->fee,
            'updated_at' => $request->updated_at?->toJSON(),
            'assessment_version' => (int) DB::table('audit_logs')
                ->where('barangay_id', $request->barangay_id)
                ->where('event', 'certificate_request.fee_assessed')
                ->where('entity_type', 'certificate_request')
                ->where('entity_id', $request->id)
                ->count(),
        ];
    }

    private function assessFeeUrl(CertificateRequest $request): string
    {
        return '/clerk/certificates/requests/'.$request->id.'/assess-fee';
    }

    private function statusUrl(CertificateRequest $request): string
    {
        return '/clerk/certificates/requests/'.$request->id.'/status';
    }

    private function confirmPaymentUrl(CertificateRequest $request): string
    {
        return '/clerk/certificates/requests/'.$request->id.'/confirm-payment';
    }

    private function paymentPayload(CertificateRequest $request): array
    {
        return [
            'confirm_payment' => true,
            'amount' => (string) $request->fee,
            'or_number' => 'OR-'.str_replace('-', '', $request->reference),
            'is_waived' => false,
        ];
    }

    private function createIssuance(
        CertificateRequest $request,
        $clerk,
        ?float $fee = null,
    ): CertificateIssuance {
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
            'reference' => 'ISSUE-FEE-'.strtoupper(bin2hex(random_bytes(4))),
        ]);
    }

    private function createRequest(string $reference, array $attributes = []): CertificateRequest
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09'.str_pad(
                (string) (abs(crc32($reference)) % 100000000),
                9,
                '0',
                STR_PAD_LEFT,
            ),
        ]);

        return CertificateRequest::create([
            'barangay_id' => $this->barangay->id,
            'resident_id' => $resident->id,
            'certificate_id' => $this->certificate->id,
            'reference' => $reference,
            'purpose' => 'Employment',
            'fee' => 50,
            'status' => 'Pending',
            'submitted_at' => now(),
            ...$attributes,
        ]);
    }

    private function mutateRequestOnFirstSelect(int $requestId, array $attributes): callable
    {
        $handled = false;

        DB::listen(function ($query) use (&$handled, $requestId, $attributes): void {
            if ($handled
                || ! str_contains($query->sql, 'from "certificate_requests"')
                || ! str_contains($query->sql, '"id" = ?')) {
                return;
            }

            $bindings = array_map('strval', $query->bindings ?? []);

            if (! in_array((string) $requestId, $bindings, true)) {
                return;
            }

            $handled = true;
            DB::table('certificate_requests')->where('id', $requestId)->update($attributes);
        });

        return function () use (&$handled): void {
            $handled = true;
        };
    }
}
