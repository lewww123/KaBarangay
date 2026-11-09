<?php

namespace Tests\Feature;

use App\Models\LuponComplaint;
use App\Models\Resident;
use App\Models\User;
use App\Support\LuponCaseWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

/**
 * Simplified complaint workflow: staff assesses the fee under review, the
 * resident pays at the barangay office, and only a secretary-verified payment
 * creates the official case.
 */
class LuponComplaintWorkflowTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private $barangay;

    private $purok;

    private Resident $resident;

    private User $secretary;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');

        $this->barangay = $this->createBarangay('Workflow Barangay', 'FLOW');
        $this->purok = $this->createPurok($this->barangay, 'Purok One');
        $this->resident = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Ana',
            'last_name' => 'Complainant',
            'mobile' => '09170003333',
        ]);
        $this->secretary = $this->createAssignedStaff(
            ['barangay' => $this->barangay, 'purok' => $this->purok],
            'lupon',
            'Lupon Secretary',
        );
        $this->member = $this->createAssignedStaff(
            ['barangay' => $this->barangay, 'purok' => $this->purok],
            'lupon',
            'Lupon Member',
        );
    }

    private function onlinePayload(array $overrides = []): array
    {
        return array_merge([
            'submission_token' => (string) Str::uuid(),
            'respondentName' => 'Pedro Santos',
            'respondentResidentStatus' => 'Yes',
            'respondentAddress' => 'Purok Two',
            'complaintType' => 'Noise / Disturbance',
            'incidentDate' => '2026-10-03',
            'incidentLocation' => 'Purok One',
            'narrative' => 'Repeated disturbances late at night.',
            'requestedAction' => 'Request mediation from the Lupon.',
        ], $overrides);
    }

    private function walkInPayload(array $overrides = []): array
    {
        return array_merge([
            'submission_token' => (string) Str::uuid(),
            'complaint_type' => 'Debt / Money Dispute',
            'incident_date' => '2026-10-01',
            'incident_location' => 'Purok One basketball court',
            'narrative' => 'Recorded at the desk from the walk-in complainant.',
            'requested_action' => 'Mediation.',
            'complainant_name' => 'Maria Santos',
            'complainant_is_resident' => false,
            'respondent_name' => 'Jose Santos',
            'respondent_is_resident' => false,
        ], $overrides);
    }

    private function onlineComplaint(): LuponComplaint
    {
        $this->withSession(['resident_id' => $this->resident->id])
            ->post('/resident/lupon/complaints', $this->onlinePayload())
            ->assertSessionHasNoErrors();

        return LuponComplaint::latest('id')->firstOrFail();
    }

    private function walkInComplaint(array $overrides = []): LuponComplaint
    {
        $this->actingAs($this->member)
            ->post('/lupon/complaints/walk-in', $this->walkInPayload($overrides))
            ->assertSessionHasNoErrors();

        return LuponComplaint::latest('id')->firstOrFail();
    }

    private function assess(LuponComplaint $complaint, string $fee = '500.00', ?User $actor = null): void
    {
        $this->actingAs($actor ?? $this->secretary)
            ->post('/lupon/complaints/'.$complaint->id.'/request-payment', [
                'assessed_fee' => $fee,
                'lupon_note' => 'Pay at the barangay cashier and keep your receipt.',
            ])
            ->assertSessionHasNoErrors();
    }

    private function historical(string $reference, string $status, int $minutesAgo): LuponComplaint
    {
        $complaint = LuponComplaint::create([
            'barangay_id' => $this->barangay->id,
            'resident_id' => $this->resident->id,
            'reference' => $reference,
            'respondent_name' => 'Maria Reyes',
            'respondent_resident_status' => 'Yes',
            'complaint_type' => 'Debt / Money Dispute',
            'incident_date' => '2026-10-02',
            'incident_location' => 'Purok One',
            'narrative' => 'An unsettled personal debt between the parties.',
            'requested_action' => 'Request mediation.',
            'status' => $status,
            'submitted_at' => now(),
        ]);

        // The queue orders by created_at, so each fixture needs a distinct age.
        $complaint->created_at = now()->subMinutes($minutesAgo);
        $complaint->save();

        return $complaint->fresh();
    }

    private function receipt(array $overrides = []): array
    {
        return array_merge([
            'or_number' => 'OR-WF-001',
            'payment_verified' => true,
            'payment_date' => LuponCaseWorkflow::today(),
            'verification_note' => 'Compared the original official receipt with the cash receipt book.',
        ], $overrides);
    }

    public function test_online_and_walk_in_submissions_both_enter_the_under_review_tab(): void
    {
        $online = $this->onlineComplaint();
        $online->created_at = now()->subHour();
        $online->save();
        $walkIn = $this->walkInComplaint();

        $this->assertSame('Under Review', $online->status);
        $this->assertSame('Under Review', $walkIn->status);
        $this->assertDatabaseCount('lupon_cases', 0);

        $this->actingAs($this->member)->get('/lupon/complaints')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Lupon/Complaints')
                ->where('queueTabs', ['Under Review', 'For Payment'])
                ->where('activeQueueTab', 'Under Review')
                ->has('complaints.data', 2)
                ->where('complaints.data.0.status', 'Under Review')
                ->where('complaints.data.0.source', 'walk-in')
                ->where('complaints.data.1.status', 'Under Review')
                ->where('complaints.data.1.source', 'online'));
    }

    public function test_submitting_the_assessment_saves_the_fee_and_opens_the_payment_stage(): void
    {
        $complaint = $this->onlineComplaint();

        $this->assess($complaint, '500.00');
        $complaint->refresh();

        $this->assertSame('For Payment', $complaint->status);
        $this->assertEquals(500.00, (float) $complaint->assessed_fee);
        $this->assertNotNull($complaint->payment_requested_at);
        $this->assertNotNull($complaint->reviewed_at);
        $this->assertNull($complaint->payment_verification);
        $this->assertDatabaseCount('lupon_cases', 0);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'lupon_complaint.payment_requested',
            'actor_id' => $this->secretary->id,
            'entity_type' => 'lupon_complaint',
            'entity_id' => $complaint->id,
        ]);

        $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/lupon')
            ->assertInertia(fn ($page) => $page
                ->where('complaints.data.0.trackingStatus', 'For Payment')
                ->where('complaints.data.0.assessedFee', '500.00')
                ->where('complaints.data.0.luponNote', null)
                ->where('complaints.data.0.case', null));

        $this->actingAs($this->member)->get('/lupon/complaints?tab=For+Payment')
            ->assertInertia(fn ($page) => $page
                ->where('activeQueueTab', 'For Payment')
                ->where('complaints.data.0.assessedFee', '500.00'));
    }

    public function test_a_second_assessment_is_rejected_and_keeps_the_record_consistent(): void
    {
        $complaint = $this->onlineComplaint();
        $this->assess($complaint, '500.00');

        $this->actingAs($this->secretary)
            ->post('/lupon/complaints/'.$complaint->id.'/request-payment', ['assessed_fee' => '800.00'])
            ->assertSessionHasErrors('assessed_fee');

        $complaint->refresh();

        $this->assertSame('For Payment', $complaint->status);
        $this->assertEquals(500.00, (float) $complaint->assessed_fee);
        $this->assertDatabaseCount('lupon_cases', 0);
    }

    public function test_reviewers_cannot_assess_or_verify_payment_without_secretary_authority(): void
    {
        $complaint = $this->onlineComplaint();

        $this->actingAs($this->member)
            ->post('/lupon/complaints/'.$complaint->id.'/request-payment', ['assessed_fee' => '500.00'])
            ->assertForbidden();
        $this->actingAs($this->member)
            ->post('/lupon/complaints/'.$complaint->id.'/promote', $this->receipt())
            ->assertForbidden();

        $this->assertSame('Under Review', $complaint->refresh()->status);
        $this->assertDatabaseCount('lupon_cases', 0);

        $this->actingAs($this->secretary)
            ->post('/lupon/complaints/'.$complaint->id.'/promote', $this->receipt())
            ->assertSessionHasErrors('complaint');
        $this->assertDatabaseCount('lupon_cases', 0);
    }

    public function test_an_or_number_alone_is_not_proof_of_payment(): void
    {
        $complaint = $this->onlineComplaint();
        $this->assess($complaint);

        $incomplete = array_diff_key($this->receipt(), array_flip(['payment_verified', 'payment_date', 'verification_note']));

        $this->actingAs($this->secretary)
            ->post('/lupon/complaints/'.$complaint->id.'/promote', $incomplete)
            ->assertSessionHasErrors(['payment_verified', 'payment_date', 'verification_note']);

        $this->assertSame('For Payment', $complaint->refresh()->status);
        $this->assertNull($complaint->payment_verification);
        $this->assertDatabaseCount('lupon_cases', 0);
        $this->assertDatabaseCount('lupon_hearings', 0);
    }

    public function test_an_uploaded_receipt_alone_is_not_proof_of_payment(): void
    {
        $complaint = $this->walkInComplaint([
            'evidence' => [UploadedFile::fake()->create('official-receipt.pdf', 12, 'application/pdf')],
        ]);
        $this->assess($complaint, '250.00');

        $this->assertSame(1, $complaint->evidence()->count());
        $this->actingAs($this->secretary)
            ->post('/lupon/complaints/'.$complaint->id.'/promote', [
                'or_number' => 'OR-WF-UPLOAD',
                'payment_verified' => false,
            ])
            ->assertSessionHasErrors(['payment_verified', 'payment_date', 'verification_note']);

        $this->assertSame('For Payment', $complaint->refresh()->status);
        $this->assertDatabaseCount('lupon_cases', 0);
    }

    public function test_verified_payment_creates_exactly_one_case_and_leaves_the_payment_queue(): void
    {
        $complaint = $this->walkInComplaint([
            'evidence' => [UploadedFile::fake()->create('annex.pdf', 10, 'application/pdf')],
        ]);
        $reference = $complaint->reference;
        $this->assess($complaint, '350.00');

        $this->actingAs($this->secretary)
            ->post('/lupon/complaints/'.$complaint->id.'/promote', $this->receipt(['or_number' => 'OR-WF-CASE']))
            ->assertSessionHasNoErrors();

        $complaint->refresh();
        $case = $complaint->case()->firstOrFail();

        $this->assertSame('Payment Verified', $complaint->status);
        $this->assertSame($reference, $complaint->reference);
        $this->assertSame('promoted', $case->origin);
        $this->assertSame('paid', $case->payment_status);
        $this->assertEquals(350.00, (float) $case->fee_amount);
        $this->assertSame('OR-WF-CASE', $case->or_number);
        $this->assertSame($this->secretary->id, $complaint->payment_verification['actor_id']);
        $this->assertSame(1, $complaint->evidence()->count());
        $this->assertDatabaseCount('lupon_cases', 1);

        // The walk-in party snapshot is carried into the case.
        $party = $case->parties()->where('party_type', 'complainant')->sole();
        $this->assertSame('Maria Santos', $party->name);
        $this->assertFalse((bool) $party->is_resident);
        $this->assertNull($party->resident_id);
        $this->assertStringContainsString('Maria Santos vs. Jose Santos', $case->title);

        // Repeating the submission must not duplicate the case.
        $this->post('/lupon/complaints/'.$complaint->id.'/promote', $this->receipt(['or_number' => 'OR-WF-CASE-2']))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('lupon_cases', 1);

        $this->get('/lupon/complaints?tab=For+Payment')->assertInertia(fn ($page) => $page->has('complaints.data', 0));
        $this->get('/lupon/complaints')->assertInertia(fn ($page) => $page->has('complaints.data', 0));
        $this->get('/lupon/complaints?tab=Closed')->assertInertia(fn ($page) => $page
            ->has('complaints.data', 1)
            ->where('complaints.data.0.case.caseNumber', $case->case_number));

        $this->get('/lupon/cases')->assertInertia(fn ($page) => $page
            ->where('cases.data.0.caseNumber', $case->case_number)
            ->where('cases.data.0.complaintReference', $reference));

        // A walk-in complainant has no portal account, so this case is never
        // exposed through an unrelated resident's tracker.
        $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/lupon')
            ->assertInertia(fn ($page) => $page->has('complaints.data', 0));
    }

    public function test_resident_tracking_reports_the_three_stages_without_internal_detail(): void
    {
        $complaint = $this->onlineComplaint();
        $complaint->update(['lupon_note' => 'PRIVATE STAFF NOTE']);

        $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/lupon')
            ->assertInertia(fn ($page) => $page
                ->where('complaints.data.0.status', 'Under Review')
                ->where('complaints.data.0.trackingStatus', 'Under Review')
                ->where('complaints.data.0.luponNote', null)
                ->where('complaints.data.0.assessedFee', null));

        $this->assess($complaint, '500.00');
        $this->actingAs($this->secretary)
            ->post('/lupon/complaints/'.$complaint->id.'/promote', $this->receipt(['or_number' => 'OR-WF-TRACK']))
            ->assertSessionHasNoErrors();

        $case = $complaint->case()->firstOrFail();

        $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/lupon')
            ->assertInertia(fn ($page) => $page
                ->where('complaints.data.0.case.id', $case->id)
                ->where('complaints.data.0.case.caseNumber', $case->case_number)
                ->where('complaints.data.0.luponNote', null)
                ->where('complaints.data.0.reference', $complaint->reference));
    }

    public function test_historical_records_stay_in_the_stage_they_meant(): void
    {
        $submitted = $this->historical('LUP-2026-70001', 'Submitted', 30);
        $legacyPayment = $this->historical('LUP-2026-70002', 'Pending Payment', 20);
        $legacyVerification = $this->historical('LUP-2026-70003', 'Payment Verification', 10);
        $rejected = $this->historical('LUP-2026-70004', 'Rejected', 5);

        $this->actingAs($this->member)->get('/lupon/complaints')
            ->assertInertia(fn ($page) => $page
                ->where('activeQueueTab', 'Under Review')
                ->where('complaints.data.0.reference', $submitted->reference));

        $this->get('/lupon/complaints?tab=For+Payment')
            ->assertInertia(fn ($page) => $page
                ->has('complaints.data', 2)
                ->where('complaints.data.0.reference', $legacyVerification->reference)
                ->where('complaints.data.1.reference', $legacyPayment->reference));

        $this->get('/lupon/complaints?tab=Closed')
            ->assertInertia(fn ($page) => $page
                ->has('complaints.data', 1)
                ->where('complaints.data.0.reference', $rejected->reference));

        // Old per-status links keep working and resolve to their tab.
        $this->get('/lupon/complaints?status=Submitted')
            ->assertInertia(fn ($page) => $page
                ->where('activeQueueTab', 'Under Review')
                ->where('complaints.data.0.status', 'Submitted'));
        $this->get('/lupon/complaints?status=Rejected')
            ->assertInertia(fn ($page) => $page
                ->where('activeQueueTab', 'Closed')
                ->where('complaints.data.0.status', 'Rejected'));

        // The payment verification queue keeps its unfiltered behaviour.
        $this->get('/lupon/payments')
            ->assertInertia(fn ($page) => $page
                ->where('queueMode', 'payments')
                ->where('activeQueueTab', null)
                ->has('complaints.data', 4));
    }

    public function test_resident_reads_case_created_as_the_primary_stage_once_a_case_exists(): void
    {
        $complaint = $this->onlineComplaint();
        $this->assess($complaint, '450.00');

        $this->actingAs($this->secretary)
            ->post('/lupon/complaints/'.$complaint->id.'/promote', $this->receipt(['or_number' => 'OR-WF-D1']))
            ->assertSessionHasNoErrors();

        $case = $complaint->case()->firstOrFail();

        // A freshly docketed case must not read as the review stage again.
        $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/lupon')
            ->assertInertia(fn ($page) => $page
                ->where('complaints.data.0.trackingStatus', 'Case Created')
                ->where('complaints.data.0.caseProgress', null)
                ->where('complaints.data.0.case.id', $case->id)
                ->where('complaints.data.0.luponNote', null));

        // Real case progress stays available as secondary information.
        $case->update(['form_7_issued_at' => now()]);

        $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/lupon')
            ->assertInertia(fn ($page) => $page
                ->where('complaints.data.0.trackingStatus', 'Case Created')
                ->where('complaints.data.0.caseProgress', 'Summoned'));
    }

    public function test_replaying_an_unregistered_walk_in_token_creates_one_submission(): void
    {
        $payload = $this->walkInPayload(['submission_token' => '22222222-2222-4222-8222-222222222222']);

        $this->actingAs($this->member)->post('/lupon/complaints/walk-in', $payload)
            ->assertSessionHasNoErrors();
        $this->post('/lupon/complaints/walk-in', $payload)
            ->assertSessionHasNoErrors();

        $complaint = LuponComplaint::sole();

        $this->assertNull($complaint->resident_id);
        $this->assertSame('Under Review', $complaint->status);
        $this->assertSame('Maria Santos', $complaint->complainant_snapshot['name']);
        $this->assertDatabaseCount('lupon_complaints', 1);
        $this->assertDatabaseCount('lupon_cases', 0);
    }

    public function test_a_terminal_complaint_cannot_be_assessed_or_verified(): void
    {
        $accepted = $this->historical('LUP-2026-73001', 'Accepted', 40);
        $rejected = $this->historical('LUP-2026-73002', 'Rejected', 35);

        $this->actingAs($this->secretary)
            ->post('/lupon/complaints/'.$accepted->id.'/request-payment', ['assessed_fee' => '300.00'])
            ->assertSessionHasErrors('assessed_fee');
        $this->post('/lupon/complaints/'.$rejected->id.'/request-payment', ['assessed_fee' => '300.00'])
            ->assertSessionHasErrors('assessed_fee');

        $this->assertNull($accepted->refresh()->assessed_fee);
        $this->assertSame('Accepted', $accepted->status);
        $this->assertSame('Rejected', $rejected->refresh()->status);

        // A final complaint cannot reach payment verification either.
        $this->post('/lupon/complaints/'.$accepted->id.'/promote', $this->receipt(['or_number' => 'OR-WF-D4']))
            ->assertSessionHasErrors('complaint');
        $this->assertDatabaseCount('lupon_cases', 0);

        $this->get('/lupon/complaints?tab=Closed')
            ->assertInertia(fn ($page) => $page
                ->has('complaints.data', 2)
                ->where('complaints.data.0.reference', $rejected->reference)
                ->where('complaints.data.1.reference', $accepted->reference));
    }

    public function test_legacy_statuses_map_to_the_same_stage_on_the_staff_and_resident_sides(): void
    {
        // Ages keep both queues deterministic: they order by created_at only.
        $pending = $this->historical('LUP-2026-74001', 'Pending Payment', 40);
        $verification = $this->historical('LUP-2026-74002', 'Payment Verification', 35);
        $accepted = $this->historical('LUP-2026-74003', 'Accepted', 30);

        $this->actingAs($this->member)->get('/lupon/complaints')
            ->assertInertia(fn ($page) => $page->has('complaints.data', 0));
        $this->get('/lupon/complaints?tab=For+Payment')
            ->assertInertia(fn ($page) => $page
                ->has('complaints.data', 2)
                ->where('complaints.data.0.reference', $verification->reference)
                ->where('complaints.data.1.reference', $pending->reference));
        $this->get('/lupon/complaints?tab=Closed')
            ->assertInertia(fn ($page) => $page
                ->has('complaints.data', 1)
                ->where('complaints.data.0.reference', $accepted->reference));

        $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/lupon')
            ->assertInertia(fn ($page) => $page
                ->has('complaints.data', 3)
                ->where('complaints.data.0.trackingStatus', 'Accepted')
                ->where('complaints.data.1.trackingStatus', 'For Payment')
                ->where('complaints.data.2.trackingStatus', 'For Payment')
                ->where('complaints.data.0.caseProgress', null));
    }
}
