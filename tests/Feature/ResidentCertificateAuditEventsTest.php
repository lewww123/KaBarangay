<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Certificate;
use App\Models\CertificateRequest;
use App\Models\Purok;
use App\Models\ResidentRegistration;
use App\Models\User;
use App\Support\CertificateRequestDetails;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

/**
 * Batch A audit coverage: resident registration/status and certificate
 * request events.
 *
 * These tests exercise the AuditLogger call sites added for
 * resident_registration.*, resident.* and certificate_request.* events.
 * They are definitions only; the suite is not executed in this batch.
 */
class ResidentCertificateAuditEventsTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Barangay $other;

    private Purok $purok;

    private Certificate $certificate;

    /**
     * Minimal but structurally valid 1x1 PNG. GD is not guaranteed in this
     * environment, so image uploads are built from real bytes instead of
     * UploadedFile::fake()->image().
     */
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $this->other = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $this->purok = $this->createPurok($this->barangay, 'Purok 1');

        $this->certificate = Certificate::create([
            'barangay_id' => $this->barangay->id,
            'code' => 'BRGY-INDIGENCY',
            'name' => 'Certificate of Indigency',
            'fee' => 50,
            'is_active' => true,
        ]);
    }

    private function portalAdmin(): User
    {
        return $this->createStaff($this->barangay, 'admin', [
            'name' => 'Portal Admin',
            'email' => 'portal.admin@example.test',
        ]);
    }

    private function portalClerk(): User
    {
        return $this->createStaff($this->barangay, 'clerk', [
            'name' => 'Portal Clerk',
            'email' => 'portal.clerk@example.test',
        ]);
    }

    /**
     * @return Collection<int, object>
     */
    private function rows(): Collection
    {
        return DB::table('audit_logs')->orderBy('id')->get();
    }

    private function pngUpload(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            base64_decode(self::PNG_1X1, true)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | resident_registration.submitted (public self-registration)
    |--------------------------------------------------------------------------
    */

    public function test_public_registration_records_one_submitted_event_without_actor_and_without_pii(): void
    {
        Storage::fake('local');

        $payload = [
            'barangay_id' => $this->barangay->id,
            'purok_id' => $this->purok->id,
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'birthdate' => '1995-05-05',
            'sex' => 'Female',
            'civil_status' => 'Single',
            'address_line' => '456 Test Street',
            'mobile' => '09981112222',
            'email' => 'ana.reyes@example.test',
            'password' => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1',
            'valid_id' => $this->pngUpload('valid_id.png'),
            'live_identity_photo' => $this->pngUpload('selfie.png'),
        ];

        $this->verifyRegistrationEmailForSubmission($payload['email']);
        $this->post('/resident/register', $payload)->assertRedirect();

        $registration = ResidentRegistration::query()
            ->where('mobile', '09981112222')
            ->firstOrFail();

        $rows = $this->rows()->where('event', 'resident_registration.submitted')->values();

        $this->assertCount(1, $rows);

        $row = $rows->first();

        $this->assertSame('resident_registration.submitted', $row->event);
        $this->assertNull($row->actor_id);
        $this->assertSame($this->barangay->id, (int) $row->barangay_id);
        $this->assertSame('resident_registration', $row->entity_type);
        $this->assertSame($registration->id, (int) $row->entity_id);
        $this->assertNull($row->context);
        $this->assertSame('127.0.0.1', $row->ip_address);

        $serialized = json_encode((array) $row);

        foreach ([
            'ana.reyes@example.test',
            '09981112222',
            'Ana',
            'Reyes',
            '456 Test Street',
            'secret-pass-1',
            '"password"',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
    }

    public function test_public_registration_validation_failure_records_nothing(): void
    {
        Storage::fake('local');

        $this->post('/resident/register', [
            'barangay_id' => $this->barangay->id,
            'purok_id' => $this->purok->id,
            'first_name' => 'No',
            'last_name' => 'Files',
            'birthdate' => '1995-05-05',
            'sex' => 'Female',
            'civil_status' => 'Single',
            'address_line' => '789 Test Street',
            'mobile' => 'invalid',
            'password' => 'short',
        ])->assertSessionHasErrors(['mobile', 'password', 'valid_id', 'live_identity_photo']);

        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    /*
    |--------------------------------------------------------------------------
    | resident_registration.approved / rejected (admin review)
    |--------------------------------------------------------------------------
    */

    public function test_approval_records_one_event_with_actor_barangay_entity_and_transition(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000011',
            'email' => 'approved@example.test',
        ]);
        $candidate = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170000011']);
        $candidate->account()->delete();
        $candidate->update(['source' => 'admin', 'email' => $registration->email]);

        $admin = $this->portalAdmin();

        $this->actingAs($admin)
            ->post('/admin/residents/'.$registration->id.'/approve', ['resident_id' => $candidate->id])
            ->assertRedirect()->assertSessionHasNoErrors();

        $rows = $this->rows()->where('event', 'resident_registration.approved')->values();

        $this->assertCount(1, $rows);

        $row = $rows->first();

        $this->assertSame('resident_registration.approved', $row->event);
        $this->assertSame($admin->id, (int) $row->actor_id);
        $this->assertSame($this->barangay->id, (int) $row->barangay_id);
        $this->assertSame('resident_registration', $row->entity_type);
        $this->assertSame($registration->id, (int) $row->entity_id);
        $this->assertSame(
            ['previous_status' => 'pending', 'new_status' => 'approved'],
            json_decode($row->context, true)
        );
        $this->assertSame('127.0.0.1', $row->ip_address);
        $this->assertSame('approved', $registration->refresh()->status);
    }

    public function test_approval_from_needs_correction_is_blocked_without_a_false_approval_event(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000012',
        ]);
        $registration->update(['status' => 'needs_correction']);

        $admin = $this->portalAdmin();

        $this->actingAs($admin)
            ->post('/admin/residents/'.$registration->id.'/approve')
            ->assertUnprocessable();

        $rows = $this->rows();

        $this->assertCount(0, $rows);
        $this->assertSame('needs_correction', $registration->fresh()->status);
    }

    public function test_rejection_records_one_event_and_never_records_the_reason(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000013',
        ]);

        $admin = $this->portalAdmin();

        $reason = 'Submitted identification is unreadable.';

        $this->actingAs($admin)
            ->post('/admin/residents/'.$registration->id.'/reject', [
                'reason' => $reason,
            ])
            ->assertRedirect();

        $rows = $this->rows();

        $this->assertCount(1, $rows);

        $row = $rows->first();

        $this->assertSame('resident_registration.rejected', $row->event);
        $this->assertSame($admin->id, (int) $row->actor_id);
        $this->assertSame($this->barangay->id, (int) $row->barangay_id);
        $this->assertSame('resident_registration', $row->entity_type);
        $this->assertSame($registration->id, (int) $row->entity_id);
        $this->assertSame(
            ['previous_status' => 'pending', 'new_status' => 'rejected'],
            json_decode($row->context, true)
        );
        $this->assertStringNotContainsString($reason, json_encode((array) $row));
        $this->assertSame('rejected', $registration->refresh()->status);
    }

    public function test_cross_barangay_registration_approval_records_nothing(): void
    {
        $otherPurok = $this->createPurok($this->other, 'Purok 2');
        $foreign = $this->createPendingRegistration($this->other, $otherPurok, [
            'mobile' => '09980000014',
        ]);

        $admin = $this->portalAdmin();

        $this->actingAs($admin)
            ->post('/admin/residents/'.$foreign->id.'/approve')
            ->assertStatus(404);

        $this->assertSame(0, DB::table('audit_logs')->count());
        $this->assertSame('pending', $foreign->refresh()->status);
    }

    public function test_non_reviewable_registration_approval_records_nothing(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000015',
        ]);
        $registration->update(['status' => 'approved']);

        $admin = $this->portalAdmin();

        $this->actingAs($admin)
            ->post('/admin/residents/'.$registration->id.'/approve')
            ->assertStatus(422);

        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    /*
    |--------------------------------------------------------------------------
    | resident.status_changed
    |--------------------------------------------------------------------------
    */

    public function test_resident_status_transitions_each_record_one_event(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09171110001',
        ]);

        $admin = $this->portalAdmin();

        $this->actingAs($admin)
            ->patch('/admin/residents/'.$resident->id.'/status', ['status' => 'inactive'])
            ->assertRedirect();

        $this->actingAs($admin)
            ->patch('/admin/residents/'.$resident->id.'/status', ['status' => 'active'])
            ->assertRedirect();

        $rows = $this->rows();

        $this->assertCount(2, $rows);

        $this->assertSame('resident.status_changed', $rows[0]->event);
        $this->assertSame($admin->id, (int) $rows[0]->actor_id);
        $this->assertSame($this->barangay->id, (int) $rows[0]->barangay_id);
        $this->assertSame('resident', $rows[0]->entity_type);
        $this->assertSame($resident->id, (int) $rows[0]->entity_id);
        $this->assertSame(
            ['previous_status' => 'active', 'new_status' => 'inactive'],
            json_decode($rows[0]->context, true)
        );
        $this->assertSame(
            ['previous_status' => 'inactive', 'new_status' => 'active'],
            json_decode($rows[1]->context, true)
        );
    }

    public function test_no_op_resident_status_change_records_nothing(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09171110002',
        ]);

        $admin = $this->portalAdmin();

        $this->actingAs($admin)
            ->patch('/admin/residents/'.$resident->id.'/status', ['status' => 'active'])
            ->assertRedirect();

        $this->assertSame(0, DB::table('audit_logs')->count());
        $this->assertSame('active', $resident->refresh()->status);
    }

    /*
    |--------------------------------------------------------------------------
    | resident.account_status_changed
    |--------------------------------------------------------------------------
    */

    public function test_resident_account_status_transitions_each_record_one_event(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09171110003',
        ]);

        $admin = $this->portalAdmin();

        $this->actingAs($admin)
            ->patch('/admin/residents/'.$resident->id.'/account-status', ['is_active' => false])
            ->assertRedirect();

        $this->actingAs($admin)
            ->patch('/admin/residents/'.$resident->id.'/account-status', ['is_active' => true])
            ->assertRedirect();

        $rows = $this->rows();

        $this->assertCount(2, $rows);

        $this->assertSame('resident.account_status_changed', $rows[0]->event);
        $this->assertSame($admin->id, (int) $rows[0]->actor_id);
        $this->assertSame($this->barangay->id, (int) $rows[0]->barangay_id);
        $this->assertSame('resident', $rows[0]->entity_type);
        $this->assertSame($resident->id, (int) $rows[0]->entity_id);
        $this->assertSame(
            ['previous_status' => 'active', 'new_status' => 'inactive'],
            json_decode($rows[0]->context, true)
        );
        $this->assertSame(
            ['previous_status' => 'inactive', 'new_status' => 'active'],
            json_decode($rows[1]->context, true)
        );
    }

    public function test_no_op_resident_account_status_change_records_nothing(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09171110004',
        ]);

        $admin = $this->portalAdmin();

        $this->actingAs($admin)
            ->patch('/admin/residents/'.$resident->id.'/account-status', ['is_active' => true])
            ->assertRedirect();

        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    /*
    |--------------------------------------------------------------------------
    | certificate_request.created (resident submission)
    |--------------------------------------------------------------------------
    */

    public function test_resident_certificate_submission_records_one_created_event_without_actor(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09171110005',
        ]);

        $this->withSession(['resident_id' => $resident->id])
            ->post('/resident/certificates', [
                'certificate_id' => $this->certificate->id,
                'purpose' => 'Employment abroad',
                'business_name' => 'Secret Business Name',
                'business_address' => '42 Hidden Avenue',
            ])
            ->assertRedirect();

        $request = CertificateRequest::query()
            ->where('resident_id', $resident->id)
            ->firstOrFail();

        $rows = $this->rows();

        $this->assertCount(1, $rows);

        $row = $rows->first();

        $this->assertSame('certificate_request.created', $row->event);
        $this->assertNull($row->actor_id);
        $this->assertSame($this->barangay->id, (int) $row->barangay_id);
        $this->assertSame('certificate_request', $row->entity_type);
        $this->assertSame($request->id, (int) $row->entity_id);
        $this->assertSame(
            [
                'entity_reference' => $request->reference,
                'new_status' => 'Pending',
            ],
            json_decode($row->context, true)
        );
        $this->assertSame('127.0.0.1', $row->ip_address);

        $serialized = json_encode((array) $row);

        foreach ([
            'Employment abroad',
            'Secret Business Name',
            '42 Hidden Avenue',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
    }

    public function test_resident_certificate_submission_validation_failure_records_nothing(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09171110006',
        ]);

        $this->withSession(['resident_id' => $resident->id])
            ->post('/resident/certificates', [
                'certificate_id' => 999999,
                'purpose' => 'Employment abroad',
            ])
            ->assertSessionHasErrors('certificate_id');

        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    /*
    |--------------------------------------------------------------------------
    | certificate_request.status_changed (Clerk review)
    |--------------------------------------------------------------------------
    */

    public function test_clerk_certificate_status_change_records_one_event_with_transition(): void
    {
        $request = $this->createCertificateRequest('REF-STATUS-001');
        $clerk = $this->portalClerk();

        $this->actingAs($clerk)
            ->patchJson('/clerk/certificates/requests/'.$request->id.'/status', [
                'status' => 'Processing',
            ])
            ->assertOk();

        $rows = $this->rows();

        $this->assertCount(1, $rows);

        $row = $rows->first();

        $this->assertSame('certificate_request.status_changed', $row->event);
        $this->assertSame($clerk->id, (int) $row->actor_id);
        $this->assertSame($this->barangay->id, (int) $row->barangay_id);
        $this->assertSame('certificate_request', $row->entity_type);
        $this->assertSame($request->id, (int) $row->entity_id);
        $this->assertSame(
            ['previous_status' => 'Pending', 'new_status' => 'Processing'],
            json_decode($row->context, true)
        );
        $this->assertSame('127.0.0.1', $row->ip_address);
    }

    public function test_clerk_reapplication_of_current_status_records_nothing(): void
    {
        $request = $this->createCertificateRequest('REF-NOOP-001');
        $clerk = $this->portalClerk();

        $this->actingAs($clerk)
            ->patchJson('/clerk/certificates/requests/'.$request->id.'/status', [
                'status' => 'Pending',
                'admin_note' => 'Waiting for the resident.',
            ])
            ->assertOk();

        $this->assertSame(0, DB::table('audit_logs')->count());
        $this->assertSame('Waiting for the resident.', $request->refresh()->admin_note);
    }

    public function test_clerk_invalid_transition_records_nothing(): void
    {
        $request = $this->createCertificateRequest('REF-JUMP-001');
        $clerk = $this->portalClerk();

        $this->actingAs($clerk)
            ->patchJson('/clerk/certificates/requests/'.$request->id.'/status', [
                'status' => 'Released',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame(0, DB::table('audit_logs')->count());
        $this->assertSame('Pending', $request->refresh()->status);
    }

    public function test_cross_barangay_certificate_status_change_records_nothing(): void
    {
        $otherPurok = $this->createPurok($this->other, 'Purok 2');
        $otherCertificate = Certificate::create([
            'barangay_id' => $this->other->id,
            'code' => 'BRGY-INDIGENCY',
            'name' => 'Certificate of Indigency',
            'fee' => 50,
            'is_active' => true,
        ]);
        $otherResident = $this->createResident($this->other, $otherPurok, [
            'mobile' => '09172220001',
        ]);
        $foreign = CertificateRequest::create([
            'barangay_id' => $this->other->id,
            'resident_id' => $otherResident->id,
            'certificate_id' => $otherCertificate->id,
            'reference' => 'REF-FOREIGN-001',
            'purpose' => 'Employment',
            'fee' => 50,
            'status' => 'Pending',
            'submitted_at' => now(),
        ]);

        $clerk = $this->portalClerk();

        $this->actingAs($clerk)
            ->patchJson('/clerk/certificates/requests/'.$foreign->id.'/status', [
                'status' => 'Processing',
            ])
            ->assertNotFound();

        $this->assertSame(0, DB::table('audit_logs')->count());
        $this->assertSame('Pending', $foreign->refresh()->status);
    }

    public function test_clerk_detail_revision_records_one_bounded_event(): void
    {
        $residency = Certificate::create([
            'barangay_id' => $this->barangay->id,
            'code' => 'residency',
            'name' => 'Certificate of Residency',
            'fee' => 50,
            'is_active' => true,
        ]);

        $request = $this->createCertificateRequest('REF-DETAIL-001');
        $request->update([
            'certificate_id' => $residency->id,
            'additional_details' => CertificateRequestDetails::encode(
                'Residency',
                ['years_of_residency' => '5'],
                'Resident note',
            ),
        ]);

        $clerk = $this->portalClerk();

        $this->actingAs($clerk)
            ->patchJson('/clerk/certificates/requests/'.$request->id, [
                'details' => ['years_of_residency' => '7'],
            ])
            ->assertOk();

        $rows = $this->rows();

        $this->assertCount(1, $rows);

        $row = $rows->first();

        $this->assertSame('certificate_request.details_reviewed', $row->event);
        $this->assertSame($clerk->id, (int) $row->actor_id);
        $this->assertSame($this->barangay->id, (int) $row->barangay_id);
        $this->assertSame('certificate_request', $row->entity_type);
        $this->assertSame($request->id, (int) $row->entity_id);
        $this->assertSame(
            ['action' => 'details_reviewed'],
            json_decode($row->context, true),
        );

        $decoded = CertificateRequestDetails::decode(
            $request->refresh()->additional_details,
        );

        $this->assertSame('7', $decoded['fields']['years_of_residency']);
        $this->assertSame('5', $decoded['original_fields']['years_of_residency']);
    }

    /*
    |--------------------------------------------------------------------------
    | Privacy: allowlisted context only, no identifiers or secrets
    |--------------------------------------------------------------------------
    */

    public function test_certificate_and_resident_contexts_contain_only_allowlisted_keys(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09171110007',
        ]);
        $certificateResident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09171110008',
        ]);
        $admin = $this->portalAdmin();

        $this->withSession(['resident_id' => $certificateResident->id])
            ->post('/resident/certificates', [
                'certificate_id' => $this->certificate->id,
                'purpose' => 'Local employment',
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->patch('/admin/residents/'.$resident->id.'/status', ['status' => 'inactive'])
            ->assertRedirect();

        $allowlist = [
            'action',
            'department',
            'entity_reference',
            'new_role',
            'new_status',
            'previous_role',
            'previous_status',
            'reason_code',
        ];

        $rows = $this->rows();

        $this->assertGreaterThanOrEqual(2, $rows->count());

        foreach ($rows as $row) {
            $context = $row->context === null ? null : json_decode($row->context, true);

            if ($context !== null) {
                $this->assertSame([], array_diff(array_keys($context), $allowlist));
            }
        }
    }

    /**
     * Build a certificate request owned by a resident of the primary barangay,
     * with a per-reference unique mobile to satisfy the (barangay_id, mobile)
     * unique index.
     */
    private function createCertificateRequest(string $reference): CertificateRequest
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '0917'.str_pad(
                (string) (abs(crc32($reference)) % 10000000),
                7,
                '0',
                STR_PAD_LEFT
            ),
        ]);

        return CertificateRequest::create([
            'barangay_id' => $this->barangay->id,
            'resident_id' => $resident->id,
            'certificate_id' => $this->certificate->id,
            'reference' => $reference,
            'purpose' => 'Employment',
            'fee' => 50,
            'status' => 'Pending',
            'submitted_at' => now(),
        ]);
    }
}
