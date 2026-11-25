<?php

namespace Tests\Feature;

use App\Models\Allocation;
use App\Models\BudgetAmendment;
use App\Models\Certificate;
use App\Models\CertificateRequest;
use App\Models\Collection;
use App\Models\Disbursement;
use App\Models\DisclosurePeriod;
use App\Models\FinancialAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class FinancialDisclosureModelTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    public function test_financial_models_use_uuid_keys_and_erd_relationships(): void
    {
        $barangay = $this->createBarangay('Model Barangay', 'FD-MODEL');
        $purok = $this->createPurok($barangay);
        $user = $this->createStaff($barangay, 'admin');

        $allocation = Allocation::create([
            'fiscal_year' => 2026,
            'name' => 'General Fund',
            'fund_type' => 'General',
            'category' => 'MOOE',
            'source' => 'National Tax Allotment',
            'amount_approved' => '100000.00',
            'reference_no' => 'ALLOC-001',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $amendment = BudgetAmendment::create([
            'allocation_id' => $allocation->id,
            'amount' => '-5000.00',
            'reference_no' => 'BA-001',
            'reason' => 'Realignment',
            'created_by' => $user->id,
            'created_at' => now(),
        ]);

        $disbursement = Disbursement::create([
            'disbursement_date' => '2026-11-25',
            'allocation_id' => $allocation->id,
            'payee' => 'Test Supplier',
            'purpose' => 'Office supplies',
            'amount' => '12000.00',
            'dv_number' => 'DV-001',
            'status' => 'draft',
            'prepared_by' => $user->id,
        ]);

        $collection = Collection::create([
            'collection_date' => '2026-11-25',
            'collection_type' => 'Certificate fee',
            'amount' => '50.00',
            'or_number' => 'OR-FD-001',
            'payee_name' => 'Juan Dela Cruz',
            'encoded_by' => $user->id,
        ]);

        $period = DisclosurePeriod::create([
            'barangay_id' => $barangay->id,
            'year' => 2026,
            'month' => 11,
            'is_published' => false,
            'published_by' => $user->id,
        ]);

        $attachment = FinancialAttachment::create([
            'record_type' => Allocation::class,
            'record_id' => $allocation->id,
            'title' => 'Allocation certificate',
            'file_path' => 'financial/test-allocation.pdf',
            'uploaded_by' => $user->id,
            'uploaded_at' => now(),
        ]);

        $this->assertSame(36, strlen($allocation->id));
        $this->assertSame(36, strlen($collection->id));
        $this->assertTrue($allocation->is($amendment->allocation));
        $this->assertTrue($allocation->is($disbursement->allocation));
        $this->assertTrue($user->is($allocation->createdBy));
        $this->assertTrue($user->is($period->publishedBy));
        $this->assertTrue($user->is($attachment->uploadedBy));
        $this->assertTrue($allocation->is($attachment->record));
    }

    public function test_certificate_request_collection_relationship_is_bidirectional(): void
    {
        $barangay = $this->createBarangay('Certificate Barangay', 'FD-CERT');
        $purok = $this->createPurok($barangay);
        $user = $this->createStaff($barangay, 'admin');

        $certificate = Certificate::create([
            'barangay_id' => $barangay->id,
            'code' => 'FD-CLEARANCE',
            'name' => 'Financial Disclosure Clearance',
            'fee' => 50,
            'is_active' => true,
        ]);

        $certificateRequest = CertificateRequest::create([
            'barangay_id' => $barangay->id,
            'resident_id' => $this->createResident($barangay, $purok)->id,
            'certificate_id' => $certificate->id,
            'reference' => 'FD-REQ-001',
            'purpose' => 'Employment',
            'fee' => 50,
            'status' => 'Pending',
            'submitted_at' => now(),
        ]);

        $collection = Collection::create([
            'collection_date' => '2026-11-25',
            'collection_type' => 'Certificate fee',
            'amount' => '50.00',
            'or_number' => 'OR-FD-CERT-001',
            'payee_name' => 'Juan Dela Cruz',
            'certificate_request_id' => $certificateRequest->id,
            'encoded_by' => $user->id,
        ]);

        $certificateRequest->update(['collection_id' => $collection->id]);

        $this->assertTrue($certificateRequest->is($collection->certificateRequest));
        $this->assertTrue($collection->is($certificateRequest->collection));
        $this->assertTrue($certificateRequest->is($collection->linkedCertificateRequest));
    }
}
