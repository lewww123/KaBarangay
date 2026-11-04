<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Barangay;
use App\Models\Purok;
use App\Models\Resident;
use App\Models\ResidentQrCredential;
use App\Models\Sector;
use App\Models\SectorApplication;
use App\Models\SectorApplicationDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class SectorApplicationWorkflowTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangayA;

    private Barangay $barangayB;

    private Purok $purokA;

    private Purok $purokB;

    private Sector $seniorSector;

    private Sector $pwdSector;

    private User $adminA;

    private User $seniorStaffA;

    private User $pwdStaffA;

    private User $adminB;

    private User $seniorStaffB;

    private Resident $residentA;

    private Resident $residentB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');

        $this->barangayA = $this->createBarangay('San Jose', 'BSJ');
        $this->barangayB = $this->createBarangay('Santa Maria', 'BSM');

        $this->purokA = $this->createPurok($this->barangayA, 'Purok 1');
        $this->purokB = $this->createPurok($this->barangayB, 'Purok 1');

        $this->seniorSector = Sector::create([
            'name' => 'Senior Citizen',
            'slug' => 'senior',
            'requires_proof' => true,
            'is_active' => true,
        ]);

        $this->pwdSector = Sector::create([
            'name' => 'Person with Disability',
            'slug' => 'pwd',
            'requires_proof' => true,
            'is_active' => true,
        ]);

        $this->adminA = $this->createStaff($this->barangayA, 'admin');
        $this->seniorStaffA = $this->createAssignedStaff(['barangay' => $this->barangayA, 'purok' => $this->purokA], 'senior', 'Senior Staff');
        $this->pwdStaffA = $this->createAssignedStaff(['barangay' => $this->barangayA, 'purok' => $this->purokA], 'pwd', 'PWD Staff');

        $this->adminB = $this->createStaff($this->barangayB, 'admin');
        $this->seniorStaffB = $this->createAssignedStaff(['barangay' => $this->barangayB, 'purok' => $this->purokB], 'senior', 'Senior Staff');

        $this->residentA = $this->createResident($this->barangayA, $this->purokA, [
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'mobile' => '09170000001',
        ]);

        $this->residentB = $this->createResident($this->barangayB, $this->purokB, [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'mobile' => '09170000002',
        ]);
    }

    public function test_sector_qr_lookup_keeps_keyboard_wedge_and_manual_enter_support(): void
    {
        $source = file_get_contents(base_path('resources/js/components/sectors/SectorApplicationWizard.tsx'));

        $this->assertSourceContainsString('lookupInputRef.current?.focus({ preventScroll: true })', $source);
        $this->assertSourceContainsString("e.key === 'Enter'", $source);
        $this->assertSourceContainsString('e.preventDefault()', $source);
        $this->assertSourceContainsString('handleResidentLookup()', $source);
        $this->assertSourceContainsString('value={lookupQuery}', $source);
        $this->assertSourceContainsString('onChange={(e) => setLookupQuery(e.target.value)}', $source);
    }

    public function test_senior_staff_can_submit_senior_application(): void
    {
        $file = UploadedFile::fake()->create('birth_cert.pdf', 500, 'application/pdf');

        $response = $this->actingAs($this->seniorStaffA)->post('/senior/applications', [
            'resident_id' => $this->residentA->id,
            'osca_id' => 'OSCA-12345',
            'documents' => [$file],
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('sector_applications', [
            'barangay_id' => $this->barangayA->id,
            'resident_id' => $this->residentA->id,
            'sector_id' => $this->seniorSector->id,
            'submission_type' => 'staff_assisted',
            'submitted_by_user_id' => $this->seniorStaffA->id,
            'status' => 'pending_staff_review',
        ]);

        $this->assertDatabaseHas('sector_application_documents', [
            'original_name' => 'birth_cert.pdf',
            'mime_type' => 'application/pdf',
        ]);
    }

    public function test_pwd_staff_can_submit_pwd_application(): void
    {
        $file = UploadedFile::fake()->create('pwd_card.jpg', 300, 'image/jpeg');

        $response = $this->actingAs($this->pwdStaffA)->post('/pwd/applications', [
            'resident_id' => $this->residentA->id,
            'disability_type' => 'Orthopedic',
            'documents' => [$file],
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('sector_applications', [
            'barangay_id' => $this->barangayA->id,
            'resident_id' => $this->residentA->id,
            'sector_id' => $this->pwdSector->id,
            'submission_type' => 'staff_assisted',
            'status' => 'pending_staff_review',
        ]);
    }

    public function test_staff_cannot_submit_for_wrong_sector(): void
    {
        // Senior staff attempting to submit for PWD desk (403 on role middleware or controller)
        $response = $this->actingAs($this->seniorStaffA)->post('/pwd/applications', [
            'resident_id' => $this->residentA->id,
            'disability_type' => 'Visual',
        ]);
        $response->assertForbidden();

        // PWD staff attempting to submit for Senior desk
        $response = $this->actingAs($this->pwdStaffA)->post('/senior/applications', [
            'resident_id' => $this->residentA->id,
            'osca_id' => 'OSCA-999',
        ]);
        $response->assertForbidden();
    }

    public function test_resident_self_service_derives_identity_from_session(): void
    {
        $file = UploadedFile::fake()->create('senior_id.pdf', 400, 'application/pdf');

        // Attacker attempts to pass a different resident_id in the body
        $response = $this->withSession(['resident_id' => $this->residentA->id])
            ->post('/resident/sector-applications', [
                'resident_id' => $this->residentB->id, // Should be ignored
                'sector' => 'senior',
                'documents' => [$file],
            ]);

        $response->assertRedirect();

        // The created application MUST belong to residentA, never residentB
        $this->assertDatabaseHas('sector_applications', [
            'resident_id' => $this->residentA->id,
            'sector_id' => $this->seniorSector->id,
            'submission_type' => 'self_service',
            'submitted_by_user_id' => null,
            'status' => 'pending_staff_review',
        ]);

        $this->assertDatabaseMissing('sector_applications', [
            'resident_id' => $this->residentB->id,
        ]);
    }

    public function test_duplicate_pending_application_is_prevented(): void
    {
        // Create initial pending application
        $this->actingAs($this->seniorStaffA)->post('/senior/applications', [
            'resident_id' => $this->residentA->id,
        ]);

        $this->assertDatabaseCount('sector_applications', 1);

        // Attempting a second application while first is pending must fail with validation error
        $response = $this->actingAs($this->seniorStaffA)->post('/senior/applications', [
            'resident_id' => $this->residentA->id,
        ]);

        $response->assertSessionHasErrors('sector');
        $this->assertDatabaseCount('sector_applications', 1);
    }

    public function test_existing_sector_membership_prevents_duplicate_application(): void
    {
        // Resident already has senior sector membership
        $this->residentA->sectors()->sync([$this->seniorSector->id]);

        $response = $this->actingAs($this->seniorStaffA)->post('/senior/applications', [
            'resident_id' => $this->residentA->id,
        ]);

        $response->assertSessionHasErrors('sector');
        $this->assertDatabaseCount('sector_applications', 0);
    }

    public function test_staff_approval_activates_membership(): void
    {
        $application = SectorApplication::create([
            'barangay_id' => $this->barangayA->id,
            'resident_id' => $this->residentA->id,
            'sector_id' => $this->seniorSector->id,
            'tracking_number' => 'APP-SNR-2026-001',
            'submission_type' => 'staff_assisted',
            'status' => 'pending_staff_review',
        ]);

        $response = $this->actingAs($this->seniorStaffA)->post("/senior/applications/{$application->id}/approve", [
            'checklist' => ['id_verified' => true, 'residency_verified' => true],
            'review_notes' => 'Complete and verified.',
        ]);

        $response->assertRedirect();

        $application->refresh();
        $this->assertSame('approved', $application->status);
        $this->assertSame($this->seniorStaffA->id, $application->staff_reviewed_by);
        $this->assertSame('Complete and verified.', $application->staff_review_notes);
        $this->assertTrue($application->staff_checklist_completed);
        $this->assertTrue($this->residentA->sectors()->where('sectors.id', $this->seniorSector->id)->exists());
    }

    public function test_staff_correction_workflow(): void
    {
        $application = SectorApplication::create([
            'barangay_id' => $this->barangayA->id,
            'resident_id' => $this->residentA->id,
            'sector_id' => $this->seniorSector->id,
            'tracking_number' => 'APP-SNR-2026-002',
            'submission_type' => 'staff_assisted',
            'status' => 'pending_staff_review',
        ]);

        $response = $this->actingAs($this->seniorStaffA)->post("/senior/applications/{$application->id}/correction", [
            'correction_notes' => 'Please provide a clearer scan of the birth certificate.',
        ]);

        $response->assertRedirect();

        $application->refresh();
        $this->assertSame('requires_correction', $application->status);
        $this->assertSame('Please provide a clearer scan of the birth certificate.', $application->correction_notes);
    }

    public function test_staff_approval_creates_only_requested_membership(): void
    {
        $application = SectorApplication::create([
            'barangay_id' => $this->barangayA->id,
            'resident_id' => $this->residentA->id,
            'sector_id' => $this->seniorSector->id,
            'tracking_number' => 'APP-SNR-2026-003',
            'submission_type' => 'staff_assisted',
            'status' => 'pending_staff_review',
        ]);

        $response = $this->actingAs($this->seniorStaffA)->post("/senior/applications/{$application->id}/approve", [
            'review_notes' => 'Approved pursuant to RA 9994.',
        ]);

        $response->assertRedirect();

        $application->refresh();
        $this->assertSame('approved', $application->status);
        $this->assertSame($this->seniorStaffA->id, $application->staff_reviewed_by);
        $this->assertNull($application->admin_decided_by);

        // Verify Senior sector was attached to resident
        $this->assertTrue($this->residentA->sectors()->where('sectors.id', $this->seniorSector->id)->exists());

        // Verify PWD sector was NOT attached
        $this->assertFalse($this->residentA->sectors()->where('sectors.id', $this->pwdSector->id)->exists());
    }

    public function test_staff_rejection_creates_no_membership(): void
    {
        $application = SectorApplication::create([
            'barangay_id' => $this->barangayA->id,
            'resident_id' => $this->residentA->id,
            'sector_id' => $this->seniorSector->id,
            'tracking_number' => 'APP-SNR-2026-004',
            'submission_type' => 'staff_assisted',
            'status' => 'pending_staff_review',
        ]);

        $response = $this->actingAs($this->seniorStaffA)->post("/senior/applications/{$application->id}/reject", [
            'reason' => 'Applicant does not meet minimum age requirement.',
        ]);

        $response->assertRedirect();

        $application->refresh();
        $this->assertSame('rejected', $application->status);
        $this->assertSame('Applicant does not meet minimum age requirement.', $application->staff_review_notes);

        // Verify NO sector membership was created
        $this->assertFalse($this->residentA->sectors()->where('sectors.id', $this->seniorSector->id)->exists());
    }

    public function test_double_or_invalid_approval_is_prevented(): void
    {
        $application = SectorApplication::create([
            'barangay_id' => $this->barangayA->id,
            'resident_id' => $this->residentA->id,
            'sector_id' => $this->seniorSector->id,
            'tracking_number' => 'APP-SNR-2026-005',
            'submission_type' => 'staff_assisted',
            'status' => 'approved', // Already approved
        ]);

        $response = $this->actingAs($this->seniorStaffA)->post("/senior/applications/{$application->id}/approve", [
            'review_notes' => 'Attempting double approval',
        ]);

        $response->assertSessionHasErrors('status');
    }

    public function test_admin_cannot_decide_sector_applications(): void
    {
        $application = SectorApplication::create([
            'barangay_id' => $this->barangayA->id,
            'resident_id' => $this->residentA->id,
            'sector_id' => $this->seniorSector->id,
            'tracking_number' => 'APP-SNR-2026-009',
            'submission_type' => 'staff_assisted',
            'status' => 'pending_staff_review',
        ]);

        $this->actingAs($this->adminA)
            ->post("/admin/sector-applications/{$application->id}/approve")
            ->assertNotFound();

        $this->actingAs($this->adminA)
            ->post("/admin/sector-applications/{$application->id}/reject")
            ->assertNotFound();

        $this->assertSame('pending_staff_review', $application->fresh()->status);
    }

    public function test_cross_barangay_access_is_prevented(): void
    {
        $application = SectorApplication::create([
            'barangay_id' => $this->barangayA->id,
            'resident_id' => $this->residentA->id,
            'sector_id' => $this->seniorSector->id,
            'tracking_number' => 'APP-SNR-2026-006',
            'submission_type' => 'staff_assisted',
            'status' => 'pending_staff_review',
        ]);

        // Senior Staff B from Barangay B attempts to view application of Barangay A
        $response = $this->actingAs($this->seniorStaffB)->get("/senior/applications/{$application->id}");
        $response->assertNotFound();

        // Senior Staff B from Barangay B attempts to approve application of Barangay A
        $response = $this->actingAs($this->seniorStaffB)->post("/senior/applications/{$application->id}/approve");
        $response->assertForbidden();
    }

    public function test_cross_sector_staff_access_is_prevented(): void
    {
        $seniorApp = SectorApplication::create([
            'barangay_id' => $this->barangayA->id,
            'resident_id' => $this->residentA->id,
            'sector_id' => $this->seniorSector->id,
            'tracking_number' => 'APP-SNR-2026-007',
            'submission_type' => 'staff_assisted',
            'status' => 'pending_staff_review',
        ]);

        // PWD staff accessing Senior application route
        $response = $this->actingAs($this->pwdStaffA)->get("/senior/applications/{$seniorApp->id}");
        $response->assertForbidden();

        // PWD staff attempting to approve Senior application
        $response = $this->actingAs($this->pwdStaffA)->post("/senior/applications/{$seniorApp->id}/approve", [
            'review_notes' => 'Illegal approval',
        ]);
        $response->assertForbidden();
    }

    public function test_document_ownership_and_authorization(): void
    {
        $application = SectorApplication::create([
            'barangay_id' => $this->barangayA->id,
            'resident_id' => $this->residentA->id,
            'sector_id' => $this->seniorSector->id,
            'tracking_number' => 'APP-SNR-2026-008',
            'submission_type' => 'staff_assisted',
            'status' => 'pending_staff_review',
        ]);

        Storage::disk('local')->put('sector-applications/1/1/doc.pdf', 'fake pdf content');

        $doc = SectorApplicationDocument::create([
            'sector_application_id' => $application->id,
            'document_type' => 'senior_proof',
            'title' => 'Birth Certificate',
            'disk' => 'local',
            'path' => 'sector-applications/1/1/doc.pdf',
            'original_name' => 'doc.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'verification_status' => 'pending',
        ]);

        // Authorized: Senior staff of Barangay A
        $response = $this->actingAs($this->seniorStaffA)->get("/sector-applications/{$application->id}/documents/{$doc->id}/file");
        $response->assertOk();

        // Authorized: Admin of Barangay A
        $response = $this->actingAs($this->adminA)->get("/sector-applications/{$application->id}/documents/{$doc->id}/file");
        $response->assertOk();

        auth()->logout();

        // Authorized: The resident themselves via session
        $response = $this->withSession(['resident_id' => $this->residentA->id])
            ->get("/sector-applications/{$application->id}/documents/{$doc->id}/file");
        $response->assertOk();

        // Denied: Different resident from session
        $response = $this->withSession(['resident_id' => $this->residentB->id])
            ->get("/sector-applications/{$application->id}/documents/{$doc->id}/file");
        $response->assertForbidden();

        // Denied: Staff from different barangay
        $response = $this->actingAs($this->seniorStaffB)->get("/sector-applications/{$application->id}/documents/{$doc->id}/file");
        $response->assertForbidden();

        // Denied: PWD staff trying to view Senior doc
        $response = $this->actingAs($this->pwdStaffA)->get("/sector-applications/{$application->id}/documents/{$doc->id}/file");
        $response->assertForbidden();
    }

    public function test_audit_events_are_recorded_with_allowlisted_context(): void
    {
        $this->actingAs($this->seniorStaffA)->post('/senior/applications', [
            'resident_id' => $this->residentA->id,
            'osca_id' => 'OSCA-AUDIT-TEST',
        ]);

        $app = SectorApplication::first();

        // Check submission audit record in raw DB (to bypass any global scopes on AuditLog)
        $auditLog = DB::table('audit_logs')
            ->where('event', 'sector_application.submitted')
            ->where('entity_id', $app->id)
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertSame($this->barangayA->id, (int) $auditLog->barangay_id);
        $this->assertSame($this->seniorStaffA->id, (int) $auditLog->actor_id);

        $context = json_decode($auditLog->context, true);
        $this->assertSame('submitted', $context['action']);
        $this->assertSame('senior', $context['department']);
        $this->assertSame('pending_staff_review', $context['new_status']);
    }

    public function test_existing_general_resident_registration_approval_regression(): void
    {
        $pendingReg = $this->createPendingRegistration($this->barangayA, $this->purokA, [
            'first_name' => 'General',
            'last_name' => 'Applicant',
            'mobile' => '09991112233',
            'email' => 'general.applicant@example.test',
        ]);

        $candidate = $this->createResident($this->barangayA, $this->purokA, [
            'first_name' => 'General',
            'last_name' => 'Applicant',
            'mobile' => '09991112234',
        ]);
        $candidate->account()->delete();
        $candidate->update(['source' => 'admin', 'email' => $pendingReg->email]);
        $originalRegistrationId = $candidate->resident_registration_id;
        $residentCount = Resident::query()->count();

        $response = $this->actingAs($this->adminA)->post("/admin/residents/{$pendingReg->id}/approve", [
            'resident_id' => $candidate->id,
        ]);
        $response->assertRedirect()->assertSessionHasNoErrors();

        $pendingReg->refresh();
        $this->assertSame('approved', $pendingReg->status);
        $this->assertSame($candidate->id, $pendingReg->matched_resident_id);
        $this->assertSame($this->adminA->id, $pendingReg->matched_by);
        $this->assertNotNull($pendingReg->reviewed_at);
        $this->assertSame($residentCount, Resident::query()->count());

        $this->assertDatabaseHas('residents', [
            'id' => $candidate->id,
            'resident_registration_id' => $originalRegistrationId,
            'barangay_id' => $this->barangayA->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('resident_accounts', [
            'resident_id' => $candidate->id,
            'is_active' => true,
        ]);
    }

    public function test_qr_lookup_backward_compatibility(): void
    {
        $token = 'TEST-QR-TOKEN-'.Str::random(10);
        ResidentQrCredential::create([
            'resident_id' => $this->residentA->id,
            'token' => $token,
            'is_active' => true,
            'issued_at' => now(),
        ]);

        $this->residentA->sectors()->sync([$this->seniorSector->id]);

        $response = $this->actingAs($this->seniorStaffA)->getJson("/api/qr/verify-token?token={$token}");
        $response->assertOk();

        $response->assertJsonStructure([
            'resident' => [
                'id',
                'full_name',
                'resident_number',
                'sectors',
            ],
            'qr_proof',
        ]);

        $this->assertSame($this->residentA->id, $response->json('resident.id'));
        $this->assertSame($this->residentA->full_name, $response->json('resident.full_name'));
        $this->assertContains('senior', $response->json('resident.sectors'));
    }
}
