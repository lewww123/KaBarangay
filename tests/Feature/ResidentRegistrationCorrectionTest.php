<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Purok;
use App\Models\RegistrationDocument;
use App\Models\ResidentAccount;
use App\Models\ResidentRegistration;
use App\Models\ResidentRegistrationCorrectionCycle;
use App\Models\Sector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class ResidentRegistrationCorrectionTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Barangay $otherBarangay;

    private Purok $purok;

    private Purok $otherPurok;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        $this->barangay = $this->createBarangay('Correction Barangay', 'CORRECTION-ONE');
        $this->otherBarangay = $this->createBarangay('Other Barangay', 'CORRECTION-TWO');
        $this->purok = $this->createPurok($this->barangay, 'Purok 1');
        $this->otherPurok = $this->createPurok($this->otherBarangay, 'Purok 1');
    }

    public function test_admin_request_records_one_reviewed_cycle_and_moves_only_pending_application_to_correction(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000601',
        ]);
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post('/admin/residents/'.$registration->id.'/correction', [
                'instructions' => 'Please correct the applicant name.',
                'field_targets' => ['first_name'],
            ])
            ->assertRedirect();

        $registration->refresh();
        $cycle = ResidentRegistrationCorrectionCycle::query()->where('resident_registration_id', $registration->id)->firstOrFail();
        $this->assertSame('needs_correction', $registration->status);
        $this->assertSame(1, $cycle->cycle_number);
        $this->assertSame('Please correct the applicant name.', $cycle->instructions);
        $this->assertSame(['first_name'], $cycle->field_targets);
        $this->assertSame([], $cycle->document_targets);
        $this->assertSame('Pending', $cycle->prior_field_snapshot['first_name']);
        $this->assertSame($admin->id, $cycle->reviewer_id);
        $this->assertNull($cycle->resubmitted_at);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'resident_registration.correction_requested',
            'entity_id' => $registration->id,
        ]);

        $this->actingAs($admin)
            ->post('/admin/residents/'.$registration->id.'/correction', [
                'instructions' => 'Duplicate request.',
                'field_targets' => ['last_name'],
            ])
            ->assertStatus(422);
        $this->assertSame(1, ResidentRegistrationCorrectionCycle::query()
            ->where('resident_registration_id', $registration->id)
            ->count());
    }

    public function test_admin_correction_request_is_barangay_scoped_authorized_and_rejects_unapproved_targets(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000602',
        ]);
        $otherAdmin = $this->createStaff($this->otherBarangay, 'admin');
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $payload = ['instructions' => 'Please correct the name.', 'field_targets' => ['first_name']];
        $this->actingAs($otherAdmin)->post('/admin/residents/'.$registration->id.'/correction', $payload)->assertNotFound();
        $this->actingAs($clerk)->post('/admin/residents/'.$registration->id.'/correction', $payload)->assertForbidden();
        $this->actingAs($this->createStaff($this->barangay, 'admin'))
            ->post('/admin/residents/'.$registration->id.'/correction', [
                'instructions' => 'Edit anything.',
                'field_targets' => ['sectors'],
            ])
            ->assertSessionHasErrors('field_targets.0');

        $registration->update(['status' => 'rejected']);
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->actingAs($admin)->post('/admin/residents/'.$registration->id.'/correction', $payload)->assertStatus(422);
        $this->assertSame(0, ResidentRegistrationCorrectionCycle::query()->count());
    }

    public function test_admin_must_provide_instructions_and_at_least_one_valid_target(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000603',
        ]);
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post('/admin/residents/'.$registration->id.'/correction', [
                'instructions' => '   ',
                'field_targets' => [],
                'document_targets' => [],
            ])
            ->assertSessionHasErrors(['instructions', 'field_targets']);

        $this->assertSame('pending', $registration->fresh()->status);
        $this->assertSame(0, ResidentRegistrationCorrectionCycle::query()->count());
    }

    public function test_applicant_can_correct_every_approved_field_and_resubmit_same_application(): void
    {
        Mail::fake();
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'first_name' => 'Pending',
            'middle_name' => null,
            'last_name' => 'Applicant',
            'email' => 'old-address@example.test',
            'mobile' => '09980000604',
        ]);
        $registration->forceFill(['email' => 'old-address@example.test'])->save();
        $registration->forceFill(['email_verified_at' => now()])->save();
        $admin = $this->createStaff($this->barangay, 'admin');
        $newPurok = $this->createPurok($this->barangay, 'Purok 2');
        $this->requestCorrection($admin, $registration, [
            'instructions' => 'Please review and correct the information listed.',
            'field_targets' => [
                'first_name', 'middle_name', 'last_name', 'suffix', 'birthdate',
                'sex', 'civil_status', 'address_line', 'mobile', 'email', 'purok_id',
            ],
        ]);

        $this->loginApplicant($registration);
        $this->get('/resident/application/'.$registration->public_id)
            ->assertInertia(fn ($page) => $page
                ->where('application.correction.instructions', 'Please review and correct the information listed.')
                ->has('application.correction.field_targets', 11)
                ->missing('application.review_notes')
                ->missing('application.correction.prior_field_snapshot'));

        $this->post('/resident/application/'.$registration->public_id.'/correction', [
            'fields' => [
                'first_name' => 'Updated',
                'middle_name' => 'Maria',
                'last_name' => 'Applicant',
                'suffix' => 'Jr.',
                'birthdate' => '1994-03-12',
                'sex' => 'Female',
                'civil_status' => 'Married',
                'address_line' => 'Updated address',
                'mobile' => '09980000614',
                'email' => 'new-address@example.test',
                'purok_id' => (string) $newPurok->id,
            ],
        ])->assertRedirect();

        $registration->refresh();
        $cycle = ResidentRegistrationCorrectionCycle::query()->where('resident_registration_id', $registration->id)->firstOrFail();
        $this->assertSame('pending', $registration->status);
        $this->assertSame('Updated', $registration->first_name);
        $this->assertSame($newPurok->id, $registration->purok_id);
        $this->assertNotNull($registration->last_resubmitted_at);
        $this->assertNotNull($cycle->resubmitted_at);
        $this->assertSame('Pending', $cycle->prior_field_snapshot['first_name']);
        $this->assertNull($registration->email_verified_at);
        $this->assertDatabaseCount('resident_registration_email_verification_tokens', 0);
        $this->assertNull($registration->fresh()->mobile_verified_at);
        Mail::assertSent(\App\Mail\ResidentRegistrationDecisionMail::class);
        Mail::assertSentCount(1);
        $this->assertSame(1, ResidentAccount::query()->count());
        $this->assertDatabaseMissing('residents', ['resident_registration_id' => $registration->id]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'resident_registration.resubmitted',
            'entity_id' => $registration->id,
        ]);

        $this->post('/resident/application/'.$registration->public_id.'/correction', [
            'fields' => ['first_name' => 'Another attempt'],
        ])->assertStatus(422);
        $this->assertSame('Updated', $registration->fresh()->first_name);
    }

    public function test_applicant_cannot_submit_another_applications_targets_or_protected_fields(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000605',
        ]);
        $other = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000615',
        ]);
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->requestCorrection($admin, $registration, [
            'instructions' => 'Correct the name.',
            'field_targets' => ['first_name'],
        ]);
        $this->loginApplicant($registration);

        $this->get('/resident/application/'.$other->public_id)->assertNotFound();
        $this->post('/resident/application/'.$registration->public_id.'/correction', [
            'fields' => [
                'first_name' => 'Changed',
                'status' => 'approved',
                'password' => 'attacker-value',
                'barangay_id' => $this->otherBarangay->id,
                'matched_resident_id' => 123,
                'qr_credential_id' => 456,
            ],
        ])->assertSessionHasErrors('fields');

        $registration->refresh();
        $this->assertSame('needs_correction', $registration->status);
        $this->assertSame($this->barangay->id, $registration->barangay_id);
        $this->assertSame('Pending', $registration->first_name);
        $this->assertNull($registration->matched_resident_id);
    }

    public function test_applicant_cannot_resubmit_a_different_cycle_or_a_non_correction_application(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000606',
        ]);
        $this->loginApplicant($registration);
        $this->post('/resident/application/'.$registration->public_id.'/correction', [
            'fields' => ['first_name' => 'Changed'],
        ])->assertStatus(422);

        $registration->update(['status' => 'needs_correction']);
        $this->post('/resident/application/'.$registration->public_id.'/correction', [
            'fields' => ['first_name' => 'Changed'],
        ])->assertStatus(422);
        $this->assertSame('needs_correction', $registration->fresh()->status);
    }

    public function test_applicant_purok_correction_cannot_cross_barangay(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000607',
        ]);
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->requestCorrection($admin, $registration, [
            'instructions' => 'Select the correct purok.',
            'field_targets' => ['purok_id'],
        ]);
        $this->loginApplicant($registration);

        $this->post('/resident/application/'.$registration->public_id.'/correction', [
            'fields' => ['purok_id' => (string) $this->otherPurok->id],
        ])->assertSessionHasErrors('fields.purok_id');

        $this->assertSame('needs_correction', $registration->fresh()->status);
        $this->assertSame($this->purok->id, $registration->fresh()->purok_id);
    }

    public function test_applicant_replaces_all_supported_documents_without_deleting_prior_versions(): void
    {
        $senior = Sector::create(['name' => 'Senior Citizen', 'slug' => 'senior', 'requires_proof' => true, 'is_active' => true]);
        $pwd = Sector::create(['name' => 'Person with Disability', 'slug' => 'pwd', 'requires_proof' => true, 'is_active' => true]);
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000608',
        ]);
        $registration->sectors()->createMany([
            ['sector_id' => $senior->id, 'verification_status' => 'pending'],
            ['sector_id' => $pwd->id, 'verification_status' => 'pending'],
        ]);

        $priorDocuments = [
            'valid_id' => $this->createDocument($registration, 'valid_id', null, 'old-id.jpg'),
            'live_identity_photo' => $this->createDocument($registration, 'live_identity_photo', null, 'old-photo.jpg'),
            'senior_proof' => $this->createDocument($registration, 'sector_proof', $senior->id, 'old-senior.jpg'),
            'pwd_proof' => $this->createDocument($registration, 'sector_proof', $pwd->id, 'old-pwd.jpg'),
        ];
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->requestCorrection($admin, $registration, [
            'instructions' => 'Replace the documents selected below.',
            'document_targets' => array_keys($priorDocuments),
        ]);
        $this->loginApplicant($registration);

        $this->post('/resident/application/'.$registration->public_id.'/correction', [
            'fields' => [],
            'documents' => [
                'valid_id' => $this->imageUpload('new-id.jpg'),
                'live_identity_photo' => $this->imageUpload('new-photo.png'),
                'senior_proof' => UploadedFile::fake()->create('new-senior.pdf', 100, 'application/pdf'),
                'pwd_proof' => $this->imageUpload('new-pwd.jpg'),
            ],
        ])->assertRedirect();

        foreach ($priorDocuments as $target => $prior) {
            $prior->refresh();
            $this->assertNotNull($prior->superseded_at);
            Storage::disk('local')->assertExists($prior->path);

            $replacement = RegistrationDocument::query()
                ->where('resident_registration_id', $registration->id)
                ->where('supersedes_document_id', $prior->id)
                ->firstOrFail();
            $this->assertSame($prior->document_type, $replacement->document_type);
            $this->assertSame($prior->sector_id, $replacement->sector_id);
            $this->assertNull($replacement->superseded_at);
            $this->assertSame('pending', $replacement->verification_status);
            Storage::disk('local')->assertExists($replacement->path);
        }

        $this->assertSame('pending', $registration->fresh()->status);
        $this->assertSame(8, RegistrationDocument::query()
            ->where('resident_registration_id', $registration->id)
            ->count());
    }

    public function test_invalid_replacement_file_keeps_application_and_original_document_unchanged(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000609',
        ]);
        $prior = $this->createDocument($registration, 'live_identity_photo', null, 'photo.jpg');
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->requestCorrection($admin, $registration, [
            'instructions' => 'Replace the live photo.',
            'document_targets' => ['live_identity_photo'],
        ]);
        $this->loginApplicant($registration);

        $this->post('/resident/application/'.$registration->public_id.'/correction', [
            'fields' => [],
            'documents' => [
                'live_identity_photo' => UploadedFile::fake()->create('not-an-image.pdf', 40, 'application/pdf'),
            ],
        ])->assertSessionHasErrors('documents.live_identity_photo');

        $this->assertSame('needs_correction', $registration->fresh()->status);
        $this->assertNull($prior->fresh()->superseded_at);
        $this->assertSame(1, RegistrationDocument::query()
            ->where('resident_registration_id', $registration->id)
            ->count());
    }

    public function test_database_failure_during_resubmission_rolls_back_state_and_cleans_only_new_uploads(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000610',
        ]);
        $prior = $this->createDocument($registration, 'valid_id', null, 'old-id.jpg');
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->requestCorrection($admin, $registration, [
            'instructions' => 'Replace the ID.',
            'document_targets' => ['valid_id'],
        ]);
        $this->loginApplicant($registration);

        DB::connection()->beforeExecuting(function (string $query): void {
            if (str_contains(strtolower($query), 'update "resident_registration_correction_cycles"')) {
                throw new \RuntimeException('Synthetic transaction failure.');
            }
        });

        $this->post('/resident/application/'.$registration->public_id.'/correction', [
            'fields' => [],
            'documents' => ['valid_id' => $this->imageUpload('replacement.jpg')],
        ])->assertSessionHasErrors('correction');

        $this->assertSame('needs_correction', $registration->fresh()->status);
        $this->assertNull($prior->fresh()->superseded_at);
        $this->assertNull(ResidentRegistrationCorrectionCycle::query()
            ->where('resident_registration_id', $registration->id)
            ->firstOrFail()->resubmitted_at);
        $this->assertSame(1, RegistrationDocument::query()
            ->where('resident_registration_id', $registration->id)
            ->count());
        Storage::disk('local')->assertExists($prior->path);
        $this->assertCount(1, Storage::disk('local')->allFiles('resident-verifications/'.$registration->public_id));
    }

    private function requestCorrection($admin, ResidentRegistration $registration, array $payload): void
    {
        $this->actingAs($admin)
            ->post('/admin/residents/'.$registration->id.'/correction', $payload)
            ->assertRedirect();
    }

    private function loginApplicant(ResidentRegistration $registration): void
    {
        $this->post('/resident/application/access', [
            'reference' => $registration->public_id,
            'password' => 'password',
        ])->assertRedirect();
    }

    private function createDocument(
        ResidentRegistration $registration,
        string $type,
        ?int $sectorId,
        string $filename
    ): RegistrationDocument {
        $file = $this->imageUpload($filename);
        $path = $file->store('resident-verifications/'.$registration->public_id, 'local');

        return RegistrationDocument::create([
            'resident_registration_id' => $registration->id,
            'sector_id' => $sectorId,
            'document_type' => $type,
            'disk' => 'local',
            'path' => $path,
            'original_name' => $filename,
            'mime_type' => 'image/png',
            'file_size' => 100,
            'sha256' => hash('sha256', $filename),
            'verification_status' => 'pending',
        ]);
    }

    private function imageUpload(string $filename): UploadedFile
    {
        // Real PNG bytes exercise upload validation without requiring GD merely
        // to generate a test fixture; production image validation is unchanged.
        return UploadedFile::fake()->createWithContent($filename, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        ));
    }
}
