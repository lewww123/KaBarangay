<?php
namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\CertificateRequest;
use App\Models\LuponCase;
use App\Models\LuponComplaint;
use App\Models\Resident;
use App\Support\AssistantResidentRecords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class ChatbotTrackingTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;
    private Resident $resident;
    private Resident $other;
    protected function setUp(): void
    {
        parent::setUp();
        $barangay = $this->createBarangay('Tracking Barangay', 'TRACK');
        $purok = $this->createPurok($barangay);
        $this->resident = $this->createResident($barangay, $purok);
        $this->other = $this->createResident($barangay, $purok, ['mobile' => '09170000002']);
        Http::preventStrayRequests();
        $this->withSession(['resident_id' => $this->resident->id]);
    }
    private function certificateRequest(Resident $resident, string $reference, ?int $barangay = null): CertificateRequest
    {
        $certificate = Certificate::create(['barangay_id' => $resident->barangay_id, 'code' => $reference, 'name' => 'Indigency Certificate', 'fee' => 0, 'is_active' => true]);
        return CertificateRequest::create(['barangay_id' => $barangay ?? $resident->barangay_id, 'resident_id' => $resident->id, 'certificate_id' => $certificate->id, 'reference' => $reference, 'purpose' => 'Private medical purpose', 'status' => 'Pending', 'admin_note' => 'Private internal note', 'submitted_at' => now()]);
    }
    public function test_tracking_reads_current_database_status_without_provider_and_hides_other_residents(): void
    {
        config(['services.groq.key' => null]);
        $own = $this->certificateRequest($this->resident, 'CERT-2026-001');
        $this->certificateRequest($this->other, 'CERT-2026-PRIVATE');
        $this->postJson('/chatbot/send', ['message' => 'Track status ko'])->assertOk()->assertJsonPath('source', 'resident_records')->assertJsonPath('records.0.reference', 'CERT-2026-001')->assertJsonPath('records.0.status', 'Pending')->assertDontSee('PRIVATE')->assertDontSee('Private medical purpose')->assertDontSee('Private internal note');
        $own->update(['status' => 'Ready for Claim']);
        $this->postJson('/chatbot/send', ['message' => 'Track status ko'])->assertOk()->assertJsonPath('records.0.status', 'Ready for Claim');
        Http::assertNothingSent();
    }
    public function test_foreign_reference_and_cross_barangay_record_are_not_exposed(): void
    {
        $foreign = $this->createBarangay('Other Barangay', 'OTHER-TRACK');
        $this->certificateRequest($this->resident, 'CERT-2026-CROSS', $foreign->id);
        $this->certificateRequest($this->other, 'CERT-2026-002');
        $this->postJson('/chatbot/send', ['message' => 'Track CERT-2026-002'])->assertOk()->assertJsonCount(0, 'records');
        $this->postJson('/chatbot/send', ['message' => 'Track status ko'])->assertOk()->assertJsonCount(0, 'records');
        Http::assertNothingSent();
    }
    public function test_specific_older_reference_is_found_outside_recent_five(): void
    {
        $this->certificateRequest($this->resident, 'CERT-2026-001')->update(['updated_at' => now()->subMonth()]);
        for ($i=2; $i<=7; $i++) $this->certificateRequest($this->resident, 'CERT-2026-00'.$i);
        $this->postJson('/chatbot/send', ['message' => 'Track CERT-2026-001'])->assertOk()->assertJsonCount(1, 'records')->assertJsonPath('records.0.reference', 'CERT-2026-001');
    }
    public function test_lupon_cases_require_complainant_or_verified_respondent_membership(): void
    {
        $complaint = LuponComplaint::create(['barangay_id' => $this->resident->barangay_id, 'resident_id' => $this->other->id, 'reference' => 'LUP-2026-001', 'respondent_name' => 'Respondent', 'respondent_resident_status' => 'Yes', 'complaint_type' => 'Debt / Money Dispute', 'incident_date' => now(), 'incident_location' => 'Barangay', 'narrative' => 'Private narrative', 'requested_action' => 'Mediation', 'status' => 'Submitted']);
        $case = LuponCase::create(['barangay_id' => $this->resident->barangay_id, 'complaint_id' => $complaint->id, 'case_number' => 'KP-2026-001', 'title' => 'Private dispute', 'case_type' => 'Debt / Money Dispute', 'phase' => 'Kapitan', 'status' => 'mediation_active', 'filed_date' => now()]);
        $party = $case->parties()->create(['resident_id' => $this->resident->id, 'name' => 'Respondent', 'party_type' => 'respondent', 'association_status' => 'unverified']);
        $this->assertCount(0, app(AssistantResidentRecords::class)->snapshot($this->resident));
        $party->update(['association_status' => 'verified']);
        $this->postJson('/chatbot/send', ['message' => 'Status ng kaso ko'])->assertOk()->assertJsonPath('records.0.reference', 'KP-2026-001')->assertJsonPath('records.0.status', 'Mediation active')->assertDontSee('Private narrative')->assertDontSee('Private dispute');
    }

    public function test_how_to_questions_use_portal_steps_and_current_barangay_catalog(): void
    {
        config(['services.groq.key' => 'test-key', 'services.groq.ca_bundle' => null]);
        $own = $this->certificateRequest($this->resident, 'CERT-2026-011');
        $own->certificate->update(['fee' => 25, 'requirements' => ['Valid ID', 'Proof of residence']]);
        Certificate::create(['barangay_id' => $this->resident->barangay_id, 'code' => 'INACTIVE', 'name' => 'Hidden Inactive Certificate', 'is_active' => false]);
        $foreign = $this->createBarangay('Foreign Catalog', 'FOREIGN-CAT');
        Certificate::create(['barangay_id' => $foreign->id, 'code' => 'FOREIGN', 'name' => 'Hidden Foreign Certificate', 'is_active' => true]);
        Http::fake(['api.groq.com/*' => Http::response(['choices' => [['message' => ['content' => '1. Buksan ang Request Certificate.']]]])]);
        $this->postJson('/chatbot/send', ['message' => 'Paano mag-track ng certificate online?'])->assertOk()->assertJsonPath('reply', '1. Buksan ang Request Certificate.');
        Http::assertSent(function ($request) {
            $messages = json_encode($request['messages']);
            return str_contains($messages, 'My Requests') && str_contains($messages, 'Submit Request') && str_contains($messages, 'Proof of residence') && str_contains($messages, '25.00') && !str_contains($messages, 'Hidden Inactive Certificate') && !str_contains($messages, 'Hidden Foreign Certificate');
        });
    }
}
