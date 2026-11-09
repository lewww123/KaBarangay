<?php

namespace Tests\Feature;

use App\Models\LuponCase;
use App\Models\LuponComplaint;
use App\Models\LuponDocument;
use App\Models\LuponEvidence;
use App\Support\LuponCaseWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class LuponStaffPortalWorkflowTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private $barangay;
    private $resident;
    private $secretary;
    private $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->barangay = $this->createBarangay('Portal Barangay', 'LP');
        $purok = $this->createPurok($this->barangay);
        $this->resident = $this->createResident($this->barangay, $purok);
        $this->secretary = $this->createAssignedStaff(['barangay' => $this->barangay, 'purok' => $purok], 'lupon', 'Lupon Secretary');
        $this->member = $this->createStaff($this->barangay, 'lupon');
    }

    private function submission(): array
    {
        return ['submission_token' => (string) Str::uuid(), 'respondentName' => 'Respondent', 'respondentResidentStatus' => 'Unknown', 'complaintType' => 'Debt / Money Dispute', 'incidentDate' => now()->subDay()->toDateString(), 'incidentLocation' => 'Purok One', 'narrative' => 'Actual dispute.', 'requestedAction' => 'Mediation.'];
    }

    private function complaint(): LuponComplaint
    {
        $this->withSession(['resident_id' => $this->resident->id])->post('/resident/lupon/complaints', $this->submission())->assertSessionHasNoErrors();
        return LuponComplaint::latest('id')->firstOrFail();
    }

    private function payment(): array
    {
        return ['or_number' => 'ACTUAL-RECEIPT-1', 'payment_date' => LuponCaseWorkflow::today(), 'payment_verified' => true, 'verification_note' => 'Personally compared the original receipt against the actual recorded payment.'];
    }

    private function paidCase(): LuponCase
    {
        $complaint = $this->complaint();
        $this->actingAs($this->secretary)->post('/lupon/complaints/'.$complaint->id.'/request-payment', ['assessed_fee' => '125.50'])->assertSessionHasNoErrors();
        $this->post('/lupon/complaints/'.$complaint->id.'/promote', $this->payment())->assertSessionHasNoErrors();
        return $complaint->case()->firstOrFail();
    }

    private function evidence(LuponCase $case): LuponEvidence
    {
        Storage::disk('local')->put('lupon/test.pdf', '%PDF-test');
        return LuponEvidence::create(['barangay_id' => $case->barangay_id, 'case_id' => $case->id, 'path' => 'lupon/test.pdf', 'original_name' => 'actual-record.pdf', 'mime_type' => 'application/pdf', 'size' => 9, 'uploaded_by' => $this->secretary->id]);
    }

    public function test_submission_is_pending_payment_idempotent_and_not_an_official_case(): void
    {
        $payload = $this->submission();
        $this->withSession(['resident_id' => $this->resident->id])->post('/resident/lupon/complaints', $payload)->assertSessionHasNoErrors();
        $this->post('/resident/lupon/complaints', $payload)->assertSessionHasNoErrors();
        $complaint = LuponComplaint::sole();
        $this->assertSame('Under Review', $complaint->status);
        $this->assertSame($this->resident->id, $complaint->resident_id);
        $this->assertMatchesRegularExpression('/^LUP-\d{4}-\d{5}$/', $complaint->reference);
        $this->assertDatabaseCount('lupon_cases', 0);
        $this->assertNull($complaint->payment_verification);
    }

    public function test_payment_needs_secretary_actual_verification_and_an_existing_fee_assessment(): void
    {
        $complaint = $this->complaint();
        $url = '/lupon/complaints/'.$complaint->id;
        $this->actingAs($this->member)->post($url.'/request-payment', ['assessed_fee' => 100])->assertForbidden();
        $this->post($url.'/promote', $this->payment())->assertForbidden();
        $this->actingAs($this->secretary)->post($url.'/promote', $this->payment())->assertSessionHasErrors('complaint');
        $this->post($url.'/request-payment', ['assessed_fee' => '125.50'])->assertSessionHasNoErrors();
        $this->post($url.'/promote', ['or_number' => 'ARBITRARY'])->assertSessionHasErrors(['payment_verified', 'payment_date', 'verification_note']);
        $this->assertDatabaseCount('lupon_cases', 0);
        $this->post($url.'/promote', $this->payment())->assertSessionHasNoErrors();
        $case = $complaint->case()->sole();
        $this->assertSame('paid', $case->payment_status);
        $this->assertSame('Payment Verified', $complaint->fresh()->status);
        $this->assertSame($this->secretary->id, $complaint->fresh()->payment_verification['actor_id']);
        $this->assertNotEmpty($complaint->fresh()->payment_verification['verified_at']);
        $this->assertDatabaseCount('lupon_hearings', 0);
        $this->post($url.'/promote', $this->payment())->assertSessionHasNoErrors();
        $this->assertDatabaseCount('lupon_cases', 1);
    }

    public function test_internal_notes_and_other_residents_complaints_are_not_exposed(): void
    {
        $complaint = $this->complaint();
        $complaint->update(['lupon_note' => 'PRIVATE STAFF NOTE']);
        $this->get('/resident/lupon')->assertInertia(fn ($p) => $p->where('complaints.data.0.luponNote', null));
        $other = $this->createResident($this->barangay, $this->resident->purok, ['mobile' => '09179999991']);
        $this->withSession(['resident_id' => $other->id])->get('/resident/lupon')->assertInertia(fn ($p) => $p->has('complaints.data', 0));
        $this->post('/lupon/complaints/'.$complaint->id.'/promote', $this->payment())->assertRedirect();
        $this->assertDatabaseCount('lupon_cases', 0);
    }

    public function test_registry_reports_profile_and_payment_queue_use_real_scoped_records(): void
    {
        $case = $this->paidCase();
        foreach (['pangkat', 'arbitration', 'records', 'documents'] as $module) {
            $this->get('/lupon/'.$module)->assertOk()->assertInertia(fn ($p) => $p->component('Lupon/Registry')->where('module', $module)->has('records.data', $module === 'documents' ? 2 : 0));
        }
        $this->get('/lupon/reports')->assertOk()->assertInertia(fn ($p) => $p->where('report.complaints', 1)->where('report.cases', 1)->where('report.paymentVerified', 1));
        $this->get('/lupon/payments')->assertOk()->assertInertia(fn ($p) => $p->where('queueMode', 'payments')->where('canVerifyPayments', true));
        $this->get('/lupon/profile')->assertOk()->assertInertia(fn ($p) => $p->where('profile.canVerifyPayments', true));
        $foreign = $this->createBarangay('Another', 'OTHER');
        $staff = $this->createStaff($foreign, 'lupon');
        $this->actingAs($staff)->get('/lupon/reports')->assertOk()->assertInertia(fn ($p) => $p->where('report.cases', 0));
        $this->post('/lupon/cases/'.$case->id.'/proceedings', [])->assertForbidden();
    }

    public function test_preparation_printing_issuance_and_service_are_distinct(): void
    {
        $case = $this->paidCase();
        Storage::disk('local')->put('lupon/notice.docx', 'prepared document');
        $doc = LuponDocument::create(['case_id' => $case->id, 'kind' => 'summons', 'status' => 'ready', 'path' => 'lupon/notice.docx', 'file_name' => 'summons.docx', 'created_by' => $this->secretary->id, 'generated_at' => now()->subHour()]);
        $this->get('/lupon/documents/'.$doc->id.'/preview')->assertOk();
        $this->assertNull($doc->fresh()->served_at);
        $service = ['served_at' => now()->toIso8601String(), 'service_method' => 'personal', 'service_note' => 'Original acknowledgment received.', 'receipt_confirmed' => true];
        $this->post('/lupon/documents/'.$doc->id.'/serve', $service)->assertSessionHasErrors('document');
        $this->post('/lupon/documents/'.$doc->id.'/issue', ['issued_at' => now()->toIso8601String(), 'content_reviewed' => true])->assertSessionHasNoErrors();
        $this->assertNotNull($doc->fresh()->issued_at);
        $this->assertNull($doc->fresh()->served_at);
        $this->assertSame('filed', $case->fresh()->status);
        $this->post('/lupon/documents/'.$doc->id.'/serve', $service)->assertSessionHasNoErrors();
        $this->assertNotNull($doc->fresh()->served_at);
        $this->assertSame('filed', $case->fresh()->status);
    }

    public function test_arbitration_award_and_execution_require_actual_records_and_never_start_automatically(): void
    {
        $case = $this->paidCase();
        LuponCaseWorkflow::transition($case, 'mediation_active', $this->secretary->id, ['event_date' => LuponCaseWorkflow::today()]);
        $case->refresh();
        $evidence = $this->evidence($case);
        $url = '/lupon/cases/'.$case->id.'/proceedings';
        $data = ['event_date' => LuponCaseWorkflow::today(), 'notes' => 'Actual verified proceeding.', 'evidence_id' => $evidence->id];
        $this->post($url, [...$data, 'action' => 'arbitration_agreement'])->assertSessionHasErrors('proceeding');
        $this->assertSame('mediation_active', $case->fresh()->status);
        $this->post($url, [...$data, 'action' => 'arbitration_agreement', 'parties_agreed' => true])->assertSessionHasNoErrors();
        $this->assertSame('arbitration_active', $case->fresh()->status);
        $this->post($url, [...$data, 'action' => 'arbitration_award'])->assertSessionHasErrors('proceeding');
        $this->post($url, [...$data, 'action' => 'arbitration_session', 'attendance' => 'Both parties attended.'])->assertSessionHasNoErrors();
        $this->post($url, [...$data, 'action' => 'arbitration_award'])->assertSessionHasNoErrors();
        $this->assertSame('arbitration_awarded', $case->fresh()->status);
        $this->post($url, [...$data, 'action' => 'execution'])->assertSessionHasNoErrors();
        $this->assertSame('executed', $case->fresh()->status);
        $this->assertCount(6, $case->fresh()->events);
    }
}
