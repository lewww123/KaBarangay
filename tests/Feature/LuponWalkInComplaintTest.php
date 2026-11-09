<?php

namespace Tests\Feature;

use App\Models\LuponComplaint;
use App\Models\LuponEvidence;
use App\Models\Resident;
use App\Support\LuponCaseWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

/**
 * Batch 2A: walk-in complaint intake records a complaint submission in the
 * shared queue and never creates an official case.
 */
class LuponWalkInComplaintTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private $barangay;

    private $purok;

    private $respondent;

    private Resident $resident;

    private $staff;

    private $secretary;

    private $clerk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->barangay = $this->createBarangay('Walk-in Barangay', 'WALK');
        $this->purok = $this->createPurok($this->barangay, 'Purok One');
        $this->resident = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Cardinal',
            'last_name' => 'Complainant',
            'mobile' => '09170001111',
        ]);
        $this->respondent = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Baron',
            'last_name' => 'Respondent',
            'mobile' => '09170002222',
        ]);
        $this->secretary = $this->createAssignedStaff(
            ['barangay' => $this->barangay, 'purok' => $this->purok],
            'lupon',
            'Lupon Secretary',
        );
        $this->staff = $this->createAssignedStaff(
            ['barangay' => $this->barangay, 'purok' => $this->purok],
            'lupon',
            'Lupon Member',
        );
        $this->clerk = $this->createStaff($this->barangay, 'clerk');
    }

    private function intake(array $overrides = []): array
    {
        return array_merge([
            'submission_token' => (string) Str::uuid(),
            'complaint_type' => 'Debt / Money Dispute',
            'incident_date' => '2026-10-01',
            'incident_time' => '10:30',
            'incident_location' => 'Purok One basketball court',
            'narrative' => 'Recorded at the desk from the walk-in complainant.',
            'requested_action' => 'Mediation.',
            'complainant_name' => 'Maria Santos',
            'complainant_is_resident' => false,
            'complainant_resident_id' => null,
            'complainant_address' => 'Purok One, Walk-in Barangay',
            'complainant_contact' => '09170000000',
            'respondent_name' => 'Jose Santos',
            'respondent_is_resident' => false,
            'respondent_resident_id' => null,
            'respondent_address' => 'Sitio Malapit, neighboring barangay',
            'respondent_contact' => '09180000000',
        ], $overrides);
    }

    private function record(array $payload, $actor = null): LuponComplaint
    {
        $this->actingAs($actor ?? $this->staff)
            ->post('/lupon/complaints/walk-in', $payload)
            ->assertSessionHasNoErrors();

        return LuponComplaint::latest('id')->firstOrFail();
    }

    private function assertNoOfficialCase(): void
    {
        $this->assertDatabaseCount('lupon_cases', 0);
        $this->assertDatabaseCount('lupon_case_parties', 0);
        $this->assertDatabaseCount('lupon_hearings', 0);
        $this->assertDatabaseCount('lupon_documents', 0);
        $this->assertDatabaseCount('lupon_settlements', 0);
    }

    public function test_walk_in_intake_is_available_to_any_active_lupon_staff(): void
    {
        foreach (['secretary' => $this->secretary, 'member' => $this->staff] as $actor) {
            $this->actingAs($actor)->get('/lupon/complaints/walk-in')
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component('Lupon/Complaints/WalkIn')
                    ->has('complaintTypes'));
        }
    }

    public function test_walk_in_complaint_is_a_submission_and_never_an_official_case(): void
    {
        $complaint = $this->record($this->intake());

        $this->assertSame($this->barangay->id, $complaint->barangay_id);
        $this->assertSame('Under Review', $complaint->status);
        $this->assertMatchesRegularExpression('/^LUP-\d{4}-\d{5}$/', $complaint->reference);
        $this->assertNull($complaint->assessed_fee);
        $this->assertNull($complaint->payment_requested_at);
        $this->assertNull($complaint->payment_verification);
        $this->assertNull($complaint->case);

        $this->assertNoOfficialCase();
        $this->assertDatabaseMissing('lupon_cases', ['complaint_id' => $complaint->id]);
    }

    public function test_lupon_walk_in_intake_rejects_staff_outside_the_lupon_department(): void
    {
        $payload = $this->intake();

        $this->actingAs($this->clerk)->get('/lupon/complaints/walk-in')->assertForbidden();
        $this->actingAs($this->clerk)->post('/lupon/complaints/walk-in', $payload)->assertForbidden();

        $this->assertDatabaseCount('lupon_complaints', 0);
        $this->assertNoOfficialCase();
    }

    public function test_walk_in_and_online_submissions_share_one_queue_with_an_identifiable_source(): void
    {
        $this->withSession(['resident_id' => $this->resident->id])->post('/resident/lupon/complaints', [
            'submission_token' => (string) Str::uuid(),
            'respondentName' => 'Online Respondent',
            'respondentResidentStatus' => 'Unknown',
            'complaintType' => 'Noise / Disturbance',
            'incidentDate' => '2026-10-02',
            'incidentLocation' => 'Purok One',
            'narrative' => 'Loud karaoke past midnight.',
            'requestedAction' => 'Summons.',
        ])->assertSessionHasNoErrors();

        $online = LuponComplaint::sole();
        $online->created_at = now()->subHour();
        $online->save();

        $walkIn = $this->record($this->intake());

        $this->actingAs($this->staff)->get('/lupon/complaints')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Lupon/Complaints')
                ->has('complaints.data', 2)
                ->where('complaints.data.0.id', $walkIn->id)
                ->where('complaints.data.0.source', 'walk-in')
                ->where('complaints.data.0.resident.name', 'Maria Santos')
                ->where('complaints.data.0.status', 'Under Review')
                ->where('complaints.data.1.id', $online->id)
                ->where('complaints.data.1.source', 'online')
                ->where('complaints.data.1.resident.name', $this->resident->full_name));

        $this->assertNoOfficialCase();
    }

    public function test_registered_complainants_and_respondents_are_taken_from_the_masterlist(): void
    {
        $complaint = $this->record($this->intake([
            'complainant_name' => 'Spoofed Name',
            'complainant_is_resident' => true,
            'complainant_resident_id' => $this->resident->id,
            'complainant_address' => 'Spoofed Address',
            'complainant_contact' => '09000000000',
            'respondent_name' => 'Spoofed Respondent',
            'respondent_is_resident' => true,
            'respondent_resident_id' => $this->respondent->id,
            'respondent_address' => 'Spoofed Respondent Address',
            'respondent_contact' => '09000000001',
        ]));

        $this->assertSame($this->resident->id, $complaint->resident_id);
        $this->assertSame($this->resident->full_name, $complaint->complainant_snapshot['name']);
        $this->assertSame($this->resident->mobile, $complaint->complainant_snapshot['contact']);
        $this->assertStringContainsString($this->purok->name, $complaint->complainant_snapshot['address']);
        $this->assertStringNotContainsString('Spoofed', json_encode($complaint->complainant_snapshot, JSON_THROW_ON_ERROR));

        $this->assertSame('Baron Respondent', $complaint->respondent_name);
        $this->assertSame('Yes', $complaint->respondent_resident_status);
        $this->assertStringNotContainsString('Spoofed', (string) $complaint->respondent_address);
        $this->assertSame($this->respondent->mobile, $complaint->respondent_contact);

        $this->assertNoOfficialCase();
    }

    public function test_unregistered_walk_in_parties_keep_their_intake_snapshot(): void
    {
        $complaint = $this->record($this->intake());

        $this->assertNull($complaint->resident_id);
        $this->assertSame('Maria Santos', $complaint->complainant_snapshot['name']);
        $this->assertSame('Purok One, Walk-in Barangay', $complaint->complainant_snapshot['address']);
        $this->assertSame('09170000000', $complaint->complainant_snapshot['contact']);
        $this->assertSame('Jose Santos', $complaint->respondent_name);
        $this->assertSame('No', $complaint->respondent_resident_status);
        $this->assertSame('Sitio Malapit, neighboring barangay', $complaint->respondent_address);
        $this->assertSame('09180000000', $complaint->respondent_contact);
        $this->assertSame('Recorded at the desk from the walk-in complainant.', $complaint->narrative);
        $this->assertSame('Mediation.', $complaint->requested_action);
        $this->assertSame('Purok One basketball court', $complaint->incident_location);
        $this->assertSame('10:30', $complaint->incident_time);

        $this->assertNoOfficialCase();
    }

    public function test_walk_in_intake_enforces_the_existing_party_validation_rules(): void
    {
        $foreignBarangay = $this->createBarangay('Foreign Barangay', 'FRGN');
        $foreign = $this->createResident($foreignBarangay, $this->createPurok($foreignBarangay, 'Purok X'), [
            'mobile' => '09170003333',
        ]);
        $this->resident->update(['status' => 'inactive']);

        $this->actingAs($this->staff)
            ->from('/lupon/complaints/walk-in')
            ->post('/lupon/complaints/walk-in', $this->intake([
                'complainant_is_resident' => true,
                'complainant_resident_id' => $foreign->id,
            ]))
            ->assertSessionHasErrors('complainant_resident_id');

        $this->post('/lupon/complaints/walk-in', $this->intake([
            'complainant_is_resident' => true,
            'complainant_resident_id' => $this->resident->id,
        ]))->assertSessionHasErrors('complainant_resident_id');

        $this->post('/lupon/complaints/walk-in', $this->intake([
            'complainant_name' => '',
            'respondent_name' => '',
            'narrative' => '',
            'incident_date' => now(config('lupon.timezone', 'Asia/Manila'))->addDay()->toDateString(),
            'complaint_type' => 'Not A Real Dispute',
        ]))->assertSessionHasErrors(['complainant_name', 'respondent_name', 'narrative', 'incident_date', 'complaint_type']);

        $this->assertDatabaseCount('lupon_complaints', 0);
        $this->assertNoOfficialCase();
    }

    public function test_one_registered_resident_cannot_be_both_parties_of_a_walk_in_complaint(): void
    {
        $this->actingAs($this->staff)
            ->from('/lupon/complaints/walk-in')
            ->post('/lupon/complaints/walk-in', $this->intake([
                'complainant_is_resident' => true,
                'complainant_resident_id' => $this->resident->id,
                'respondent_is_resident' => true,
                'respondent_resident_id' => $this->resident->id,
            ]))
            ->assertSessionHasErrors('respondent_resident_id');

        $this->assertDatabaseCount('lupon_complaints', 0);
    }

    public function test_walk_in_evidence_stays_with_the_complaint_and_bad_files_are_rejected(): void
    {
        $complaint = $this->record($this->intake([
            'evidence' => [
                UploadedFile::fake()->create('receipt.pdf', 12, 'application/pdf'),
                UploadedFile::fake()->create('marker.jpg', 15, 'image/jpeg'),
            ],
        ]));

        $files = $complaint->evidence()->get();
        $this->assertCount(2, $files);
        $this->assertSame($this->barangay->id, $files[0]->barangay_id);
        foreach ($files as $file) {
            $this->assertSame($complaint->id, $file->complaint_id);
            $this->assertNull($file->case_id);
            $this->assertSame($this->staff->id, $file->uploaded_by);
            Storage::disk('local')->assertExists($file->path);
        }

        $this->actingAs($this->staff)
            ->from('/lupon/complaints/walk-in')
            ->post('/lupon/complaints/walk-in', $this->intake([
                'evidence' => [UploadedFile::fake()->create('payload.php', 1, 'application/x-php')],
            ]))
            ->assertSessionHasErrors('evidence.0');

        $this->assertSame(2, LuponEvidence::count());
        $this->assertDatabaseCount('lupon_complaints', 1);
        $this->assertNoOfficialCase();
    }

    public function test_resubmitting_the_same_walk_in_token_does_not_duplicate_the_submission(): void
    {
        $token = (string) Str::uuid();
        $payload = $this->intake([
            'submission_token' => $token,
            'evidence' => [UploadedFile::fake()->create('receipt.pdf', 12, 'application/pdf')],
        ]);

        $first = $this->record($payload);
        $second = $this->record($payload);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('lupon_complaints', 1);
        $this->assertSame(1, $first->evidence()->count());
        $this->assertNoOfficialCase();
    }

    public function test_walk_in_intake_leaves_the_online_submission_and_paid_case_flow_unchanged(): void
    {
        $this->withSession(['resident_id' => $this->resident->id])->post('/resident/lupon/complaints', [
            'submission_token' => (string) Str::uuid(),
            'respondentName' => 'Online Respondent',
            'respondentResidentStatus' => 'Unknown',
            'complaintType' => 'Debt / Money Dispute',
            'incidentDate' => '2026-10-03',
            'incidentLocation' => 'Purok One',
            'narrative' => 'Loan remains unpaid.',
            'requestedAction' => 'Request mediation.',
        ])->assertSessionHasNoErrors();

        $online = LuponComplaint::sole();
        $online->created_at = now()->subHour();
        $online->save();
        $walkIn = $this->record($this->intake());

        $this->actingAs($this->secretary)
            ->post('/lupon/complaints/'.$online->id.'/request-payment', ['assessed_fee' => '125.50'])
            ->assertSessionHasNoErrors();
        $this->post('/lupon/complaints/'.$online->id.'/promote', [
            'or_number' => 'OR-WALKIN-101',
            'payment_date' => LuponCaseWorkflow::today(),
            'payment_verified' => true,
            'verification_note' => 'Personally compared the original receipt against the recorded payment.',
        ])->assertSessionHasNoErrors();

        $case = $online->case()->firstOrFail();
        $this->assertSame('paid', $case->payment_status);
        $this->assertNotEmpty($case->case_number);
        $this->assertSame('Payment Verified', $online->fresh()->status);

        $this->assertSame('Under Review', $walkIn->fresh()->status);
        $this->assertNull($walkIn->fresh()->case);
        $this->assertDatabaseCount('lupon_cases', 1);

        $this->actingAs($this->staff)->get('/lupon/complaints')
            ->assertInertia(fn ($page) => $page
                ->has('complaints.data', 1)
                ->where('complaints.data.0.source', 'walk-in')
                ->where('complaints.data.0.case', null));

        $this->actingAs($this->staff)->get('/lupon/complaints?tab=Closed')
            ->assertInertia(fn ($page) => $page
                ->where('complaints.data.0.source', 'online')
                ->where('complaints.data.0.case.caseNumber', $case->case_number));
    }
}
