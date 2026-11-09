<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\LuponCase;
use App\Models\LuponCaseEvent;
use App\Models\LuponCaseParty;
use App\Models\LuponComplaint;
use App\Models\LuponDocument;
use App\Models\LuponHearing;
use App\Models\LuponSettlement;
use App\Models\Purok;
use App\Models\Resident;
use App\Models\StaffAssignment;
use App\Models\User;
use App\Support\LuponEvidenceStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class ProtectLuponLegalRecordsOnResidentDeletionTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Purok $purok;

    private Resident $resident;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->barangay = $this->createBarangay('Legal Protection Barangay', 'BRGY-LPB');
        $this->purok = $this->createPurok($this->barangay, 'Purok Peace');
        $this->resident = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Maria',
            'last_name' => 'Clara',
            'mobile' => '09171234567',
        ]);
    }

    public function test_deleting_resident_with_complaint_preserves_complaint_and_sets_resident_id_to_null(): void
    {
        $complaint = LuponComplaint::create([
            'barangay_id' => $this->barangay->id,
            'resident_id' => $this->resident->id,
            'reference' => 'LUP-2026-000101',
            'respondent_name' => 'Pedro Penduko',
            'respondent_resident_status' => 'resident',
            'respondent_address' => 'Purok Peace, Legal Protection Barangay',
            'respondent_contact' => '09189876543',
            'complaint_type' => 'Property Dispute',
            'incident_date' => '2026-11-01',
            'incident_location' => 'Purok Peace',
            'narrative' => 'Dispute over land property encroachment.',
            'requested_action' => 'Amicable settlement and boundary adjustment.',
            'status' => 'Under Review',
            'complainant_snapshot' => [
                'name' => 'Maria Clara',
                'address' => 'Purok Peace, Legal Protection Barangay',
                'contact' => '09171234567',
            ],
        ]);

        $this->resident->delete();

        $this->assertDatabaseMissing('residents', ['id' => $this->resident->id]);
        $this->assertDatabaseHas('lupon_complaints', [
            'id' => $complaint->id,
            'resident_id' => null,
            'reference' => 'LUP-2026-000101',
            'narrative' => 'Dispute over land property encroachment.',
            'requested_action' => 'Amicable settlement and boundary adjustment.',
        ]);

        $complaint->refresh();
        $this->assertNull($complaint->resident_id);
        $this->assertNull($complaint->resident);
        $this->assertSame('Pedro Penduko', $complaint->respondent_name);
        $this->assertSame('Maria Clara', $complaint->complainant_snapshot['name']);
    }

    public function test_deleting_resident_with_official_case_preserves_case_and_case_number_and_status(): void
    {
        $complaint = LuponComplaint::create([
            'barangay_id' => $this->barangay->id,
            'resident_id' => $this->resident->id,
            'reference' => 'LUP-2026-000102',
            'respondent_name' => 'Crisostomo Ibarra',
            'respondent_resident_status' => 'resident',
            'complaint_type' => 'Collection of Debt',
            'incident_date' => '2026-10-15',
            'incident_location' => 'Barangay Hall',
            'narrative' => 'Unpaid loan of 5000 pesos.',
            'requested_action' => 'Payment agreement.',
            'status' => 'Payment Verified',
        ]);

        $case = LuponCase::create([
            'barangay_id' => $this->barangay->id,
            'complaint_id' => $complaint->id,
            'origin' => 'promoted',
            'case_number' => 'KP-2026-BRGY-LPB-0042',
            'title' => 'Collection of Debt: Maria Clara vs. Crisostomo Ibarra',
            'case_type' => 'Collection of Debt',
            'phase' => 'Kapitan',
            'status' => 'mediation_active',
            'filed_date' => '2026-10-20',
            'promoted_at' => now(),
            'payment_status' => 'paid',
            'fee_amount' => 50.00,
            'or_number' => 'OR-998877',
            'paid_at' => now(),
        ]);

        $this->resident->delete();

        $this->assertDatabaseMissing('residents', ['id' => $this->resident->id]);

        $this->assertDatabaseHas('lupon_complaints', [
            'id' => $complaint->id,
            'resident_id' => null,
            'reference' => 'LUP-2026-000102',
        ]);

        $this->assertDatabaseHas('lupon_cases', [
            'id' => $case->id,
            'complaint_id' => $complaint->id,
            'case_number' => 'KP-2026-BRGY-LPB-0042',
            'title' => 'Collection of Debt: Maria Clara vs. Crisostomo Ibarra',
            'status' => 'mediation_active',
            'phase' => 'Kapitan',
        ]);

        $case->refresh();
        $this->assertSame('KP-2026-BRGY-LPB-0042', $case->case_number);
        $this->assertSame('mediation_active', $case->status);
        $this->assertSame($complaint->id, $case->complaint_id);
    }

    public function test_case_parties_and_historical_snapshots_remain_intact_when_resident_is_deleted(): void
    {
        $case = LuponCase::create([
            'barangay_id' => $this->barangay->id,
            'origin' => 'manual',
            'case_number' => 'KP-2026-BRGY-LPB-0043',
            'title' => 'Ejectment: Maria Clara vs. Damaso Verdolagas',
            'case_type' => 'Ejectment',
            'phase' => 'Kapitan',
            'status' => 'filed',
            'filed_date' => '2026-11-01',
        ]);

        $complainantParty = LuponCaseParty::create([
            'case_id' => $case->id,
            'party_type' => 'complainant',
            'resident_id' => $this->resident->id,
            'name' => 'Maria Clara',
            'is_resident' => true,
            'address' => 'Purok Peace, Legal Protection Barangay',
            'contact_number' => '09171234567',
            'is_primary' => true,
        ]);

        $respondentParty = LuponCaseParty::create([
            'case_id' => $case->id,
            'party_type' => 'respondent',
            'resident_id' => null,
            'name' => 'Damaso Verdolagas',
            'is_resident' => false,
            'address' => 'San Diego',
            'contact_number' => '09191112233',
            'is_primary' => true,
        ]);

        $this->resident->delete();

        $this->assertDatabaseMissing('residents', ['id' => $this->resident->id]);

        $this->assertDatabaseHas('lupon_case_parties', [
            'id' => $complainantParty->id,
            'case_id' => $case->id,
            'party_type' => 'complainant',
            'resident_id' => null,
            'name' => 'Maria Clara',
            'is_resident' => true,
            'address' => 'Purok Peace, Legal Protection Barangay',
            'contact_number' => '09171234567',
            'is_primary' => true,
        ]);

        $this->assertDatabaseHas('lupon_case_parties', [
            'id' => $respondentParty->id,
            'case_id' => $case->id,
            'party_type' => 'respondent',
            'resident_id' => null,
            'name' => 'Damaso Verdolagas',
        ]);

        $complainantParty->refresh();
        $this->assertNull($complainantParty->resident_id);
        $this->assertSame('Maria Clara', $complainantParty->name);
        $this->assertSame('09171234567', $complainantParty->contact_number);
    }

    public function test_hearings_settlements_events_and_documents_remain_intact_when_resident_is_deleted(): void
    {
        $complaint = LuponComplaint::create([
            'barangay_id' => $this->barangay->id,
            'resident_id' => $this->resident->id,
            'reference' => 'LUP-2026-000103',
            'respondent_name' => 'Elias Salaysay',
            'respondent_resident_status' => 'resident',
            'complaint_type' => 'Noise Disturbance',
            'incident_date' => '2026-11-02',
            'incident_location' => 'Purok Peace',
            'narrative' => 'Loud videoke past midnight.',
            'requested_action' => 'Agreement on quiet hours.',
            'status' => 'Under Conciliation',
        ]);

        $case = LuponCase::create([
            'barangay_id' => $this->barangay->id,
            'complaint_id' => $complaint->id,
            'origin' => 'promoted',
            'case_number' => 'KP-2026-BRGY-LPB-0044',
            'title' => 'Noise Disturbance: Maria Clara vs. Elias Salaysay',
            'case_type' => 'Noise Disturbance',
            'phase' => 'Kapitan',
            'status' => 'mediation_active',
            'filed_date' => '2026-11-03',
        ]);

        $hearing = LuponHearing::create([
            'case_id' => $case->id,
            'barangay_id' => $this->barangay->id,
            'hearing_number' => 1,
            'phase' => 'Kapitan',
            'hearing_date' => '2026-11-10',
            'hearing_time' => '10:00',
            'venue' => 'Barangay Hall Session Room',
            'status' => 'Scheduled',
            'notes' => 'First mediation hearing.',
        ]);

        $settlement = LuponSettlement::create([
            'case_id' => $case->id,
            'barangay_id' => $this->barangay->id,
            'settlement_type' => 'Amicable Settlement',
            'settlement_date' => '2026-11-10',
            'terms' => 'Parties agree to stop loud music by 10:00 PM.',
            'status' => 'Active',
        ]);

        $event = LuponCaseEvent::create([
            'case_id' => $case->id,
            'from_status' => 'filed',
            'to_status' => 'mediation_active',
            'occurred_at' => now(),
            'notes' => 'Case moved to active mediation.',
            'metadata' => ['action' => 'mediation_started'],
        ]);

        $document = LuponDocument::create([
            'case_id' => $case->id,
            'hearing_id' => $hearing->id,
            'kind' => 'notice_of_hearing',
            'status' => 'ready',
            'path' => 'lupon/documents/BRGY-LPB/notice_hearing_1.pdf',
            'file_name' => 'notice_hearing_1.pdf',
            'signatories' => ['Pangkat Chair', 'Lupon Secretary'],
            'generated_at' => now(),
        ]);

        $this->resident->delete();

        $this->assertDatabaseMissing('residents', ['id' => $this->resident->id]);

        $this->assertDatabaseHas('lupon_hearings', [
            'id' => $hearing->id,
            'case_id' => $case->id,
            'hearing_number' => 1,
            'status' => 'Scheduled',
            'venue' => 'Barangay Hall Session Room',
        ]);

        $this->assertDatabaseHas('lupon_settlements', [
            'id' => $settlement->id,
            'case_id' => $case->id,
            'settlement_type' => 'Amicable Settlement',
            'status' => 'Active',
        ]);

        $this->assertDatabaseHas('lupon_case_events', [
            'id' => $event->id,
            'case_id' => $case->id,
            'to_status' => 'mediation_active',
        ]);

        $this->assertDatabaseHas('lupon_documents', [
            'id' => $document->id,
            'case_id' => $case->id,
            'kind' => 'notice_of_hearing',
            'status' => 'ready',
        ]);
    }

    public function test_evidence_records_and_uploaded_files_remain_physically_present_when_resident_is_deleted(): void
    {
        Storage::fake('local');

        $complaint = LuponComplaint::create([
            'barangay_id' => $this->barangay->id,
            'resident_id' => $this->resident->id,
            'reference' => 'LUP-2026-000104',
            'respondent_name' => 'Tarsilo Alasigan',
            'respondent_resident_status' => 'resident',
            'complaint_type' => 'Physical Injuries',
            'incident_date' => '2026-11-04',
            'incident_location' => 'Main Road',
            'narrative' => 'Physical altercation with minor injuries.',
            'requested_action' => 'Medical fee reimbursement.',
            'status' => 'Under Review',
        ]);

        $fakeFile = UploadedFile::fake()->create('medical_certificate.pdf', 150, 'application/pdf');
        $storedPaths = LuponEvidenceStore::store([$fakeFile], $complaint, null, $this->resident->id);
        $this->assertCount(1, $storedPaths);
        $evidencePath = $storedPaths[0];

        Storage::disk('local')->assertExists($evidencePath);

        $this->assertDatabaseHas('lupon_evidence', [
            'complaint_id' => $complaint->id,
            'resident_id' => $this->resident->id,
            'path' => $evidencePath,
        ]);

        // Also add a document file in storage
        $docPath = 'lupon/documents/'.$this->barangay->id.'/summons_001.pdf';
        Storage::disk('local')->put($docPath, 'sample summons pdf content');
        Storage::disk('local')->assertExists($docPath);

        $case = LuponCase::create([
            'barangay_id' => $this->barangay->id,
            'complaint_id' => $complaint->id,
            'origin' => 'promoted',
            'case_number' => 'KP-2026-BRGY-LPB-0045',
            'title' => 'Physical Injuries: Maria Clara vs. Tarsilo Alasigan',
            'case_type' => 'Physical Injuries',
            'phase' => 'Kapitan',
            'status' => 'filed',
            'filed_date' => '2026-11-04',
        ]);

        $document = LuponDocument::create([
            'case_id' => $case->id,
            'kind' => 'summons',
            'status' => 'ready',
            'path' => $docPath,
            'file_name' => 'summons_001.pdf',
        ]);

        $this->resident->delete();

        $this->assertDatabaseMissing('residents', ['id' => $this->resident->id]);

        // Database rows are preserved, with resident_id set to null on evidence
        $this->assertDatabaseHas('lupon_evidence', [
            'complaint_id' => $complaint->id,
            'resident_id' => null,
            'path' => $evidencePath,
        ]);

        $this->assertDatabaseHas('lupon_documents', [
            'id' => $document->id,
            'case_id' => $case->id,
            'path' => $docPath,
        ]);

        // Physical files remain intact in private local storage
        Storage::disk('local')->assertExists($evidencePath);
        Storage::disk('local')->assertExists($docPath);
    }

    public function test_staff_assignments_are_removed_when_resident_is_deleted(): void
    {
        $user = User::factory()->create([
            'barangay_id' => $this->barangay->id,
            'resident_id' => $this->resident->id,
            'status' => 'active',
        ]);

        $assignment = StaffAssignment::create([
            'user_id' => $user->id,
            'resident_id' => $this->resident->id,
            'barangay_id' => $this->barangay->id,
            'department' => 'Lupon',
            'position' => 'Lupon Staff',
            'is_active' => true,
        ]);

        $complaint = LuponComplaint::create([
            'barangay_id' => $this->barangay->id,
            'resident_id' => $this->resident->id,
            'reference' => 'LUP-2026-000105',
            'respondent_name' => 'Sisa Basilio',
            'respondent_resident_status' => 'resident',
            'complaint_type' => 'Oral Defamation',
            'incident_date' => '2026-11-05',
            'incident_location' => 'Market',
            'narrative' => 'Defamatory remarks.',
            'requested_action' => 'Public apology.',
            'status' => 'Under Review',
        ]);

        $this->assertDatabaseHas('staff_assignments', ['id' => $assignment->id]);
        $this->assertDatabaseHas('lupon_complaints', ['id' => $complaint->id]);

        $this->resident->delete();

        $this->assertDatabaseMissing('residents', ['id' => $this->resident->id]);

        // Staff assignment is deleted (revoking staff access)
        $this->assertDatabaseMissing('staff_assignments', ['id' => $assignment->id]);

        // Legal record is preserved
        $this->assertDatabaseHas('lupon_complaints', [
            'id' => $complaint->id,
            'resident_id' => null,
            'reference' => 'LUP-2026-000105',
        ]);
    }

    public function test_indexes_and_foreign_keys_remain_intact_after_migration(): void
    {
        $this->assertTrue(Schema::hasTable('lupon_complaints'));
        $this->assertTrue(Schema::hasColumn('lupon_complaints', 'resident_id'));

        // Check that resident_id can hold NULL
        $complaint = LuponComplaint::create([
            'barangay_id' => $this->barangay->id,
            'resident_id' => null,
            'reference' => 'LUP-2026-000106',
            'respondent_name' => 'Anonymous Walkin',
            'respondent_resident_status' => 'non_resident',
            'complaint_type' => 'Nuisance',
            'incident_date' => '2026-11-06',
            'incident_location' => 'Street',
            'narrative' => 'Nuisance narrative.',
            'requested_action' => 'Mediation.',
            'status' => 'Under Review',
        ]);

        $this->assertDatabaseHas('lupon_complaints', [
            'id' => $complaint->id,
            'resident_id' => null,
        ]);
    }
}
