<?php

namespace Tests\Feature;

use App\Models\Allocation;
use App\Models\Certificate;
use App\Models\CertificateIssuance;
use App\Models\CertificateRequest;
use App\Models\Collection;
use App\Models\Disbursement;
use App\Models\DisclosurePeriod;
use App\Models\FinancialAttachment;
use App\Models\StaffAssignment;
use App\Models\User;
use App\Support\AllocationBalance;
use App\Support\FinancialDisclosureStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class FinancialDisclosureIntegrationTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_allocation_crud_amendment_and_audit_persist_real_records(): void
    {
        $context = $this->financialContext();
        $this->actingAs($context['admin']);

        $this->post('/admin/financial-disclosure/allocations', [
            'fiscal_year' => 2026,
            'name' => 'General Fund',
            'fund_type' => 'General Fund',
            'category' => 'MOOE',
            'source' => 'IRA',
            'amount_approved' => '1000.00',
            'reference_no' => 'ALLOC-AUDIT-001',
            'status' => 'Active',
        ])->assertRedirect();

        $allocation = Allocation::query()->where('reference_no', 'ALLOC-AUDIT-001')->firstOrFail();

        $this->assertDatabaseHas('audit_logs', [
            'event' => FinancialDisclosureStatus::PUBLIC_EVENT_PREFIX.'allocation_created',
            'barangay_id' => $context['barangay']->id,
            'actor_id' => $context['admin']->id,
        ]);
        $this->assertFinancialAudit('allocation_created', $allocation->id, 'General Fund');

        $this->getJson("/admin/financial-disclosure/allocations/{$allocation->id}")
            ->assertOk()
            ->assertJsonPath('balance.approved', 1000)
            ->assertJsonPath('balance.remaining', 1000);

        $this->post("/admin/financial-disclosure/allocations/{$allocation->id}/amendments", [
            'amount' => '250.00',
            'reference_no' => 'AMD-AUDIT-001',
            'reason' => 'Supplemental budget',
        ])->assertRedirect();

        $this->assertSame('1000.00', (string) $allocation->fresh()->amount_approved);
        $this->assertDatabaseHas('budget_amendments', [
            'allocation_id' => $allocation->id,
            'amount' => 250,
            'reference_no' => 'AMD-AUDIT-001',
        ]);
        $amendmentId = DB::table('budget_amendments')
            ->where('reference_no', 'AMD-AUDIT-001')
            ->value('id');
        $this->assertFinancialAudit('amendment_created', $amendmentId, 'AMD-AUDIT-001');

        $this->getJson("/admin/financial-disclosure/allocations/{$allocation->id}")
            ->assertOk()
            ->assertJsonPath('balance.available', 1250)
            ->assertJsonPath('balance.remaining', 1250);

        $this->put("/admin/financial-disclosure/allocations/{$allocation->id}", [
            'fiscal_year' => 2026,
            'name' => 'General Fund Updated',
            'fund_type' => 'General Fund',
            'category' => 'MOOE',
            'source' => 'IRA',
            'reference_no' => 'ALLOC-AUDIT-001',
            'status' => 'Archived',
        ])->assertRedirect();

        $this->assertDatabaseHas('allocations', [
            'id' => $allocation->id,
            'name' => 'General Fund Updated',
            'status' => 'Archived',
        ]);
        $this->assertFinancialAudit('allocation_updated', $allocation->id, 'General Fund Updated');
    }

    public function test_allocation_combined_filters_sorting_and_pagination_are_backend_driven(): void
    {
        $context = $this->financialContext();
        $this->actingAs($context['admin']);

        for ($index = 1; $index <= 16; $index++) {
            $this->createAllocation([
                'name' => 'PPA '.$index,
                'reference_no' => 'ALLOC-PAGE-'.$index,
                'amount_approved' => (string) (1000 + $index),
            ]);
        }

        $this->createAllocation([
            'name' => 'Excluded PPA',
            'reference_no' => 'ALLOC-EXCLUDED',
            'category' => 'PS',
            'amount_approved' => '99999.00',
        ]);

        $this->get('/admin/financial-disclosure/allocations?fiscal_year=2026&category=MOOE&source=IRA&status=Active&sort=amount_desc')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/FinancialDisclosure/Allocation')
                ->where('allocations.total', 16)
                ->where('allocations.per_page', 15)
                ->where('allocations.data.0.amount_approved', 1016)
                ->where('allocations.next_page_url', fn ($url) => str_contains((string) $url, 'category=MOOE'))
                ->where('filters.category', 'MOOE')
                ->where('filters.sort', 'amount_desc'));
    }

    public function test_collection_crud_duplicate_or_waiver_verification_and_void_workflow(): void
    {
        $context = $this->financialContext();
        $this->actingAs($context['admin']);

        $this->post('/admin/financial-disclosure/collections', [
            'collection_date' => '2026-11-10',
            'collection_type' => 'Certificate fee',
            'amount' => '50.00',
            'or_number' => 'OR-COLLECTION-001',
            'payee_name' => 'Juan Dela Cruz',
            'is_waived' => false,
        ])->assertRedirect();

        $collection = Collection::query()->where('or_number', 'OR-COLLECTION-001')->firstOrFail();

        $this->assertDatabaseHas('collections', [
            'id' => $collection->id,
            'status' => 'Pending',
            'encoded_by' => $context['admin']->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => FinancialDisclosureStatus::PUBLIC_EVENT_PREFIX.'collection_created',
            'actor_id' => $context['admin']->id,
        ]);
        $this->assertFinancialAudit('collection_created', $collection->id, 'OR-COLLECTION-001');

        $this->post('/admin/financial-disclosure/collections', [
            'collection_date' => '2026-11-10',
            'collection_type' => 'Certificate fee',
            'amount' => '75.00',
            'or_number' => ' or-collection-001 ',
            'payee_name' => 'Maria Santos',
            'is_waived' => false,
        ])->assertRedirect()->assertSessionHasErrors('or_number');

        $this->post('/admin/financial-disclosure/collections', [
            'collection_date' => '2026-11-10',
            'collection_type' => 'Certificate fee',
            'amount' => '25.00',
            'or_number' => '',
            'payee_name' => 'Pedro Reyes',
            'is_waived' => true,
        ])->assertRedirect()->assertSessionHasErrors('amount');

        $this->post('/admin/financial-disclosure/collections', [
            'collection_date' => '2026-11-10',
            'collection_type' => 'Certificate fee',
            'amount' => '25.00',
            'or_number' => '',
            'payee_name' => 'Maria Santos',
            'is_waived' => false,
        ])->assertRedirect()->assertSessionHasErrors('or_number');

        $this->post('/admin/financial-disclosure/collections', [
            'collection_date' => '2026-11-10',
            'collection_type' => 'Certificate fee',
            'amount' => '0.00',
            'or_number' => '',
            'payee_name' => 'Pedro Reyes',
            'is_waived' => true,
            'waive_reason' => 'Approved indigency waiver',
        ])->assertRedirect();

        $this->assertDatabaseHas('collections', [
            'or_number' => null,
            'amount' => 0,
            'is_waived' => true,
            'waive_reason' => 'Approved indigency waiver',
        ]);

        $this->post("/admin/financial-disclosure/collections/{$collection->id}/verify")
            ->assertRedirect()
            ->assertSessionHasErrors('verified_by');

        $this->actingAs($context['verifier'])
            ->post("/admin/financial-disclosure/collections/{$collection->id}/verify")
            ->assertRedirect();

        $this->assertDatabaseHas('collections', [
            'id' => $collection->id,
            'status' => 'Verified',
            'verified_by' => $context['verifier']->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => FinancialDisclosureStatus::PUBLIC_EVENT_PREFIX.'collection_verified',
            'actor_id' => $context['verifier']->id,
        ]);
        $this->assertFinancialAudit('collection_verified', $collection->id, 'OR-COLLECTION-001');

        $this->post("/admin/financial-disclosure/collections/{$collection->id}/void", [
            'void_reason' => 'Duplicate OR discovered after verification',
        ])->assertRedirect();

        $this->assertDatabaseHas('collections', [
            'id' => $collection->id,
            'status' => 'Voided',
            'void_reason' => 'Duplicate OR discovered after verification',
        ]);
        $this->assertFinancialAudit('collection_voided', $collection->id, 'OR-COLLECTION-001');
    }

    public function test_certificate_collections_are_created_by_clerk_confirmation_and_admin_cannot_bypass(): void
    {
        $context = $this->financialContext();
        $clerk = $this->createStaff($context['barangay'], 'clerk');
        $certificate = Certificate::create([
            'barangay_id' => $context['barangay']->id,
            'code' => 'FD-CERT-'.Str::upper(Str::random(6)),
            'name' => 'Barangay Clearance',
            'fee' => 50,
            'is_active' => true,
        ]);

        $request = $this->createCollectionCertificateRequest(
            $context,
            $certificate,
            'FD-REQ-PAYABLE-'.Str::upper(Str::random(6)),
            50,
        );
        $this->createIssuanceFor($context, $certificate, $request, $clerk);

        $this->actingAs($context['admin'])
            ->post('/admin/financial-disclosure/collections', [
                'collection_date' => '2026-11-10',
                'collection_type' => 'Certificate fee',
                'amount' => '50.00',
                'or_number' => 'OR-ADMIN-BYPASS-'.Str::upper(Str::random(6)),
                'payee_name' => 'Juan Dela Cruz',
                'certificate_request_id' => $request->id,
                'is_waived' => false,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('certificate_request_id');

        $this->assertDatabaseMissing('collections', [
            'certificate_request_id' => $request->id,
        ]);

        $orNumber = 'OR-CERT-'.Str::upper(Str::random(8));

        $this->actingAs($clerk)
            ->postJson("/clerk/certificates/requests/{$request->id}/confirm-payment", [
                'confirm_payment' => true,
                'amount' => '50.00',
                'or_number' => $orNumber,
                'is_waived' => false,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'Ready for Claim');

        $collection = Collection::query()->where('or_number', $orNumber)->firstOrFail();

        $this->assertDatabaseHas('collections', [
            'id' => $collection->id,
            'certificate_request_id' => $request->id,
            'collection_type' => 'Barangay Clearance',
            'status' => 'Verified',
            'encoded_by' => $clerk->id,
            'verified_by' => $clerk->id,
        ]);
        $this->assertDatabaseHas('certificate_requests', [
            'id' => $request->id,
            'collection_id' => $collection->id,
            'status' => 'Ready for Claim',
            'or_number' => $orNumber,
            'amount_paid' => 50,
        ]);
        $this->assertFinancialAudit('collection_created', $collection->id, $orNumber);
        $this->assertFinancialAudit('collection_verified', $collection->id, $orNumber);

        $createdAudit = DB::table('audit_logs')
            ->where('event', FinancialDisclosureStatus::PUBLIC_EVENT_PREFIX.'collection_created')
            ->where('entity_type', 'financial_collection')
            ->orderByDesc('id')
            ->firstOrFail();
        $auditContext = json_decode((string) $createdAudit->context, true);
        $this->assertSame('50.00', $auditContext['amount'] ?? null);
        $this->assertSame($orNumber, $auditContext['or_number'] ?? null);

        $secondRequest = $this->createCollectionCertificateRequest(
            $context,
            $certificate,
            'FD-REQ-DUP-'.Str::upper(Str::random(6)),
            50,
        );
        $this->createIssuanceFor($context, $certificate, $secondRequest, $clerk);

        $this->actingAs($clerk)
            ->postJson("/clerk/certificates/requests/{$secondRequest->id}/confirm-payment", [
                'confirm_payment' => true,
                'amount' => '50.00',
                'or_number' => strtolower($orNumber),
                'is_waived' => false,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('or_number');

        $this->assertDatabaseMissing('collections', [
            'certificate_request_id' => $secondRequest->id,
        ]);

        $this->actingAs($context['admin'])
            ->post("/admin/financial-disclosure/collections/{$collection->id}/void", [
                'void_reason' => 'Attempted void after release approval',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('collection');

        $this->assertDatabaseHas('collections', [
            'id' => $collection->id,
            'status' => 'Verified',
            'void_reason' => null,
        ]);
        $this->assertSame('Ready for Claim', $request->fresh()->status);
    }

    public function test_admin_cannot_edit_or_verify_certificate_collections(): void
    {
        $context = $this->financialContext();
        $clerk = $this->createStaff($context['barangay'], 'clerk');
        $certificate = Certificate::create([
            'barangay_id' => $context['barangay']->id,
            'code' => 'FD-CERT-'.Str::upper(Str::random(6)),
            'name' => 'Barangay Clearance',
            'fee' => 50,
            'is_active' => true,
        ]);

        $request = $this->createCollectionCertificateRequest(
            $context,
            $certificate,
            'FD-REQ-UPDATE-'.Str::upper(Str::random(6)),
            50,
        );
        $this->createIssuanceFor($context, $certificate, $request, $clerk);

        $orNumber = 'OR-LINKED-UPDATE-'.Str::upper(Str::random(6));

        $this->actingAs($clerk)
            ->postJson("/clerk/certificates/requests/{$request->id}/confirm-payment", [
                'confirm_payment' => true,
                'amount' => '50.00',
                'or_number' => $orNumber,
                'is_waived' => false,
            ])
            ->assertOk();

        $collection = Collection::query()->where('or_number', $orNumber)->firstOrFail();

        $this->actingAs($context['admin'])
            ->put("/admin/financial-disclosure/collections/{$collection->id}", [
                'collection_date' => '2026-11-10',
                'collection_type' => 'Certificate fee',
                'amount' => '25.00',
                'or_number' => $orNumber,
                'payee_name' => 'Juan Dela Cruz',
                'is_waived' => false,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('collection');

        $this->actingAs($context['verifier'])
            ->post("/admin/financial-disclosure/collections/{$collection->id}/verify")
            ->assertRedirect()
            ->assertSessionHasErrors('collection');

        $this->assertDatabaseHas('collections', [
            'id' => $collection->id,
            'amount' => 50,
            'status' => 'Verified',
            'verified_by' => $clerk->id,
        ]);
    }

    public function test_voiding_a_verified_collection_clears_the_certificate_payment_snapshot(): void
    {
        $context = $this->financialContext();
        $this->actingAs($context['admin']);

        $certificate = Certificate::create([
            'barangay_id' => $context['barangay']->id,
            'code' => 'FD-CERT-VOID-'.Str::upper(Str::random(6)),
            'name' => 'Barangay Clearance',
            'fee' => 50,
            'is_active' => true,
        ]);

        $certificateRequest = CertificateRequest::create([
            'barangay_id' => $context['barangay']->id,
            'resident_id' => $context['resident']->id,
            'certificate_id' => $certificate->id,
            'reference' => 'FD-REQ-VOID-'.Str::upper(Str::random(6)),
            'purpose' => 'Employment',
            'fee' => 50,
            'status' => 'For Payment',
            'submitted_at' => now(),
        ]);

        $collection = $this->createCollection([
            'collection_date' => '2026-11-10',
            'collection_type' => $certificate->name,
            'certificate_request_id' => $certificateRequest->id,
            'or_number' => 'OR-VOID-LINK-'.Str::upper(Str::random(6)),
            'status' => 'Verified',
            'verified_by' => $context['verifier']->id,
            'verified_at' => now(),
        ]);

        $certificateRequest->update([
            'collection_id' => $collection->id,
            'or_number' => $collection->or_number,
            'amount_paid' => $collection->amount,
            'is_waived' => false,
        ]);

        $this->post("/admin/financial-disclosure/collections/{$collection->id}/void", [
            'void_reason' => 'OR cancelled by receipting office',
        ])->assertRedirect();

        $this->assertDatabaseHas('collections', [
            'id' => $collection->id,
            'status' => 'Voided',
        ]);
        $this->assertDatabaseHas('certificate_requests', [
            'id' => $certificateRequest->id,
            'collection_id' => null,
            'or_number' => null,
            'amount_paid' => null,
            'is_waived' => false,
        ]);
    }

    public function test_voiding_collection_after_ready_for_claim_demotion_preserves_payment_proof(): void
    {
        $context = $this->financialContext();
        $this->actingAs($context['admin']);

        $certificate = Certificate::create([
            'barangay_id' => $context['barangay']->id,
            'code' => 'FD-CERT-DEMOTED-'.Str::upper(Str::random(6)),
            'name' => 'Barangay Clearance',
            'fee' => 50,
            'is_active' => true,
        ]);
        $certificateRequest = $this->createCollectionCertificateRequest(
            $context,
            $certificate,
            'FD-REQ-DEMOTED-'.Str::upper(Str::random(6)),
            50,
        );

        $collection = $this->createCollection([
            'collection_type' => $certificate->name,
            'certificate_request_id' => $certificateRequest->id,
            'or_number' => 'OR-DEMOTED-'.Str::upper(Str::random(6)),
            'status' => 'Verified',
            'verified_by' => $context['verifier']->id,
            'verified_at' => now(),
        ]);
        $certificateRequest->update([
            'status' => 'Needs Action',
            'ready_for_claim_at' => now(),
            'collection_id' => $collection->id,
            'or_number' => $collection->or_number,
            'amount_paid' => $collection->amount,
            'is_waived' => false,
        ]);

        $this->post("/admin/financial-disclosure/collections/{$collection->id}/void", [
            'void_reason' => 'Attempted reversal after claim readiness',
        ])->assertRedirect()->assertSessionHasErrors('collection');

        $this->assertDatabaseHas('collections', [
            'id' => $collection->id,
            'status' => 'Verified',
            'void_reason' => null,
        ]);
        $this->assertDatabaseHas('certificate_requests', [
            'id' => $certificateRequest->id,
            'status' => 'Needs Action',
            'collection_id' => $collection->id,
            'or_number' => $collection->or_number,
            'amount_paid' => 50,
        ]);
    }

    public function test_voiding_collection_for_released_certificate_is_blocked(): void
    {
        $context = $this->financialContext();
        $this->actingAs($context['admin']);

        $certificate = Certificate::create([
            'barangay_id' => $context['barangay']->id,
            'code' => 'FD-CERT-REL-'.Str::upper(Str::random(6)),
            'name' => 'Barangay Clearance',
            'fee' => 50,
            'is_active' => true,
        ]);
        $certificateRequest = $this->createCollectionCertificateRequest(
            $context,
            $certificate,
            'FD-REQ-RELEASED-'.Str::upper(Str::random(6)),
            50,
        );

        $collection = $this->createCollection([
            'collection_type' => $certificate->name,
            'certificate_request_id' => $certificateRequest->id,
            'or_number' => 'OR-RELEASED-'.Str::upper(Str::random(6)),
            'status' => 'Verified',
            'verified_by' => $context['verifier']->id,
            'verified_at' => now(),
        ]);
        $certificateRequest->update([
            'status' => 'Released',
            'ready_for_claim_at' => now()->subDay(),
            'released_at' => now(),
            'collection_id' => $collection->id,
            'or_number' => $collection->or_number,
            'amount_paid' => $collection->amount,
            'is_waived' => false,
        ]);

        $this->post("/admin/financial-disclosure/collections/{$collection->id}/void", [
            'void_reason' => 'Attempted reversal after release',
        ])->assertRedirect()->assertSessionHasErrors('collection');

        $this->assertDatabaseHas('collections', [
            'id' => $collection->id,
            'status' => 'Verified',
            'void_reason' => null,
        ]);
        $this->assertSame($collection->id, $certificateRequest->fresh()->collection_id);
    }

    public function test_voiding_collection_for_demoted_request_without_claim_proof_is_blocked(): void
    {
        $context = $this->financialContext();
        $this->actingAs($context['admin']);

        $certificate = Certificate::create([
            'barangay_id' => $context['barangay']->id,
            'code' => 'FD-CERT-CORRECTION-'.Str::upper(Str::random(6)),
            'name' => 'Barangay Clearance',
            'fee' => 50,
            'is_active' => true,
        ]);
        $certificateRequest = $this->createCollectionCertificateRequest(
            $context,
            $certificate,
            'FD-REQ-CORRECTION-'.Str::upper(Str::random(6)),
            50,
        );

        $collection = $this->createCollection([
            'collection_type' => $certificate->name,
            'certificate_request_id' => $certificateRequest->id,
            'or_number' => 'OR-CORRECTION-'.Str::upper(Str::random(6)),
            'status' => 'Verified',
            'verified_by' => $context['verifier']->id,
            'verified_at' => now(),
        ]);
        $certificateRequest->update([
            'status' => 'Needs Action',
            'collection_id' => $collection->id,
            'or_number' => $collection->or_number,
            'amount_paid' => $collection->amount,
        ]);

        $this->post("/admin/financial-disclosure/collections/{$collection->id}/void", [
            'void_reason' => 'Attempted reversal during correction',
        ])->assertRedirect()->assertSessionHasErrors('collection');

        $this->assertDatabaseHas('collections', [
            'id' => $collection->id,
            'status' => 'Verified',
            'void_reason' => null,
        ]);
    }

    public function test_collection_index_exposes_request_status_for_void_action_consistency(): void
    {
        $context = $this->financialContext();

        $certificate = Certificate::create([
            'barangay_id' => $context['barangay']->id,
            'code' => 'FD-CERT-UI-'.Str::upper(Str::random(6)),
            'name' => 'Barangay Clearance',
            'fee' => 50,
            'is_active' => true,
        ]);
        $certificateRequest = $this->createCollectionCertificateRequest(
            $context,
            $certificate,
            'FD-REQ-UI-'.Str::upper(Str::random(6)),
            50,
        );
        $collection = $this->createCollection([
            'collection_type' => $certificate->name,
            'certificate_request_id' => $certificateRequest->id,
            'or_number' => 'OR-UI-'.Str::upper(Str::random(6)),
            'status' => 'Verified',
            'verified_by' => $context['verifier']->id,
            'verified_at' => now(),
        ]);
        $certificateRequest->update([
            'collection_id' => $collection->id,
            'or_number' => $collection->or_number,
            'amount_paid' => $collection->amount,
        ]);

        $this->actingAs($context['admin'])
            ->get('/admin/financial-disclosure/collections')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('collections.data.0.id', $collection->id)
                ->where('collections.data.0.certificate_request_status', 'For Payment')
                ->where('collections.data.0.certificate_ready_for_claim', false)
                ->where('collections.data.0.certificate_released', false));
    }

    public function test_collection_analytics_and_filters_are_database_driven(): void
    {
        $context = $this->financialContext();
        $this->actingAs($context['admin']);

        $clearance = Certificate::create([
            'barangay_id' => $context['barangay']->id,
            'code' => 'FD-AN-CLEARANCE',
            'name' => 'Barangay Clearance',
            'fee' => 100,
            'is_active' => true,
        ]);
        $indigency = Certificate::create([
            'barangay_id' => $context['barangay']->id,
            'code' => 'FD-AN-INDIGENCY',
            'name' => 'Certificate of Indigency',
            'fee' => 50,
            'is_active' => true,
        ]);

        $verifiedBy = ['status' => 'Verified', 'verified_by' => $context['verifier']->id, 'verified_at' => now()];

        $requests = [
            'clearance_1' => $this->createCollectionCertificateRequest($context, $clearance, 'FD-AN-REQ-C1', 100),
            'clearance_2' => $this->createCollectionCertificateRequest($context, $clearance, 'FD-AN-REQ-C2', 100),
            'indigency_1' => $this->createCollectionCertificateRequest($context, $indigency, 'FD-AN-REQ-I1', 50),
            'waived' => $this->createCollectionCertificateRequest($context, $clearance, 'FD-AN-REQ-W1', 100),
            'pending' => $this->createCollectionCertificateRequest($context, $indigency, 'FD-AN-REQ-P1', 50),
            'voided' => $this->createCollectionCertificateRequest($context, $clearance, 'FD-AN-REQ-V1', 100),
            'december' => $this->createCollectionCertificateRequest($context, $indigency, 'FD-AN-REQ-D1', 50),
        ];

        $this->createCollection(array_merge($verifiedBy, [
            'collection_date' => '2026-11-09',
            'collection_type' => $clearance->name,
            'amount' => '100.00',
            'or_number' => 'OR-AN-001',
            'certificate_request_id' => $requests['clearance_1']->id,
        ]));
        $this->createCollection(array_merge($verifiedBy, [
            'collection_date' => '2026-11-10',
            'collection_type' => $clearance->name,
            'amount' => '100.00',
            'or_number' => 'OR-AN-002',
            'certificate_request_id' => $requests['clearance_2']->id,
        ]));
        $this->createCollection(array_merge($verifiedBy, [
            'collection_date' => '2026-11-10',
            'collection_type' => $indigency->name,
            'amount' => '50.00',
            'or_number' => 'OR-AN-003',
            'certificate_request_id' => $requests['indigency_1']->id,
        ]));
        $this->createCollection(array_merge($verifiedBy, [
            'collection_date' => '2026-11-10',
            'collection_type' => $clearance->name,
            'amount' => '0.00',
            'or_number' => 'OR-AN-004',
            'certificate_request_id' => $requests['waived']->id,
            'is_waived' => true,
            'waive_reason' => 'Approved indigency waiver',
        ]));
        $this->createCollection([
            'collection_date' => '2026-11-10',
            'collection_type' => $indigency->name,
            'amount' => '50.00',
            'or_number' => 'OR-AN-005',
            'certificate_request_id' => $requests['pending']->id,
            'status' => 'Pending',
        ]);
        $this->createCollection([
            'collection_date' => '2026-11-11',
            'collection_type' => $clearance->name,
            'amount' => '100.00',
            'or_number' => 'OR-AN-006',
            'certificate_request_id' => $requests['voided']->id,
            'status' => 'Voided',
            'void_reason' => 'Duplicate receipt',
        ]);
        $this->createCollection(array_merge($verifiedBy, [
            'collection_date' => '2026-12-01',
            'collection_type' => $indigency->name,
            'amount' => '50.00',
            'or_number' => 'OR-AN-007',
            'certificate_request_id' => $requests['december']->id,
        ]));

        $this->get('/admin/financial-disclosure/collections?period=monthly&date=2026-11-15')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/FinancialDisclosure/Collection')
                ->where('collections.total', 6)
                ->where('analytics.period', 'monthly')
                ->where('analytics.date_from', '2026-11-01')
                ->where('analytics.date_to', '2026-11-30')
                ->where('analytics.totals.amount', 250)
                ->where('analytics.totals.paid_count', 3)
                ->where('analytics.totals.recorded_count', 4)
                ->where('analytics.totals.waived_count', 1)
                ->where('analytics.totals.pending_count', 1)
                ->where('analytics.totals.voided_count', 1)
                ->where('analytics.by_certificate_type', fn ($entries) => collect($entries)->contains(fn ($entry) => $entry['label'] === 'Barangay Clearance' && (float) $entry['amount'] === 200.0 && $entry['count'] === 2))
                ->where('analytics.by_certificate_type', fn ($entries) => collect($entries)->contains(fn ($entry) => $entry['label'] === 'Certificate of Indigency' && (float) $entry['amount'] === 50.0 && $entry['count'] === 1))
                ->where('analytics.trend', fn ($trend) => (float) collect($trend)->firstWhere('key', '2026-11-09')['amount'] === 100.0)
                ->where('analytics.trend', fn ($trend) => (float) collect($trend)->firstWhere('key', '2026-11-10')['amount'] === 150.0)
                ->where('filterOptions.certificates', fn ($certificates) => collect($certificates)->contains(fn ($certificate) => $certificate['label'] === 'Barangay Clearance')));

        $this->get('/admin/financial-disclosure/collections?period=daily&date=2026-11-10')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('collections.total', 4)
                ->where('analytics.totals.amount', 150)
                ->where('analytics.trend', fn ($trend) => count($trend) === 1));

        $this->get('/admin/financial-disclosure/collections?period=weekly&date=2026-11-10')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('analytics.date_from', '2026-11-09')
                ->where('analytics.date_to', '2026-11-15')
                ->where('analytics.totals.amount', 250));

        $this->get('/admin/financial-disclosure/collections?period=yearly&date=2026-12-15')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('analytics.date_from', '2026-01-01')
                ->where('analytics.date_to', '2026-12-31')
                ->where('analytics.totals.amount', 300)
                ->where('analytics.trend', fn ($trend) => (float) collect($trend)->firstWhere('key', '2026-12')['amount'] === 50.0));

        $this->get('/admin/financial-disclosure/collections?period=range&date_from=2026-11-10&date_to=2026-11-30')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('analytics.period', 'range')
                ->where('analytics.totals.amount', 150)
                ->where('filters.date_from', '2026-11-10')
                ->where('filters.date_to', '2026-11-30'));

        $this->get('/admin/financial-disclosure/collections?period=monthly&date=2026-11-15&certificate_id='.$clearance->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.certificate_id', $clearance->id)
                ->where('collections.total', 4)
                ->where('analytics.totals.amount', 250)
                ->where('analytics.totals.paid_count', 3));

        $this->get('/admin/financial-disclosure/collections?period=daily&date=2026-11-10&certificate_id='.$clearance->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('collections.total', 2)
                ->where('analytics.totals.amount', 150)
                ->where('analytics.totals.paid_count', 2)
                ->where('analytics.totals.waived_count', 1));

        $this->get('/admin/financial-disclosure/collections?period=weekly&date=2026-11-10&certificate_id='.$clearance->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('analytics.totals.amount', 250)
                ->where('analytics.totals.paid_count', 3));

        $this->get('/admin/financial-disclosure/collections?period=yearly&date=2026-12-15&certificate_id='.$clearance->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('analytics.totals.amount', 300)
                ->where('analytics.totals.paid_count', 4));

        $this->get('/admin/financial-disclosure/collections?period=range&date_from=2026-11-10&date_to=2026-11-30&certificate_id='.$indigency->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('collections.total', 2)
                ->where('analytics.totals.amount', 150)
                ->where('analytics.totals.paid_count', 2));

        $this->get('/admin/financial-disclosure/collections?period=monthly&date=2026-11-15&status=Pending')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('collections.total', 1)
                ->where('analytics.totals.amount', 250)
                ->where('analytics.totals.paid_count', 3));

        $this->get('/admin/financial-disclosure/collections?period=range&date_from=2026-11-30&date_to=2026-11-10')
            ->assertRedirect()
            ->assertSessionHasErrors('date_to');
    }

    public function test_collection_pagination_preserves_reporting_filters(): void
    {
        $context = $this->financialContext();
        $this->actingAs($context['admin']);

        foreach (range(1, 16) as $index) {
            $this->createCollection([
                'collection_date' => '2026-11-10',
                'collection_type' => 'Certificate fee',
                'amount' => (string) (10 + $index),
                'or_number' => sprintf('OR-PAGE-%03d', $index),
                'status' => 'Pending',
            ]);
        }

        $this->get('/admin/financial-disclosure/collections?period=monthly&date=2026-11-15&status=Pending&waived=0&sort=amount_desc')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('collections.total', 16)
                ->where('collections.per_page', 15)
                ->where('collections.data.0.amount', 26)
                ->where('collections.next_page_url', fn ($url) => str_contains((string) $url, 'period=monthly')
                    && str_contains((string) $url, 'status=Pending')
                    && str_contains((string) $url, 'sort=amount_desc'))
                ->where('filters.period', 'monthly')
                ->where('filters.status', 'Pending'));
    }

    public function test_disbursement_workflow_enforces_balance_and_persists_attribution(): void
    {
        $context = $this->financialContext();
        $this->actingAs($context['admin']);

        $allocation = $this->createAllocation(['amount_approved' => '100.00']);

        $this->post('/admin/financial-disclosure/disbursements', [
            'disbursement_date' => '2026-11-10',
            'allocation_id' => $allocation->id,
            'payee' => 'Supplier One',
            'purpose' => 'Office supplies',
            'amount' => '60.00',
            'dv_number' => 'DV-001',
        ])->assertRedirect();

        $disbursement = Disbursement::query()->where('dv_number', 'DV-001')->firstOrFail();

        $this->assertDatabaseHas('disbursements', [
            'id' => $disbursement->id,
            'status' => 'Draft',
            'prepared_by' => $context['admin']->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => FinancialDisclosureStatus::PUBLIC_EVENT_PREFIX.'disbursement_created',
            'actor_id' => $context['admin']->id,
        ]);
        $this->assertFinancialAudit('disbursement_created', $disbursement->id, 'DV-001');

        $this->post('/admin/financial-disclosure/disbursements', [
            'disbursement_date' => '2026-11-10',
            'allocation_id' => $allocation->id,
            'payee' => 'Supplier Two',
            'purpose' => 'Office supplies',
            'amount' => '50.00',
            'dv_number' => 'DV-002',
        ])->assertRedirect()->assertSessionDoesntHaveErrors();

        $this->post("/admin/financial-disclosure/disbursements/{$disbursement->id}/approve")
            ->assertRedirect();

        $this->assertDatabaseHas('disbursements', [
            'id' => $disbursement->id,
            'status' => 'Approved',
            'approved_by' => $context['admin']->id,
        ]);
        $this->assertFinancialAudit('disbursement_approved', $disbursement->id, 'DV-001');

        $this->put("/admin/financial-disclosure/disbursements/{$disbursement->id}", [
            'disbursement_date' => '2026-11-10',
            'payee' => 'Supplier Renamed',
            'purpose' => 'Office supplies',
            'amount' => '60.00',
            'dv_number' => 'DV-001',
        ])->assertRedirect()->assertSessionHasErrors('status');

        $this->post("/admin/financial-disclosure/disbursements/{$disbursement->id}/release")
            ->assertRedirect();

        $this->assertDatabaseHas('disbursements', [
            'id' => $disbursement->id,
            'status' => 'Released',
            'released_by' => $context['admin']->id,
        ]);
        $this->assertFinancialAudit('disbursement_released', $disbursement->id, 'DV-001');

        $secondDisbursement = Disbursement::query()->where('dv_number', 'DV-002')->firstOrFail();

        $this->post("/admin/financial-disclosure/disbursements/{$secondDisbursement->id}/approve")
            ->assertRedirect()
            ->assertSessionHasErrors('amount');

        $this->assertDatabaseHas('disbursements', [
            'id' => $secondDisbursement->id,
            'status' => 'Draft',
        ]);

        $this->post("/admin/financial-disclosure/disbursements/{$disbursement->id}/void", [
            'void_reason' => 'Incorrect payee',
        ])->assertRedirect();

        $this->assertDatabaseHas('disbursements', [
            'id' => $disbursement->id,
            'status' => 'Voided',
            'void_reason' => 'Incorrect payee',
        ]);
        $this->assertFinancialAudit('disbursement_voided', $disbursement->id, 'DV-001');

        $this->assertSame(100.0, AllocationBalance::totals($allocation->fresh())['remaining']);
    }

    public function test_draft_disbursements_do_not_consume_balance_but_release_enforces_it(): void
    {
        $context = $this->financialContext();
        $this->actingAs($context['admin']);

        $allocation = $this->createAllocation(['amount_approved' => '100.00']);

        foreach ([['DV-SERIAL-1', '60.00'], ['DV-SERIAL-2', '50.00']] as [$dv, $amount]) {
            $this->post('/admin/financial-disclosure/disbursements', [
                'disbursement_date' => '2026-11-10',
                'allocation_id' => $allocation->id,
                'payee' => 'Supplier',
                'purpose' => 'Office supplies',
                'amount' => $amount,
                'dv_number' => $dv,
            ]);
        }

        $this->assertSame(2, Disbursement::query()->where('allocation_id', $allocation->id)->count());
        $this->assertSame(100.0, AllocationBalance::totals($allocation->fresh())['remaining']);

        $first = Disbursement::query()->where('dv_number', 'DV-SERIAL-1')->firstOrFail();
        $second = Disbursement::query()->where('dv_number', 'DV-SERIAL-2')->firstOrFail();

        $this->post("/admin/financial-disclosure/disbursements/{$first->id}/approve")->assertRedirect();
        $this->post("/admin/financial-disclosure/disbursements/{$first->id}/release")->assertRedirect();
        $this->post("/admin/financial-disclosure/disbursements/{$second->id}/approve")
            ->assertRedirect()
            ->assertSessionHasErrors('amount');

        $this->assertSame(40.0, AllocationBalance::totals($allocation->fresh())['remaining']);
    }

    public function test_disbursement_combined_filters_sorting_and_pagination_are_backend_driven(): void
    {
        $context = $this->financialContext();
        $this->actingAs($context['admin']);

        $allocation = $this->createAllocation(['amount_approved' => '10000.00']);

        for ($index = 1; $index <= 16; $index++) {
            $this->createDisbursement($allocation, [
                'payee' => 'Payee '.$index,
                'dv_number' => 'DV-PAGE-'.$index,
                'amount' => (string) (100 + $index),
            ]);
        }

        $this->createDisbursement($allocation, [
            'payee' => 'Excluded Payee',
            'dv_number' => 'DV-EXCLUDED',
            'status' => 'Released',
        ]);

        $this->get('/admin/financial-disclosure/disbursements?payee=Payee&status=Draft&sort=amount_desc')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/FinancialDisclosure/Disbursement')
                ->where('disbursements.total', 16)
                ->where('disbursements.per_page', 15)
                ->where('disbursements.data.0.amount', 116)
                ->where('disbursements.next_page_url', fn ($url) => str_contains((string) $url, 'status=Draft'))
                ->where('filters.payee', 'Payee')
                ->where('filters.status', 'Draft'));
    }

    public function test_status_reports_use_authoritative_records_period_filters_and_audit_history(): void
    {
        $context = $this->financialContext();
        $this->actingAs($context['admin']);

        $allocation = $this->createAllocation(['amount_approved' => '200.00']);

        $this->createCollection([
            'collection_date' => '2026-11-10',
            'amount' => '100.00',
            'status' => 'Verified',
            'or_number' => 'OR-REPORT-1',
        ]);
        $this->createCollection([
            'collection_date' => '2026-11-11',
            'amount' => '999.00',
            'status' => 'Voided',
            'or_number' => 'OR-REPORT-VOID',
        ]);
        $this->createCollection([
            'collection_date' => '2025-10-11',
            'amount' => '888.00',
            'status' => 'Verified',
            'or_number' => 'OR-REPORT-OLD',
        ]);
        $this->createDisbursement($allocation, [
            'disbursement_date' => '2026-11-10',
            'amount' => '60.00',
            'status' => 'Released',
            'dv_number' => 'DV-REPORT-RELEASED',
        ]);
        $this->createDisbursement($allocation, [
            'disbursement_date' => '2026-11-11',
            'amount' => '40.00',
            'status' => 'Draft',
            'dv_number' => 'DV-REPORT-DRAFT',
        ]);

        $this->post('/admin/financial-disclosure/allocations', [
            'fiscal_year' => 2026,
            'name' => 'Audit Report Fund',
            'fund_type' => 'General Fund',
            'category' => 'MOOE',
            'source' => 'IRA',
            'amount_approved' => '10.00',
            'status' => 'Active',
        ])->assertRedirect();

        $this->get('/admin/financial-disclosure/status-reports?year=2026&month=11')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/FinancialDisclosure/StatusAndReports')
                ->where('summary.total_collections', 100)
                ->where('summary.total_disbursements', 60)
                ->where('summary.available_balance', 150)
                ->where('allocations.total', 2)
                ->where('auditHistory.total', 1)
                ->where('blockedSections.publication', ''));

        $this->get('/admin/financial-disclosure/status-reports?year=2025&month=10')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('summary.total_collections', 888)
                ->where('summary.total_disbursements', 0));
    }

    public function test_resident_requested_month_selects_that_published_period(): void
    {
        $context = $this->financialContext();

        DisclosurePeriod::create([
            'barangay_id' => $context['barangay']->id,
            'year' => 2026,
            'month' => 10,
            'is_published' => true,
            'published_by' => $context['admin']->id,
            'published_at' => now(),
        ]);

        DisclosurePeriod::create([
            'barangay_id' => $context['barangay']->id,
            'year' => 2026,
            'month' => 11,
            'is_published' => true,
            'published_by' => $context['admin']->id,
            'published_at' => now(),
        ]);

        $this->withSession(['resident_id' => $context['resident']->id])
            ->get('/resident/financial-disclosure?year=2026&month=10')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Resident/FinancialDisclosure')
                ->where('period.label', '2026-10'));
    }

    public function test_status_report_exports_respect_fiscal_year_and_neutralize_formula_text(): void
    {
        $context = $this->financialContext();
        $this->actingAs($context['admin']);

        $this->createAllocation([
            'name' => 'Old Fiscal Year Allocation',
            'fiscal_year' => 2025,
        ]);
        $this->createAllocation([
            'name' => 'Current Fiscal Year Allocation',
            'fiscal_year' => 2026,
        ]);

        $csv = $this->get('/admin/financial-disclosure/status-reports/exports/allocations?fiscal_year=2025')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Old Fiscal Year Allocation', $csv);
        $this->assertStringNotContainsString('Current Fiscal Year Allocation', $csv);

        $this->createCollection([
            'amount' => '0.00',
            'or_number' => null,
            'is_waived' => true,
            'waive_reason' => '-malicious-formula',
            'status' => 'Verified',
        ]);

        $collectionsCsv = $this->get('/admin/financial-disclosure/status-reports/exports/collections')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString("'-malicious-formula", $collectionsCsv);
    }

    public function test_authorization_tenant_scope_and_staff_assignment_are_enforced(): void
    {
        $context = $this->financialContext();

        $clerk = $this->createStaff($context['barangay'], 'clerk');
        $this->actingAs($clerk)
            ->post('/admin/financial-disclosure/allocations', [])
            ->assertForbidden();

        $foreignBarangay = $this->createBarangay('Foreign Barangay', 'FD-FOREIGN');
        $foreignAdmin = $this->createStaff($foreignBarangay, 'admin');
        $foreignAllocation = $this->createAllocation(['created_by' => $foreignAdmin->id]);
        $foreignDisbursement = $this->createDisbursement($foreignAllocation, [
            'prepared_by' => $foreignAdmin->id,
        ]);

        $this->actingAs($context['admin']);

        $this->getJson("/admin/financial-disclosure/allocations/{$foreignAllocation->id}")->assertNotFound();
        $this->getJson("/admin/financial-disclosure/disbursements/{$foreignDisbursement->id}")->assertNotFound();

        $foreignCollection = $this->createCollection(['encoded_by' => $foreignAdmin->id]);

        $this->get('/admin/financial-disclosure/collections')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('collections.total', 0));

        $this->getJson("/admin/financial-disclosure/collections/{$foreignCollection->id}")->assertNotFound();

        $this->assertTrue(Route::has('admin.financial-disclosure.attachments.store'));

        $this->adminStaffAssignment($context['admin'])->update(['is_active' => false]);

        $this->get('/admin/financial-disclosure/allocations')->assertRedirect(route('login'));
    }

    public function test_publication_and_attachment_audit_events_persist_entity_uuid(): void
    {
        $context = $this->financialContext();
        $this->actingAs($context['admin']);
        Storage::fake('local');

        $this->post('/admin/financial-disclosure/status-reports/publication', [
            'year' => 2026,
            'month' => 11,
            'publish' => true,
        ])->assertRedirect();

        $period = DisclosurePeriod::query()
            ->where('barangay_id', $context['barangay']->id)
            ->where('year', 2026)
            ->where('month', 11)
            ->firstOrFail();

        $this->assertFinancialAudit('disclosure_published', $period->id, '2026-11');

        $this->post('/admin/financial-disclosure/attachments', [
            'title' => 'Proof.pdf',
            'record_type' => 'general',
            'file' => UploadedFile::fake()->createWithContent('proof.pdf', '%PDF-1.4 test'),
        ])->assertRedirect()->assertSessionDoesntHaveErrors();

        $attachment = FinancialAttachment::query()->firstOrFail();

        Storage::disk('local')->assertExists($attachment->file_path);
        $this->assertFinancialAudit('attachment_uploaded', $attachment->id, 'Proof.pdf');
    }

    public function test_collection_csv_export_distinguishes_realized_totals_from_raw_ledger_rows(): void
    {
        $context = $this->financialContext();
        $this->actingAs($context['admin']);

        $this->createCollection([
            'collection_date' => '2026-11-10',
            'or_number' => 'OR-EXPORT-REALIZED',
            'status' => 'Verified',
            'verified_by' => $context['verifier']->id,
            'verified_at' => now(),
        ]);
        $this->createCollection([
            'collection_date' => '2026-11-10',
            'or_number' => 'OR-EXPORT-PENDING',
            'status' => 'Pending',
        ]);

        $realizedCsv = $this->get('/admin/financial-disclosure/status-reports/exports/collections?year=2026&month=11')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('OR-EXPORT-REALIZED', $realizedCsv);
        $this->assertStringNotContainsString('OR-EXPORT-PENDING', $realizedCsv);
        $this->assertStringContainsString('Realized collections', $realizedCsv);

        $rawCsv = $this->get('/admin/financial-disclosure/status-reports/exports/collections?year=2026&month=11&status=Pending')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('OR-EXPORT-PENDING', $rawCsv);
        $this->assertStringNotContainsString('OR-EXPORT-REALIZED', $rawCsv);
        $this->assertStringContainsString('Raw ledger (status: Pending)', $rawCsv);
    }

    private function financialContext(): array
    {
        $barangay = $this->createBarangay('Integration Barangay', 'FD-INTEGRATION-'.Str::upper(Str::random(4)));
        $purok = $this->createPurok($barangay);
        $resident = $this->createResident($barangay, $purok);

        $admin = $this->createAssignedStaff([
            'barangay' => $barangay,
            'purok' => $purok,
        ], 'admin', 'Barangay Admin');

        $verifier = $this->createAssignedStaff([
            'barangay' => $barangay,
            'purok' => $purok,
        ], 'admin', 'Barangay Admin');

        return [
            'barangay' => $barangay,
            'resident' => $resident,
            'admin' => $admin,
            'verifier' => $verifier,
        ];
    }

    private function createAllocation(array $attributes = []): Allocation
    {
        return Allocation::create(array_merge([
            'fiscal_year' => 2026,
            'name' => 'Test PPA',
            'fund_type' => 'General Fund',
            'category' => 'MOOE',
            'source' => 'IRA',
            'amount_approved' => '1000.00',
            'reference_no' => 'FD-'.Str::upper(Str::random(10)),
            'status' => 'Active',
            'created_by' => $this->adminId(),
        ], $attributes));
    }

    private function createCollectionCertificateRequest(array $context, Certificate $certificate, string $reference, float $fee): CertificateRequest
    {
        return CertificateRequest::create([
            'barangay_id' => $context['barangay']->id,
            'resident_id' => $context['resident']->id,
            'certificate_id' => $certificate->id,
            'reference' => $reference,
            'purpose' => 'Employment',
            'fee' => $fee,
            'status' => 'For Payment',
            'submitted_at' => now(),
        ]);
    }

    private function createIssuanceFor(array $context, Certificate $certificate, CertificateRequest $request, User $clerk): CertificateIssuance
    {
        return CertificateIssuance::create([
            'barangay_id' => $context['barangay']->id,
            'certificate_request_id' => $request->id,
            'resident_id' => $context['resident']->id,
            'certificate_type' => $certificate->name,
            'resident_name' => $context['resident']->full_name,
            'purok' => $context['resident']->purok?->name ?? '',
            'fee' => $request->fee,
            'issued_by' => $clerk->id,
            'issued_at' => now(),
            'reference' => 'ISSUE-'.Str::upper(Str::random(10)),
        ]);
    }

    private function createCollection(array $attributes = []): Collection
    {
        return Collection::create(array_merge([
            'collection_date' => '2026-11-10',
            'collection_type' => 'Certificate fee',
            'amount' => '50.00',
            'or_number' => 'OR-'.Str::upper(Str::random(10)),
            'payee_name' => 'Juan Dela Cruz',
            'status' => 'Pending',
            'encoded_by' => $this->adminId(),
        ], $attributes));
    }

    private function createDisbursement(Allocation $allocation, array $attributes = []): Disbursement
    {
        return Disbursement::create(array_merge([
            'disbursement_date' => '2026-11-10',
            'allocation_id' => $allocation->id,
            'payee' => 'Supplier',
            'purpose' => 'Office supplies',
            'amount' => '10.00',
            'dv_number' => 'DV-'.Str::upper(Str::random(10)),
            'status' => 'Draft',
            'prepared_by' => $this->adminId(),
        ], $attributes));
    }

    private function assertFinancialAudit(string $event, string $entityUuid, ?string $entityReference = null): void
    {
        $rows = DB::table('audit_logs')
            ->where('event', FinancialDisclosureStatus::PUBLIC_EVENT_PREFIX.$event)
            ->orderByDesc('id')
            ->get();

        $log = $rows->first(function ($row) use ($entityUuid) {
            $context = json_decode((string) $row->context, true);

            return is_array($context) && ($context['entity_uuid'] ?? null) === $entityUuid;
        });

        $this->assertNotNull($log, 'Missing financial audit event: '.$event);

        $context = json_decode((string) $log->context, true);

        $this->assertSame($entityUuid, $context['entity_uuid'] ?? null);

        if ($entityReference !== null) {
            $this->assertSame($entityReference, $context['entity_reference'] ?? null);
        }
    }

    private function adminId(): int
    {
        return (int) (auth()->id() ?? 1);
    }

    private function adminStaffAssignment(User $user): StaffAssignment
    {
        return $user->staffAssignments()->where('is_active', true)->firstOrFail();
    }
}
