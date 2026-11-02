<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\LuponCase;
use App\Models\LuponCaseParty;
use App\Models\LuponComplaint;
use App\Models\LuponHearing;
use App\Models\Purok;
use App\Models\Resident;
use App\Models\ResidentQrCredential;
use App\Models\User;
use App\Support\LuponCaseWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class LuponCaseManagementTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Purok $purok;

    private Resident $resident;

    private User $luponStaff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $this->purok = $this->createPurok($this->barangay, 'Purok 1');
        $this->resident = $this->createResident($this->barangay, $this->purok);
        $this->luponStaff = $this->createAssignedStaff([
            'barangay' => $this->barangay,
            'purok' => $this->purok,
        ], 'lupon', 'Lupon Secretary');
    }

    public function test_lupon_lookup_requires_same_barangay_and_does_not_preload_masterlist(): void
    {
        $this->actingAs($this->luponStaff)->get('/lupon/cases/create')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Lupon/Cases/Create')->missing('residents')->has('officials'));
        $this->get("/lupon/residents/{$this->resident->id}/lookup")->assertOk()->assertJsonPath('resident.id', $this->resident->id);
        $foreign = $this->createBarangay('Foreign', 'FOREIGN');
        $other = $this->createResident($foreign, $this->createPurok($foreign, 'Other'), ['mobile' => '09179999998']);
        $this->get("/lupon/residents/{$other->id}/lookup")->assertNotFound();
    }

    private function createComplaint(
        Barangay $barangay,
        Resident $resident,
        array $overrides = [],
    ): LuponComplaint {
        return LuponComplaint::create([
            'barangay_id' => $barangay->id,
            'resident_id' => $resident->id,
            'reference' => 'LUP-2026-99999',
            'respondent_name' => 'Maria Reyes',
            'respondent_resident_status' => 'Yes',
            'respondent_address' => 'Purok 2',
            'respondent_contact' => '09170000002',
            'complaint_type' => 'Debt / Money Dispute',
            'incident_date' => now()->subDays(3)->toDateString(),
            'incident_time' => '14:00',
            'incident_location' => 'Barangay One',
            'narrative' => 'An unsettled personal debt between the parties.',
            'requested_action' => 'Request mediation.',
            'status' => 'Submitted',
            'submitted_at' => now(),
            ...$overrides,
        ]);
    }

    public function test_accepted_complaint_can_be_manually_promoted_to_case(): void
    {
        $complaint = $this->createComplaint($this->barangay, $this->resident, [
            'reference' => 'LUP-2026-00001',
            'status' => 'Accepted',
        ]);

        $response = $this->actingAs($this->luponStaff)
            ->post("/lupon/complaints/{$complaint->id}/promote");

        $response->assertRedirect();

        $this->assertDatabaseHas('lupon_cases', [
            'barangay_id' => $this->barangay->id,
            'complaint_id' => $complaint->id,
            'status' => 'filed',
            'phase' => 'Kapitan',
        ]);

        $case = LuponCase::first();
        $this->assertNotNull($case);

        // Check primary complainant was created
        $this->assertDatabaseHas('lupon_case_parties', [
            'case_id' => $case->id,
            'party_type' => 'complainant',
            'resident_id' => $this->resident->id,
            'is_primary' => true,
        ]);

        // Check primary respondent was created
        $this->assertDatabaseHas('lupon_case_parties', [
            'case_id' => $case->id,
            'party_type' => 'respondent',
            'name' => $complaint->respondent_name,
            'is_primary' => true,
        ]);
    }

    public function test_cannot_promote_unaccepted_complaint(): void
    {
        $complaint = $this->createComplaint($this->barangay, $this->resident, [
            'reference' => 'LUP-2026-00001',
            'status' => 'Submitted',
        ]);

        $response = $this->actingAs($this->luponStaff)
            ->post("/lupon/complaints/{$complaint->id}/promote");

        $response->assertSessionHasErrors('complaint');
        $this->assertDatabaseCount('lupon_cases', 0);
    }

    public function test_cannot_promote_complaint_twice(): void
    {
        $complaint = $this->createComplaint($this->barangay, $this->resident, [
            'reference' => 'LUP-2026-00001',
            'status' => 'Accepted',
        ]);

        // First promotion
        $this->actingAs($this->luponStaff)
            ->post("/lupon/complaints/{$complaint->id}/promote");

        $this->assertDatabaseCount('lupon_cases', 1);

        // Second promotion should be rejected
        $response = $this->actingAs($this->luponStaff)
            ->post("/lupon/complaints/{$complaint->id}/promote");

        $response->assertSessionHasErrors('complaint');
        $this->assertDatabaseCount('lupon_cases', 1);
    }

    public function test_cannot_promote_other_barangay_complaint(): void
    {
        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $otherPurok = $this->createPurok($otherBarangay, 'Purok 2');
        $otherResident = $this->createResident($otherBarangay, $otherPurok, [
            'mobile' => '09170000099',
        ]);
        $complaint = $this->createComplaint($otherBarangay, $otherResident, [
            'status' => 'Accepted',
        ]);

        $response = $this->actingAs($this->luponStaff)
            ->post("/lupon/complaints/{$complaint->id}/promote");

        $response->assertForbidden();
        $this->assertDatabaseCount('lupon_cases', 0);
    }

    public function test_can_schedule_hearing_for_case(): void
    {
        $complaint = $this->createComplaint($this->barangay, $this->resident, ['status' => 'Accepted']);
        $this->actingAs($this->luponStaff)->post("/lupon/complaints/{$complaint->id}/promote");
        $case = LuponCase::first();

        $response = $this->actingAs($this->luponStaff)
            ->post("/lupon/cases/{$case->id}/hearings", [
                'hearing_date' => now()->addDays(3)->toDateString(),
                'hearing_time' => '10:00',
                'venue' => 'Barangay Session Hall',
                'phase' => 'Kapitan',
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('lupon_hearings', [
            'case_id' => $case->id,
            'hearing_number' => 1,
            'venue' => 'Barangay Session Hall',
            'status' => 'Scheduled',
        ]);
    }

    public function test_hearings_page_lists_case_details_and_only_this_barangays_records(): void
    {
        $complaint = $this->createComplaint($this->barangay, $this->resident, [
            'reference' => 'LUP-2026-00071',
            'status' => 'Accepted',
        ]);

        $this->actingAs($this->luponStaff)->post("/lupon/complaints/{$complaint->id}/promote");
        $case = LuponCase::where('complaint_id', $complaint->id)->firstOrFail();
        $hearing = LuponHearing::create([
            'case_id' => $case->id,
            'barangay_id' => $this->barangay->id,
            'hearing_number' => 1,
            'phase' => 'Kapitan',
            'hearing_date' => now()->addDays(3)->toDateString(),
            'hearing_time' => '10:00 AM',
            'venue' => 'Barangay Hall',
            'status' => 'Scheduled',
            'officer_id' => $this->luponStaff->id,
        ]);

        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $otherStaff = $this->createStaff($otherBarangay, 'lupon');
        $otherCase = LuponCase::create([
            'barangay_id' => $otherBarangay->id,
            'origin' => 'manual',
            'case_number' => 'KP-2026-OTHER-01',
            'title' => 'Other barangay case',
            'case_type' => 'Noise / Disturbance',
            'phase' => 'Kapitan',
            'status' => 'Under Mediation',
            'created_by' => $otherStaff->id,
            'assigned_to' => $otherStaff->id,
            'filed_date' => now()->toDateString(),
        ]);
        LuponHearing::create([
            'case_id' => $otherCase->id,
            'barangay_id' => $otherBarangay->id,
            'hearing_number' => 1,
            'phase' => 'Kapitan',
            'hearing_date' => now()->addDays(4)->toDateString(),
            'status' => 'Scheduled',
            'officer_id' => $otherStaff->id,
        ]);
        LuponHearing::create([
            'case_id' => $otherCase->id,
            'barangay_id' => $this->barangay->id,
            'hearing_number' => 2,
            'phase' => 'Kapitan',
            'hearing_date' => now()->addDays(5)->toDateString(),
            'status' => 'Scheduled',
            'officer_id' => $this->luponStaff->id,
        ]);

        $this->actingAs($this->luponStaff)
            ->get('/lupon/hearings?phase=Kapitan&status=Scheduled')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Lupon/Hearings/Index')
                ->has('hearings.data', 1)
                ->where('hearings.data.0.id', $hearing->id)
                ->where('hearings.data.0.caseId', $case->id)
                ->where('hearings.data.0.caseNumber', $case->case_number)
                ->where('hearings.data.0.hearingNumber', 1)
                ->where('hearings.data.0.phase', 'Kapitan')
                ->where('hearings.data.0.status', 'Scheduled')
                ->where('filters.phase', 'Kapitan')
                ->where('filters.status', 'Scheduled'));
    }

    public function test_lupon_cases_list_renders_existing_filters_and_pagination_data(): void
    {
        $case = LuponCase::create([
            'barangay_id' => $this->barangay->id,
            'origin' => 'manual',
            'case_number' => 'KP-2026-CASE-INDEX-01',
            'title' => 'Boundary concern',
            'case_type' => 'Property / Boundary Dispute',
            'phase' => 'Kapitan',
            'status' => 'Under Mediation',
            'created_by' => $this->luponStaff->id,
            'assigned_to' => $this->luponStaff->id,
            'filed_date' => now()->toDateString(),
        ]);

        $this->actingAs($this->luponStaff)
            ->get('/lupon/cases?phase=Kapitan&status=Under%20Mediation')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Lupon/Cases/Index')
                ->has('cases.data', 1)
                ->where('cases.data.0.id', $case->id)
                ->where('cases.data.0.caseNumber', $case->case_number)
                ->where('filters.phase', 'Kapitan')
                ->where('filters.status', 'mediation_active')
                ->has('cases.links'));
    }

    public function test_lupon_case_index_preserves_party_names_and_counts_without_loading_party_residents(): void
    {
        $case = LuponCase::create([
            'barangay_id' => $this->barangay->id,
            'origin' => 'manual',
            'case_number' => 'KP-2026-INDEX-PARTIES',
            'title' => 'Party loading check',
            'case_type' => 'Property / Boundary Dispute',
            'phase' => 'Kapitan',
            'status' => 'Under Mediation',
            'created_by' => $this->luponStaff->id,
            'assigned_to' => $this->luponStaff->id,
            'filed_date' => now()->toDateString(),
        ]);

        LuponCaseParty::create([
            'case_id' => $case->id,
            'party_type' => 'complainant',
            'resident_id' => $this->resident->id,
            'name' => 'Resident complainant snapshot',
            'is_resident' => true,
            'is_primary' => true,
        ]);
        LuponCaseParty::create([
            'case_id' => $case->id,
            'party_type' => 'complainant',
            'name' => 'Non-resident complainant',
            'is_resident' => false,
            'is_primary' => false,
        ]);
        LuponCaseParty::create([
            'case_id' => $case->id,
            'party_type' => 'respondent',
            'resident_id' => $this->resident->id,
            'name' => 'Resident respondent snapshot',
            'is_resident' => true,
            'is_primary' => true,
        ]);
        LuponCaseParty::create([
            'case_id' => $case->id,
            'party_type' => 'respondent',
            'name' => 'Non-resident respondent',
            'is_resident' => false,
            'is_primary' => false,
        ]);

        $olderCase = LuponCase::create([
            'barangay_id' => $this->barangay->id,
            'origin' => 'manual',
            'case_number' => 'KP-2026-INDEX-OLDER',
            'title' => 'Older case',
            'case_type' => 'Noise / Disturbance',
            'phase' => 'Kapitan',
            'status' => 'Active',
            'created_by' => $this->luponStaff->id,
            'assigned_to' => $this->luponStaff->id,
            'filed_date' => now()->subDay()->toDateString(),
        ]);
        LuponCaseParty::create([
            'case_id' => $olderCase->id,
            'party_type' => 'complainant',
            'name' => 'Older non-resident complainant',
            'is_resident' => false,
            'is_primary' => true,
        ]);
        LuponCaseParty::create([
            'case_id' => $olderCase->id,
            'party_type' => 'respondent',
            'name' => 'Older non-resident respondent',
            'is_resident' => false,
            'is_primary' => true,
        ]);

        $capture = false;
        $residentSelects = [];
        DB::listen(function ($query) use (&$capture, &$residentSelects): void {
            $sql = strtolower($query->sql);
            if ($capture && str_starts_with(ltrim($sql), 'select') && str_contains($sql, 'from "residents"')) {
                $residentSelects[] = $query->sql;
            }
        });

        $capture = true;
        $response = $this->actingAs($this->luponStaff)->get('/lupon/cases')->assertOk();
        $capture = false;

        $response->assertInertia(fn ($page) => $page
            ->component('Lupon/Cases/Index')
            ->has('cases.data', 2)
            ->where('cases.data.0.caseNumber', 'KP-2026-INDEX-PARTIES')
            ->where('cases.data.0.complainantName', 'Resident complainant snapshot')
            ->where('cases.data.0.respondentName', 'Resident respondent snapshot')
            ->where('cases.data.0.complainantsCount', 2)
            ->where('cases.data.0.respondentsCount', 2)
            ->where('cases.data.1.caseNumber', 'KP-2026-INDEX-OLDER')
            ->where('cases.data.1.complainantName', 'Older non-resident complainant')
            ->has('cases.links'));

        $this->assertCount(0, $residentSelects, 'The index uses party snapshots and does not need resident models.');
    }

    public function test_can_record_settlement_for_case(): void
    {
        $complaint = $this->createComplaint($this->barangay, $this->resident, ['status' => 'Accepted']);
        $this->actingAs($this->luponStaff)->post("/lupon/complaints/{$complaint->id}/promote");
        $case = LuponCase::first();

        LuponCaseWorkflow::transition($case, 'mediation_active', $this->luponStaff->id, ['event_date' => now()->toDateString()]);
        $response = $this->actingAs($this->luponStaff)
            ->post("/lupon/cases/{$case->id}/settlements", [
                'settlement_type' => 'Amicable Settlement',
                'settlement_date' => now()->toDateString(),
                'terms' => 'Respondent agreed to pay debt in installments.',
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('lupon_settlements', [
            'case_id' => $case->id,
            'settlement_type' => 'Amicable Settlement',
            'status' => 'Active',
        ]);

        $this->assertEquals('settled', $case->fresh()->status);
        $this->assertEquals('Closed', $case->fresh()->phase);
    }

    public function test_resident_complainant_can_view_own_case_details(): void
    {
        $complaint = $this->createComplaint($this->barangay, $this->resident, ['status' => 'Accepted']);
        $this->actingAs($this->luponStaff)->post("/lupon/complaints/{$complaint->id}/promote");
        $case = LuponCase::first();

        $response = $this->withSession(['resident_id' => $this->resident->id])
            ->get("/resident/lupon/cases/{$case->id}");

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Resident/LuponCaseShow')
                ->where('caseData.caseNumber', $case->case_number));
    }

    public function test_unrelated_resident_cannot_view_case(): void
    {
        $complaint = $this->createComplaint($this->barangay, $this->resident, ['status' => 'Accepted']);
        $this->actingAs($this->luponStaff)->post("/lupon/complaints/{$complaint->id}/promote");
        $case = LuponCase::first();

        $unrelatedResident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170000088',
        ]);

        $response = $this->withSession(['resident_id' => $unrelatedResident->id])
            ->get("/resident/lupon/cases/{$case->id}");

        $response->assertForbidden();
    }

    public function test_additional_complainant_cannot_be_from_another_barangay(): void
    {
        $complaint = $this->createComplaint($this->barangay, $this->resident, ['status' => 'Accepted']);
        $this->actingAs($this->luponStaff)->post("/lupon/complaints/{$complaint->id}/promote");
        $case = LuponCase::first();

        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $otherPurok = $this->createPurok($otherBarangay, 'Purok 2');
        $otherResident = $this->createResident($otherBarangay, $otherPurok, [
            'mobile' => '09170000077',
        ]);

        $response = $this->actingAs($this->luponStaff)
            ->post("/lupon/cases/{$case->id}/complainants", [
                'name' => 'Cross Barangay Complainant',
                'resident_id' => $otherResident->id,
            ]);

        $response->assertSessionHasErrors('resident_id');

        $this->assertDatabaseMissing('lupon_case_parties', [
            'case_id' => $case->id,
            'resident_id' => $otherResident->id,
        ]);
    }

    public function test_additional_complainant_from_same_barangay_succeeds(): void
    {
        $complaint = $this->createComplaint($this->barangay, $this->resident, ['status' => 'Accepted']);
        $this->actingAs($this->luponStaff)->post("/lupon/complaints/{$complaint->id}/promote");
        $case = LuponCase::first();

        $secondResident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170000066',
        ]);

        $response = $this->actingAs($this->luponStaff)
            ->post("/lupon/cases/{$case->id}/complainants", [
                'name' => 'Co-Complainant Resident',
                'resident_id' => $secondResident->id,
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('lupon_case_parties', [
            'case_id' => $case->id,
            'resident_id' => $secondResident->id,
            'name' => $secondResident->full_name,
            'is_resident' => true,
        ]);
    }

    public function test_lupon_staff_can_view_create_case_form(): void
    {
        $response = $this->actingAs($this->luponStaff)->get('/lupon/cases/create');

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Lupon/Cases/Create')
                ->has('caseTypes')
                ->missing('residents')->has('officials'));
    }

    public function test_lupon_staff_can_create_manual_walkin_case(): void
    {
        $respondentResident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170000055',
        ]);

        $response = $this->actingAs($this->luponStaff)->post('/lupon/cases', [
            'case_type' => 'Property / Boundary Dispute',
            'incident_date' => now()->subDay()->toDateString(),
            'incident_location' => 'Zone 4, Purok 1',
            'narrative' => 'Dispute over land fence alignment.',
            'requested_action' => 'Survey and mediation.',
            'complainant_name' => 'Walkin Complainant',
            'complainant_is_resident' => true,
            'complainant_resident_id' => $this->resident->id,
            'complainant_address' => 'Purok 1',
            'complainant_contact' => '09171112233',
            'respondent_name' => 'Walkin Respondent',
            'respondent_is_resident' => true,
            'respondent_resident_id' => $respondentResident->id,
            'respondent_address' => 'Purok 1',
            'respondent_contact' => '09173334455',
        ]);

        $response->assertRedirect();

        $case = LuponCase::where('origin', 'manual')->first();
        $this->assertNotNull($case);
        $this->assertNull($case->complaint_id);
        $this->assertEquals('Property / Boundary Dispute', $case->case_type);
        $this->assertEquals('filed', $case->status);

        // Check complainant party
        $this->assertDatabaseHas('lupon_case_parties', [
            'case_id' => $case->id,
            'party_type' => 'complainant',
            'resident_id' => $this->resident->id,
            'name' => $this->resident->full_name,
            'is_primary' => true,
        ]);

        // Check respondent party with verified association status
        $this->assertDatabaseHas('lupon_case_parties', [
            'case_id' => $case->id,
            'party_type' => 'respondent',
            'resident_id' => $respondentResident->id,
            'name' => $respondentResident->full_name,
            'association_status' => 'verified',
            'is_primary' => true,
        ]);
    }

    public function test_manual_case_complainant_cannot_use_resident_from_other_barangay(): void
    {
        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $otherPurok = $this->createPurok($otherBarangay, 'Purok 2');
        $otherResident = $this->createResident($otherBarangay, $otherPurok, [
            'mobile' => '09170000077',
        ]);

        $response = $this->actingAs($this->luponStaff)->post('/lupon/cases', [
            'case_type' => 'Debt / Money Dispute',
            'incident_date' => now()->toDateString(),
            'incident_location' => 'Barangay Center',
            'narrative' => 'Testing cross barangay validation.',
            'requested_action' => 'Mediation',
            'complainant_name' => 'Resident BRGY 2',
            'complainant_is_resident' => true,
            'complainant_resident_id' => $otherResident->id,
            'respondent_name' => 'Local BRGY 1',
            'respondent_is_resident' => false,
        ]);

        $response->assertSessionHasErrors('complainant_resident_id');
        $this->assertEquals(0, LuponCase::where('origin', 'manual')->count());
    }

    public function test_staff_can_link_verify_and_unlink_respondent_association(): void
    {
        $complaint = $this->createComplaint($this->barangay, $this->resident, ['status' => 'Accepted']);
        $this->actingAs($this->luponStaff)->post("/lupon/complaints/{$complaint->id}/promote");
        $case = LuponCase::first();
        $respondentParty = $case->respondents()->first();

        $secondResident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170000033',
        ]);

        // Link & verify
        $linkResponse = $this->actingAs($this->luponStaff)
            ->post("/lupon/parties/{$respondentParty->id}/associate-respondent", [
                'action' => 'link_verify',
                'resident_id' => $secondResident->id,
                'verification_notes' => 'Matched physically at barangay hall.',
            ]);

        $linkResponse->assertRedirect();

        $respondentParty->refresh();
        $this->assertEquals('verified', $respondentParty->association_status);
        $this->assertEquals($secondResident->id, $respondentParty->resident_id);
        $this->assertEquals($this->luponStaff->id, $respondentParty->verified_by);
        $this->assertNotNull($respondentParty->verified_at);

        // Unlink
        $unlinkResponse = $this->actingAs($this->luponStaff)
            ->post("/lupon/parties/{$respondentParty->id}/associate-respondent", [
                'action' => 'unlink',
                'verification_notes' => 'Mistaken identity.',
            ]);

        $unlinkResponse->assertRedirect();

        $respondentParty->refresh();
        $this->assertEquals('unlinked', $respondentParty->association_status);
        $this->assertNull($respondentParty->resident_id);
    }

    public function test_staff_cannot_link_resident_from_another_barangay_to_respondent(): void
    {
        $complaint = $this->createComplaint($this->barangay, $this->resident, ['status' => 'Accepted']);
        $this->actingAs($this->luponStaff)->post("/lupon/complaints/{$complaint->id}/promote");
        $case = LuponCase::first();
        $respondentParty = $case->respondents()->first();

        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $otherPurok = $this->createPurok($otherBarangay, 'Purok 2');
        $otherResident = $this->createResident($otherBarangay, $otherPurok, [
            'mobile' => '09170000077',
        ]);

        $response = $this->actingAs($this->luponStaff)
            ->post("/lupon/parties/{$respondentParty->id}/associate-respondent", [
                'action' => 'link_verify',
                'resident_id' => $otherResident->id,
            ]);

        $response->assertSessionHasErrors('resident_id');
        $this->assertEquals('unlinked', $respondentParty->fresh()->association_status);
    }

    public function test_verified_respondent_sees_case_in_complaints_against_me_and_can_view_show(): void
    {
        $complaint = $this->createComplaint($this->barangay, $this->resident, [
            'status' => 'Accepted',
            'narrative' => 'Top secret complainant narrative.',
            'requested_action' => 'Demand payment of 100,000 PHP.',
        ]);
        $this->actingAs($this->luponStaff)->post("/lupon/complaints/{$complaint->id}/promote");
        $case = LuponCase::first();
        $respondentParty = $case->respondents()->first();

        $respondentResident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170000044',
        ]);

        // Prior to verification, resident index has no complaints against them
        $respBefore = $this->withSession(['resident_id' => $respondentResident->id])
            ->get('/resident/lupon');
        $respBefore->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Resident/Lupon')
                ->where('complaintsAgainstMe.data', []));

        // Unverified respondent cannot view case detail
        $unverifiedShow = $this->withSession(['resident_id' => $respondentResident->id])
            ->get("/resident/lupon/cases/{$case->id}");
        $unverifiedShow->assertForbidden();

        // Staff links and verifies the respondent
        $this->actingAs($this->luponStaff)
            ->post("/lupon/parties/{$respondentParty->id}/associate-respondent", [
                'action' => 'link_verify',
                'resident_id' => $respondentResident->id,
            ]);

        // Resident index now lists the verified case
        $respAfter = $this->withSession(['resident_id' => $respondentResident->id])
            ->get('/resident/lupon');
        $respAfter->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Resident/Lupon')
                ->has('complaintsAgainstMe.data', 1)
                ->where('complaintsAgainstMe.data.0.caseNumber', $case->case_number));

        // Verified respondent can view case detail with narrative masked & requested action hidden
        $verifiedShow = $this->withSession(['resident_id' => $respondentResident->id])
            ->get("/resident/lupon/cases/{$case->id}");
        $verifiedShow->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Resident/LuponCaseShow')
                ->where('viewerRole', 'respondent')
                ->where('caseData.caseNumber', $case->case_number)
                ->where('caseData.requestedAction', null)
                ->where('caseData.narrative', 'Nature of Dispute: '.$case->case_type));
    }

    private function makeCredential(Resident $resident, array $attributes = []): ResidentQrCredential
    {
        return ResidentQrCredential::create([
            'resident_id' => $resident->id,
            'token' => 'KBQR-'.Str::random(40),
            'is_active' => true,
            ...$attributes,
        ]);
    }

    public function test_lupon_staff_can_lookup_resident_by_qr_code_and_resident_number(): void
    {
        $credential = $this->makeCredential($this->resident);

        // 1. By raw token
        $respToken = $this->actingAs($this->luponStaff)
            ->getJson("/lupon/cases/qr-lookup?token={$credential->token}");

        $respToken->assertOk()
            ->assertJsonPath('resident.id', $this->resident->id)
            ->assertJsonPath('resident.name', $this->resident->full_name)
            ->assertJsonPath('resident.resident_number', $this->resident->resident_number);

        // 2. By full verification URL
        $url = "https://kabarangay.local/verify/resident/{$credential->token}";
        $respUrl = $this->actingAs($this->luponStaff)
            ->getJson('/lupon/cases/qr-lookup?token='.urlencode($url));

        $respUrl->assertOk()
            ->assertJsonPath('resident.id', $this->resident->id);

        // 3. By official resident number
        $respNumber = $this->actingAs($this->luponStaff)
            ->getJson("/lupon/cases/qr-lookup?token={$this->resident->resident_number}");

        $respNumber->assertOk()
            ->assertJsonPath('resident.id', $this->resident->id);
    }

    public function test_qr_lookup_fails_for_unknown_cross_barangay_and_inactive_tokens(): void
    {
        // Missing token -> 400
        $this->actingAs($this->luponStaff)
            ->getJson('/lupon/cases/qr-lookup')
            ->assertStatus(400);

        // Unknown token -> 404
        $this->actingAs($this->luponStaff)
            ->getJson('/lupon/cases/qr-lookup?token=KBQR-UNKNOWN')
            ->assertStatus(404);

        // Cross barangay token -> 404
        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $otherPurok = $this->createPurok($otherBarangay, 'Purok 2');
        $otherResident = $this->createResident($otherBarangay, $otherPurok);
        $otherCredential = $this->makeCredential($otherResident);

        $this->actingAs($this->luponStaff)
            ->getJson("/lupon/cases/qr-lookup?token={$otherCredential->token}")
            ->assertStatus(404);

        // Inactive / revoked token -> 404
        $revokedCred = $this->makeCredential($this->resident, [
            'token' => 'KBQR-REVOKED-123',
            'is_active' => false,
            'revoked_at' => now(),
        ]);

        $this->actingAs($this->luponStaff)
            ->getJson("/lupon/cases/qr-lookup?token={$revokedCred->token}")
            ->assertStatus(404);
    }

    public function test_qr_lookup_is_forbidden_to_non_lupon_users(): void
    {
        $credential = $this->makeCredential($this->resident);

        // Unauthenticated -> 401 or 302
        $this->getJson("/lupon/cases/qr-lookup?token={$credential->token}")
            ->assertStatus(401);

        // Authenticated as resident -> 403
        $residentUser = User::factory()->create([
            'barangay_id' => $this->barangay->id,
        ]);

        $this->actingAs($residentUser)
            ->getJson("/lupon/cases/qr-lookup?token={$credential->token}")
            ->assertStatus(403);
    }

    public function test_manual_add_case_with_non_resident_parties(): void
    {
        $response = $this->actingAs($this->luponStaff)->post('/lupon/cases', [
            'case_type' => 'Damage to Property',
            'incident_date' => now()->toDateString(),
            'incident_location' => 'Main Road',
            'narrative' => 'Vehicle fender collision.',
            'requested_action' => 'Repair settlement.',
            'complainant_name' => 'Transient Complainant',
            'complainant_is_resident' => false,
            'complainant_resident_id' => null,
            'complainant_address' => 'Neighboring City',
            'complainant_contact' => '09170001122',
            'respondent_name' => 'Transient Respondent',
            'respondent_is_resident' => false,
            'respondent_resident_id' => null,
            'respondent_address' => 'Neighboring City',
            'respondent_contact' => '09170003344',
        ]);

        $response->assertRedirect();

        $case = LuponCase::where('origin', 'manual')->latest('id')->first();
        $this->assertNotNull($case);

        $complainant = $case->complainants()->first();
        $this->assertFalse((bool) $complainant->is_resident);
        $this->assertNull($complainant->resident_id);

        $respondent = $case->respondents()->first();
        $this->assertFalse((bool) $respondent->is_resident);
        $this->assertNull($respondent->resident_id);
        $this->assertEquals('unlinked', $respondent->association_status);
    }

    public function test_manual_add_case_rejects_duplicate_parties_with_same_resident_id(): void
    {
        $response = $this->actingAs($this->luponStaff)->post('/lupon/cases', [
            'case_type' => 'Noise / Disturbance',
            'incident_date' => now()->toDateString(),
            'incident_location' => 'Purok 1',
            'narrative' => 'Testing duplicate party check.',
            'requested_action' => 'Mediation',
            'complainant_name' => $this->resident->full_name,
            'complainant_is_resident' => true,
            'complainant_resident_id' => $this->resident->id,
            'respondent_name' => $this->resident->full_name,
            'respondent_is_resident' => true,
            'respondent_resident_id' => $this->resident->id,
        ]);

        $response->assertSessionHasErrors('respondent_resident_id');
    }

    public function test_manual_add_case_requires_custom_nature_when_other_is_selected(): void
    {
        $response = $this->actingAs($this->luponStaff)->post('/lupon/cases', [
            'case_type' => 'Other',
            'custom_case_type' => '',
            'incident_date' => now()->toDateString(),
            'incident_location' => 'Purok 1',
            'narrative' => 'Testing custom nature required.',
            'requested_action' => 'Mediation',
            'complainant_name' => 'Complainant A',
            'complainant_is_resident' => false,
            'respondent_name' => 'Respondent B',
            'respondent_is_resident' => false,
        ]);

        $response->assertSessionHasErrors('custom_case_type');
    }

    public function test_manual_add_case_persists_custom_nature_and_displays_properly(): void
    {
        $respondentResident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170000099',
        ]);

        $response = $this->actingAs($this->luponStaff)->post('/lupon/cases', [
            'case_type' => 'Other',
            'custom_case_type' => 'Water Pipe Encroachment',
            'incident_date' => now()->toDateString(),
            'incident_location' => 'Purok 1',
            'narrative' => 'Water pipe crossing boundary illegally.',
            'requested_action' => 'Remove water pipe.',
            'complainant_name' => 'Juan Dela Cruz',
            'complainant_is_resident' => true,
            'complainant_resident_id' => $this->resident->id,
            'respondent_name' => 'Pedro Santos',
            'respondent_is_resident' => true,
            'respondent_resident_id' => $respondentResident->id,
        ]);

        $response->assertRedirect();

        $case = LuponCase::where('origin', 'manual')->latest('id')->first();
        $this->assertNotNull($case);
        $this->assertEquals('Other: Water Pipe Encroachment', $case->case_type);
        $this->assertEquals('Other: Water Pipe Encroachment: '.$this->resident->full_name.' vs. '.$respondentResident->full_name, $case->title);

        // Check staff case detail show
        $staffShow = $this->actingAs($this->luponStaff)->get("/lupon/cases/{$case->id}");
        $staffShow->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Lupon/Cases/Show')
                ->where('caseData.caseType', 'Other: Water Pipe Encroachment'));

        // Check resident respondent case detail show
        $residentShow = $this->withSession(['resident_id' => $respondentResident->id])
            ->get("/resident/lupon/cases/{$case->id}");
        $residentShow->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Resident/LuponCaseShow')
                ->where('caseData.caseType', 'Other: Water Pipe Encroachment')
                ->where('caseData.narrative', 'Nature of Dispute: Other: Water Pipe Encroachment'));
    }
}
