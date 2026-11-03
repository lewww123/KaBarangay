<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\LuponCase;
use App\Models\LuponComplaint;
use App\Models\LuponDocument;
use App\Models\LuponEvidence;
use App\Models\OrgChartPosition;
use App\Models\Resident;
use App\Models\User;
use App\Support\LuponCaseWorkflow;
use App\Support\LuponEvidenceStore;
use App\Support\StagedDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class LuponLifecycleIntegrationTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private User $staff;

    private Resident $resident;

    private OrgChartPosition $acting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 11, 2)->setTime(2, 0));
        Storage::fake('local');
        Mail::fake();
        $this->barangay = $this->createBarangay('Test Barangay', 'KP');
        $purok = $this->createPurok($this->barangay);
        $this->resident = $this->createResident($this->barangay, $purok);
        $this->staff = $this->createStaff($this->barangay, 'lupon');
        foreach (['Punong Barangay' => 'Default Captain', 'Barangay Secretary' => 'Secretary Person', 'Barangay Kagawad' => 'Alice & Reyes', 'Kagawad on Health' => 'Bob Santos'] as $title => $name) {
            $position = OrgChartPosition::create(['barangay_id' => $this->barangay->id, 'title' => $title, 'assigned_person' => $name, 'status' => 'published', 'created_by' => $this->staff->id]);
            if ($name === 'Alice & Reyes') {
                $this->acting = $position;
            }
        }
        $this->actingAs($this->staff);
    }

    private function docket(array $overrides = []): LuponCase
    {
        $this->post('/lupon/cases', [
            'case_type' => 'Debt / Money Dispute', 'incident_date' => '2026-10-01', 'incident_location' => 'Purok 1', 'narrative' => 'Unpaid loan.', 'requested_action' => 'Mediation.',
            'complainant_name' => 'Tampered name', 'complainant_is_resident' => true, 'complainant_resident_id' => $this->resident->id, 'complainant_address' => 'Spoofed address',
            'respondent_name' => 'Walk-in Respondent', 'respondent_is_resident' => false,
            'hearing_date' => '2026-11-02', 'hearing_time' => '09:00', 'venue' => 'Barangay Hall', 'acting_captain_id' => $this->acting->id, ...$overrides,
        ])->assertRedirect()->assertSessionHasNoErrors();

        return LuponCase::latest('id')->firstOrFail();
    }

    private function xml(LuponDocument $document): string
    {
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($document->path)) === true);
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        $dom = new \DOMDocument;
        $this->assertTrue($dom->loadXML($xml));
        $this->assertStringNotContainsString('${', $xml);

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1);
    }

    private function move(LuponCase $case, string $target, array $data = []): void
    {
        $this->patch('/lupon/cases/'.$case->id.'/status', ['status' => $target, 'expected_status' => $case->fresh()->status, 'event_date' => '2026-11-02', ...$data])->assertRedirect()->assertSessionHasNoErrors();
    }

    private function conciliate(LuponCase $case): void
    {
        $hearing = $case->hearings()->first();
        $this->patch('/lupon/hearings/'.$hearing->id, ['status' => 'Held', 'outcome' => 'Unresolved'])->assertSessionHasNoErrors();
        $this->move($case, 'pangkat_formed', ['pangkat_members' => ['Chair One', 'Secretary Two', 'Member Three'], 'pangkat_chair_name' => 'Chair One', 'pangkat_secretary_name' => 'Secretary Two', 'notes' => 'Mediation failed; parties constituted the Pangkat.']);
        $this->post('/lupon/cases/'.$case->id.'/hearings', ['hearing_date' => '2026-11-02', 'hearing_time' => '14:00', 'phase' => 'Lupon'])->assertSessionHasNoErrors();
        $second = $case->hearings()->where('phase', 'Lupon')->firstOrFail();
        $this->patch('/lupon/hearings/'.$second->id, ['status' => 'Held', 'outcome' => 'Unresolved'])->assertSessionHasNoErrors();
    }

    public function test_intake_generates_valid_acting_notices_without_starting_mediation_or_fabricating_service(): void
    {
        $case = $this->docket();
        $this->assertSame('filed', $case->status);
        $this->assertNull($case->mediation_started_at);
        $this->assertNull($case->mediation_deadline_at);
        $this->assertNull($case->form_7_issued_at);
        $this->assertSame('2026-11-03', $case->summons_due_at->toDateString());
        $this->assertSame($this->resident->full_name, $case->complainants()->first()->name);
        $this->assertStringNotContainsString('Spoofed', $case->complainants()->first()->address);
        $this->assertCount(2, $case->documents);
        foreach ($case->documents as $doc) {
            $text = $this->xml($doc);
            $this->assertStringContainsString('ALICE & REYES', $text);
            $this->assertStringContainsString('Acting Punong Barangay', $text);
            $this->assertStringNotContainsString('Default Captain', $text);
            $this->assertSame(['Bob Santos'], array_column($doc->signatories['kagawads'], 'name'));
            $this->assertNull($doc->served_at);
        }
        $this->post('/lupon/cases/'.$case->id.'/documents', ['kind' => 'summons', 'hearing_id' => $case->hearings()->first()->id])->assertSessionHasNoErrors();
        $this->assertSame(2, $case->documents()->count());
        Mail::assertNothingSent();
    }

    public function test_full_failed_conciliation_path_tracks_15_plus_15_and_generates_cfa_with_pangkat_officials(): void
    {
        $case = $this->docket();
        $this->conciliate($case);
        $case->refresh();
        $this->assertSame('conciliation_active', $case->status);
        $this->assertSame('2026-11-17', $case->mediation_deadline_at->toDateString());
        $this->assertSame('2026-11-17', $case->conciliation_deadline_at->toDateString());
        $this->post('/lupon/cases/'.$case->id.'/extend-conciliation', ['days' => 15, 'notes' => 'Pangkat approved more time for negotiations.'])->assertSessionHasNoErrors();
        $this->assertSame('2026-12-02', $case->fresh()->conciliation_extended_until->toDateString());
        $this->post('/lupon/cases/'.$case->id.'/extend-conciliation', ['days' => 1, 'notes' => 'Another extension.'])->assertSessionHasErrors('status');
        $this->patch('/lupon/cases/'.$case->id.'/status', ['status' => 'certified_to_file_action'])->assertSessionHasErrors('status');
        $this->move($case, 'certified_to_file_action', ['prerequisites_verified' => true, 'notes' => 'Personal confrontation completed, no agreement; grounds verified.']);
        $doc = $case->documents()->where('kind', 'cfa')->firstOrFail();
        $text = $this->xml($doc);
        $this->assertStringContainsString('Chair One', $text);
        $this->assertStringContainsString('Secretary Two', $text);
        $this->assertStringContainsString('Pangkat Secretary', $text);
        $this->assertStringNotContainsString('Bob Santos', $text);
        $this->assertSame('CFA Issued', LuponCaseWorkflow::tracking($case->fresh()));
        $this->patch('/lupon/cases/'.$case->id.'/status', ['status' => 'mediation_active', 'event_date' => '2026-11-02'])->assertSessionHasErrors('status');
    }

    public function test_settlement_is_immutable_and_valid_sworn_repudiation_can_proceed_to_cfa(): void
    {
        $case = $this->docket();
        $this->move($case, 'mediation_active');
        $payload = ['settlement_type' => 'Amicable Settlement', 'settlement_date' => '2026-11-02', 'terms' => "Pay 500 pesos.\nKeep the original receipt."];
        $this->post('/lupon/cases/'.$case->id.'/settlements', $payload)->assertSessionHasNoErrors();
        $this->assertSame('settled', $case->fresh()->status);
        $this->xml($case->documents()->where('kind', 'amicable_settlement')->firstOrFail());
        $this->post('/lupon/cases/'.$case->id.'/settlements', [...$payload, 'terms' => 'Replace agreement'])->assertSessionHasErrors('terms');
        $this->patch('/lupon/cases/'.$case->id.'/status', ['status' => 'repudiated', 'event_date' => '2026-11-02', 'notes' => 'Objection'])->assertSessionHasErrors('status');
        $this->move($case, 'repudiated', ['repudiation_ground' => 'intimidation', 'sworn_statement_received' => true, 'notes' => 'Sworn repudiation filed and verified.']);
        $this->move($case, 'certified_to_file_action', ['prerequisites_verified' => true, 'notes' => 'Verified sworn repudiation and proper officials.']);
        $text = $this->xml($case->documents()->where('kind', 'cfa')->firstOrFail());
        $this->assertStringContainsString('Secretary Person', $text);
        $this->assertStringContainsString('sworn repudiation', $text);
    }

    public function test_no_case_progression_on_elapsed_deadlines_or_stale_edit_and_no_future_hearing_held(): void
    {
        config(['lupon.hearing_actions_unlocked' => false]);
        $case = $this->docket(['hearing_date' => '2026-11-05']);
        $this->patch('/lupon/hearings/'.$case->hearings()->first()->id, ['status' => 'Held'])->assertSessionHasErrors('status');
        $this->patch('/lupon/cases/'.$case->id.'/status', ['status' => 'certified_to_file_action', 'prerequisites_verified' => true, 'notes' => 'skip'])->assertSessionHasErrors('status');
        $this->patch('/lupon/cases/'.$case->id.'/status', ['status' => 'mediation_active', 'expected_status' => 'settled', 'event_date' => '2026-11-02'])->assertSessionHasErrors('status');
        $this->travel(40)->days();
        LuponCaseWorkflow::timeline($case);
        $this->assertSame('filed', $case->fresh()->status);
    }

    public function test_late_repudiation_is_rejected(): void
    {
        $case = $this->docket();
        $this->move($case, 'mediation_active');
        $this->post('/lupon/cases/'.$case->id.'/settlements', ['settlement_type' => 'Amicable Settlement', 'settlement_date' => '2026-11-02', 'terms' => 'Written agreement.'])->assertSessionHasNoErrors();
        $this->travel(11)->days();
        $this->patch('/lupon/cases/'.$case->id.'/status', ['status' => 'repudiated', 'event_date' => '2026-11-13', 'repudiation_ground' => 'fraud', 'sworn_statement_received' => true, 'notes' => 'Too late'])->assertSessionHasErrors('status');
        $this->assertSame('settled', $case->fresh()->status);
    }

    public function test_rescheduled_notices_are_versioned_and_fee_verification_does_not_fake_service(): void
    {
        $case = $this->docket();
        $hearing = $case->hearings()->first();
        $old = $case->documents()->pluck('id');
        $this->patch('/lupon/hearings/'.$hearing->id, ['status' => 'Rescheduled', 'hearing_date' => '2026-11-04', 'hearing_time' => '10:30', 'venue' => 'Session Hall'])->assertSessionHasNoErrors();
        $this->assertSame(2, $case->documents()->whereIn('id', $old)->where('status', 'superseded')->count());
        $doc = $case->documents()->where('kind', 'summons')->where('status', 'ready')->firstOrFail();
        $this->assertStringContainsString('November 4, 2026', $this->xml($doc));
        $service = ['served_at' => now()->toIso8601String(), 'service_method' => 'personal', 'service_note' => 'Personally received; signed acknowledgment in docket.', 'receipt_confirmed' => true];
        $this->post('/lupon/documents/'.$doc->id.'/serve', $service)->assertSessionHasErrors('document');
        $this->post('/lupon/cases/'.$case->id.'/verify-fee', ['payment_status' => 'waived', 'fee_amount' => 0, 'or_number' => 'WAIVER-1'])->assertSessionHasNoErrors();
        $this->assertNull($case->fresh()->form_7_issued_at);
        Mail::assertNothingSent();
        $this->travel(1)->seconds();
        $service['served_at'] = now()->toIso8601String();
        $this->post('/lupon/documents/'.$doc->id.'/serve', $service)->assertSessionHasNoErrors();
        $this->assertNotNull($doc->fresh()->served_at);
        $this->assertSame('Summoned', LuponCaseWorkflow::tracking($case->fresh()));
    }

    public function test_private_evidence_only_visible_to_owner_staff_or_verified_shared_party(): void
    {
        $case = $this->docket();
        $other = $this->createResident($this->barangay, $this->createPurok($this->barangay), ['mobile' => '09170000088']);
        $party = $case->respondents()->first();
        $party->update(['resident_id' => $other->id, 'association_status' => 'unverified']);
        $this->post('/lupon/cases/'.$case->id.'/evidence', ['evidence' => [UploadedFile::fake()->create('receipt.pdf', 12, 'application/pdf')]])->assertSessionHasNoErrors();
        $file = LuponEvidence::firstOrFail();
        Storage::disk('local')->assertExists($file->path);
        $this->get('/lupon/evidence/'.$file->id)->assertOk();
        $this->withSession(['resident_id' => $other->id])->get('/resident/lupon/evidence/'.$file->id)->assertNotFound();
        $file->update(['visible_to_parties' => true]);
        $this->get('/resident/lupon/evidence/'.$file->id)->assertNotFound();
        $party->update(['association_status' => 'verified']);
        $this->get('/resident/lupon/evidence/'.$file->id)->assertOk();
        $foreign = $this->createBarangay('Foreign', 'FOREIGN');
        $foreignStaff = $this->createStaff($foreign, 'lupon');
        $this->actingAs($foreignStaff)->get('/lupon/evidence/'.$file->id)->assertNotFound();
        $this->get('/lupon/documents/'.$case->documents()->first()->id)->assertForbidden();
    }

    public function test_evidence_rollback_removes_private_files_and_executable_uploads_are_rejected(): void
    {
        $case = $this->docket();
        $this->post('/lupon/cases/'.$case->id.'/evidence', ['evidence' => [UploadedFile::fake()->create('script.php', 1, 'application/x-php')]])->assertSessionHasErrors('evidence.0');
        try {
            DB::transaction(function () use ($case) {
                LuponEvidenceStore::store([UploadedFile::fake()->create('receipt.pdf', 1, 'application/pdf')], null, $case);
                throw new \RuntimeException('Rollback');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('Rollback', $error->getMessage());
        }
        $this->assertSame([], Storage::disk('local')->allFiles('lupon/evidence'));
        $this->assertSame(0, LuponEvidence::count());
    }

    public function test_invalid_template_rolls_back_cfa_transition_and_staging_cleans_after_failure(): void
    {
        $case = $this->docket();
        $this->conciliate($case);
        config(['lupon.templates.cfa' => storage_path('app/missing-template.docx')]);
        $this->patch('/lupon/cases/'.$case->id.'/status', ['status' => 'certified_to_file_action', 'prerequisites_verified' => true, 'notes' => 'Verified grounds'])->assertSessionHasErrors('document');
        $this->assertSame('conciliation_active', $case->fresh()->status);
        $staged = null;
        try {
            StagedDocument::write(storage_path('app/unused.docx'), function ($path) use (&$staged) {
                $staged = $path;
                throw new \RuntimeException('Build failed');
            });
        } catch (\RuntimeException $error) {
            $this->assertFileDoesNotExist($staged);
        }
    }

    public function test_online_complaint_evidence_is_carried_to_docket_and_remains_private(): void
    {
        $this->withSession(['resident_id' => $this->resident->id])->post('/resident/lupon/complaints', [
            'respondentName' => 'Walk-in Respondent', 'respondentResidentStatus' => 'Unknown', 'complaintType' => 'Debt / Money Dispute', 'incidentDate' => '2026-10-01', 'incidentLocation' => 'Purok 1', 'narrative' => 'Loan remains unpaid.', 'requestedAction' => 'Request mediation.',
            'evidence' => [UploadedFile::fake()->create('receipt.pdf', 12, 'application/pdf')],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $complaint = LuponComplaint::firstOrFail();
        $file = $complaint->evidence()->firstOrFail();
        $this->get('/resident/lupon/evidence/'.$file->id)->assertOk();
        $this->get('/resident/lupon')->assertInertia(fn ($page) => $page->where('complaints.data.0.trackingStatus', 'Pending')->has('complaints.data.0.evidence', 1));
        $complaint->update(['status' => 'Accepted', 'submitted_at' => now()->subDays(3)]);
        $this->post('/lupon/complaints/'.$complaint->id.'/promote', ['hearing_date' => '2026-11-03', 'hearing_time' => '10:00'])->assertSessionHasNoErrors();
        $case = $complaint->case()->firstOrFail();
        $this->assertSame($case->id, $file->fresh()->case_id);
        $this->assertSame('2026-10-30', $case->filed_date->toDateString());
        $this->assertSame('2026-11-02', $case->summons_due_at->toDateString());
        $this->get('/resident/lupon/cases/'.$case->id)->assertOk()->assertInertia(fn ($page) => $page->has('caseData.evidence', 1)->has('caseData.documents', 0));
    }

    public function test_changing_acting_captain_without_explicit_hearing_creates_a_new_notice_snapshot(): void
    {
        $case = $this->docket();
        $original = $case->documents()->where('kind', 'summons')->firstOrFail();
        $this->post('/lupon/cases/'.$case->id.'/documents', ['kind' => 'summons', 'acting_captain_id' => null])->assertSessionHasNoErrors();
        $this->assertSame('superseded', $original->fresh()->status);
        $doc = $case->documents()->where('kind', 'summons')->where('status', 'ready')->firstOrFail();
        $this->assertStringContainsString('DEFAULT CAPTAIN', $this->xml($doc));
        $this->assertNull($case->fresh()->acting_captain_id);
    }

    public function test_verified_respondent_only_sees_served_notices_and_unlinked_account_loses_access(): void
    {
        $case = $this->docket();
        $party = $case->respondents()->first();
        $resident = $this->createResident($this->barangay, $this->createPurok($this->barangay), ['mobile' => '09170000087']);
        $party->update(['resident_id' => $resident->id, 'association_status' => 'verified']);
        $doc = $case->documents()->where('kind', 'summons')->firstOrFail();
        $this->withSession(['resident_id' => $resident->id])->get('/resident/lupon/documents/'.$doc->id)->assertNotFound();
        $this->post('/lupon/cases/'.$case->id.'/verify-fee', ['payment_status' => 'waived', 'fee_amount' => 0, 'or_number' => 'WAIVER-2'])->assertSessionHasNoErrors();
        $this->travel(1)->seconds();
        $this->post('/lupon/documents/'.$doc->id.'/serve', ['served_at' => now()->toIso8601String(), 'service_method' => 'personal', 'service_note' => 'Verified personal receipt.', 'receipt_confirmed' => true])->assertSessionHasNoErrors();
        $this->get('/resident/lupon/documents/'.$doc->id)->assertOk();
        $this->get('/resident/lupon/cases/'.$case->id)->assertInertia(fn ($page) => $page->where('viewerRole', 'respondent')->where('caseData.requestedAction', null)->where('caseData.narrative', 'Nature of Dispute: Debt / Money Dispute')->has('caseData.documents', 1));
        $party->update(['association_status' => 'unlinked']);
        $this->get('/resident/lupon/documents/'.$doc->id)->assertNotFound();
        Mail::assertNothingSent();
    }

    public function test_dashboard_uses_current_pangkat_deadline_and_hides_completed_summons_target(): void
    {
        $case = $this->docket();
        $this->move($case, 'mediation_active');
        $this->move($case, 'pangkat_formed', ['pangkat_members' => ['Chair', 'Secretary', 'Member'], 'pangkat_chair_name' => 'Chair', 'pangkat_secretary_name' => 'Secretary', 'notes' => 'Mediation failed; Pangkat constituted.']);
        $this->get('/lupon/dashboard')->assertOk()->assertInertia(fn ($page) => $page->where('deadlines.0.caseNumber', $case->case_number)->where('deadlines.0.dueDate', 'Nov 5, 2026')->where('metrics.conciliation', 1));
        $timeline = LuponCaseWorkflow::timeline($case->fresh(), true);
        $this->assertFalse(collect($timeline['deadlines'])->firstWhere('label', 'Mediation — 15 days from first meeting')['active']);
    }

    public function test_closed_cases_cannot_generate_new_summons_or_reopen_a_held_hearing(): void
    {
        $case = $this->docket();
        $hearing = $case->hearings()->firstOrFail();
        $this->patch('/lupon/hearings/'.$hearing->id, ['status' => 'Held'])->assertSessionHasNoErrors();
        $this->patch('/lupon/hearings/'.$hearing->id, ['status' => 'Scheduled'])->assertSessionHasErrors('status');
        $this->move($case, 'dismissed', ['notes' => 'Verified jurisdictional dismissal.']);
        $this->post('/lupon/cases/'.$case->id.'/documents', ['kind' => 'summons'])->assertSessionHasErrors('document');
        $this->post('/lupon/cases/'.$case->id.'/hearings', ['hearing_date' => '2026-11-04', 'hearing_time' => '09:00'])->assertSessionHasErrors('hearing_date');
    }

    public function test_unresolved_next_schedule_is_atomic_and_limited_to_mediation_window(): void
    {
        config(['lupon.hearing_actions_unlocked' => true]);
        $case = $this->docket();
        $first = $case->hearings()->firstOrFail();
        $payload = ['previous_hearing_id' => $first->id, 'previous_hearing_notes' => 'No agreement reached.', 'phase' => 'Kapitan', 'hearing_time' => '10:00', 'venue' => 'Mediation room'];
        $this->post('/lupon/cases/'.$case->id.'/hearings', [...$payload, 'hearing_date' => '2026-11-18'])->assertSessionHasErrors('hearing_date');
        $this->assertSame('Scheduled', $first->fresh()->status);
        $this->assertSame('filed', $case->fresh()->status);
        $this->assertSame(1, $case->hearings()->count());
        $this->post('/lupon/cases/'.$case->id.'/hearings', [...$payload, 'hearing_date' => '2026-11-17'])->assertSessionHasNoErrors();
        $this->assertSame('Unresolved', $first->fresh()->outcome);
        $this->assertSame('Held', $first->fresh()->status);
        $this->assertSame('No agreement reached.', $first->fresh()->notes);
        $this->assertSame('mediation_active', $case->fresh()->status);
        $this->assertSame(2, $case->hearings()->count());
        $this->assertSame('2026-11-17', $case->hearings()->reorder('id', 'desc')->first()->hearing_date->toDateString());
    }

    public function test_pangkat_members_and_first_lupon_schedule_are_saved_together(): void
    {
        config(['lupon.hearing_actions_unlocked' => true]);
        $case = $this->docket();
        $first = $case->hearings()->firstOrFail();
        $this->patch('/lupon/hearings/'.$first->id, ['status' => 'Held', 'outcome' => 'Unresolved'])->assertSessionHasNoErrors();
        $payload = ['status' => 'pangkat_formed', 'expected_status' => 'mediation_active', 'event_date' => '2026-11-02', 'pangkat_members' => ['Chair One', 'Secretary Two', 'Member Three'], 'pangkat_chair_name' => 'Chair One', 'pangkat_secretary_name' => 'Secretary Two', 'notes' => 'Unsuccessful mediation; Pangkat constituted.', 'first_hearing_time' => '10:00', 'first_hearing_venue' => 'Conciliation room'];
        $this->patch('/lupon/cases/'.$case->id.'/status', [...$payload, 'first_hearing_date' => '2026-11-06'])->assertSessionHasErrors('first_hearing_date');
        $this->assertSame('mediation_active', $case->fresh()->status);
        $this->assertFalse($case->hearings()->where('phase', 'Lupon')->exists());
        $this->patch('/lupon/cases/'.$case->id.'/status', [...$payload, 'first_hearing_date' => '2026-11-05'])->assertSessionHasNoErrors();
        $this->assertSame('Lupon', $case->fresh()->phase);
        $this->assertSame(['Chair One', 'Secretary Two', 'Member Three'], $case->fresh()->pangkat_members);
        $hearing = $case->hearings()->where('phase', 'Lupon')->firstOrFail();
        $this->assertSame(1, $hearing->phase_number);
        $this->assertSame('2026-11-05', $hearing->hearing_date->toDateString());
    }
}
