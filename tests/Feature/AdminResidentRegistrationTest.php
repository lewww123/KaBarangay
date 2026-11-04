<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Purok;
use App\Models\RegistrationDocument;
use App\Models\Resident;
use App\Models\ResidentAccount;
use App\Models\ResidentQrCredential;
use App\Models\ResidentRegistration;
use App\Models\Sector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class AdminResidentRegistrationTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Barangay $other;

    private Purok $purok;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $this->other = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $this->purok = $this->createPurok($this->barangay, 'Purok 1');
    }

    /*
    |--------------------------------------------------------------------------
    | Index / Authorization
    |--------------------------------------------------------------------------
    */

    public function test_index_lists_only_own_barangay_pending_registrations(): void
    {
        $own = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000001',
        ]);

        $otherPurok = $this->createPurok($this->other, 'Purok 2');
        $this->createPendingRegistration($this->other, $otherPurok, [
            'mobile' => '09980000002',
        ]);

        // Permanent residents must also be scoped.
        $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170000001',
        ]);
        $this->createResident($this->other, $otherPurok, [
            'mobile' => '09170000002',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/residents')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Residents')
                ->has('pendingRegistrations.data', 1)
                ->where('pendingRegistrations.data.0.id', $own->id)
                ->where('stats.totalResidents', 2)
                ->where('stats.activeResidents', 2)
                ->where('stats.pendingRegistrations', 1));
    }

    public function test_new_application_appears_immediately_after_admin_loaded_an_empty_queue(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        // Exercise production caching: testing normally bypasses aggregates.
        $this->app['env'] = 'production';
        try {
            $this->actingAs($admin)->get('/admin/residents')->assertOk()
                ->assertInertia(fn ($page) => $page->where('pendingRegistrations.total', 0));

            $application = $this->createPendingRegistration($this->barangay, $this->purok);
            $this->createPendingRegistration($this->other, $this->createPurok($this->other), [
                'mobile' => '09980000002',
            ]);

            $this->get('/admin/residents')->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('pendingRegistrations.total', 1)
                    ->has('pendingRegistrations.data', 1)
                    ->where('pendingRegistrations.data.0.id', $application->id)
                    ->where('stats.pendingRegistrations', 1));

            config(['inertia.defer_heavy_props' => true]);
            $this->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => Inertia::getVersion(),
                'X-Inertia-Partial-Component' => 'Admin/Residents',
                'X-Inertia-Partial-Data' => 'pendingRegistrations,stats',
            ])->get('/admin/residents')->assertOk()
                ->assertJsonPath('props.pendingRegistrations.total', 1)
                ->assertJsonPath('props.pendingRegistrations.data.0.id', $application->id)
                ->assertJsonPath('props.stats.pendingRegistrations', 1)
                ->assertJsonMissingPath('props.allResidents');
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_all_residents_and_added_by_admin_use_separate_verified_account_and_source_rules(): void
    {
        $adminLinked = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Admin',
            'last_name' => 'Linked',
            'mobile' => '09170000011',
        ]);
        $adminLinked->update([
            'source' => 'admin',
            'email_verified_at' => now(),
        ]);

        $adminUnlinked = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Admin',
            'last_name' => 'Offline',
            'mobile' => '09170000012',
        ]);
        $adminUnlinked->update(['source' => 'admin']);
        $adminUnlinked->account()->delete();

        $verifiedRegistration = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Online',
            'last_name' => 'Linked',
            'mobile' => '09170000013',
        ]);
        $verifiedRegistration->update(['email_verified_at' => now()]);

        $otherPurok = $this->createPurok($this->other, 'Purok 2');
        $foreignResident = $this->createResident($this->other, $otherPurok, [
            'mobile' => '09170000014',
        ]);
        $foreignResident->update(['email_verified_at' => now()]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/residents')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Residents')
                ->where('allResidents.total', 3)
                ->where('allResidents.data.0.reference', $adminLinked->resident_number)
                ->where('allResidents.data.1.reference', $verifiedRegistration->resident_number)
                ->where('addedByAdmin.total', 2)
                ->where('addedByAdmin.data.0.id', $adminLinked->id)
                ->where('addedByAdmin.data.1.id', $adminUnlinked->id));
    }

    public function test_index_requires_admin_role(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->get('/admin/residents')
            ->assertStatus(403);
    }

    public function test_index_redirects_guests_to_login(): void
    {
        $this->get('/admin/residents')->assertRedirect(route('login'));
    }

    /*
    |--------------------------------------------------------------------------
    | Cross-barangay isolation (404)
    |--------------------------------------------------------------------------
    */

    public function test_cross_barangay_registration_cannot_be_approved(): void
    {
        $otherPurok = $this->createPurok($this->other, 'Purok 2');
        $registration = $this->createPendingRegistration($this->other, $otherPurok);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post('/admin/residents/'.$registration->id.'/approve')
            ->assertStatus(404);

        $this->assertDatabaseMissing('residents', [
            'resident_registration_id' => $registration->id,
        ]);
    }

    public function test_cross_barangay_registration_cannot_be_rejected(): void
    {
        $otherPurok = $this->createPurok($this->other, 'Purok 2');
        $registration = $this->createPendingRegistration($this->other, $otherPurok);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post('/admin/residents/'.$registration->id.'/reject', [
                'reason' => 'These documents cannot be verified at all.',
            ])
            ->assertStatus(404);

        $this->assertDatabaseHas('resident_registrations', [
            'id' => $registration->id,
            'status' => 'pending',
        ]);
    }

    public function test_cross_barangay_document_access_returns_404(): void
    {
        Storage::fake('local');

        $otherPurok = $this->createPurok($this->other, 'Purok 2');
        $registration = $this->createPendingRegistration($this->other, $otherPurok);
        $document = $this->createDocument($registration);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/residents/'.$registration->id.'/documents/'.$document->id)
            ->assertStatus(404);
    }

    /*
    |--------------------------------------------------------------------------
    | Document access
    |--------------------------------------------------------------------------
    */

    public function test_document_from_another_registration_returns_404(): void
    {
        Storage::fake('local');

        $registrationA = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000011',
        ]);
        $registrationB = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000012',
        ]);

        $documentB = $this->createDocument($registrationB);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/residents/'.$registrationA->id.'/documents/'.$documentB->id)
            ->assertStatus(404);
    }

    public function test_missing_document_file_returns_404(): void
    {
        Storage::fake('local');

        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000013',
        ]);

        $document = $this->createDocument($registration);

        $admin = $this->createStaff($this->barangay, 'admin');

        // The file was never written to the fake disk.
        $this->actingAs($admin)
            ->get('/admin/residents/'.$registration->id.'/documents/'.$document->id)
            ->assertStatus(404);
    }

    public function test_own_barangay_document_can_be_opened(): void
    {
        Storage::fake('local');

        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000014',
        ]);

        $path = 'resident-verifications/'.$registration->public_id.'/valid_id.jpg';
        Storage::disk('local')->put($path, 'image-bytes');

        $document = $this->createDocument($registration, ['path' => $path]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/residents/'.$registration->id.'/documents/'.$document->id)
            ->assertStatus(200);
    }

    public function test_private_document_survives_loss_of_local_storage(): void
    {
        Storage::fake('local');
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000015',
        ]);
        $path = 'resident-verifications/'.$registration->public_id.'/valid_id.jpg';
        Storage::disk('local')->put($path, 'private-image-bytes');
        $document = $this->createDocument($registration, ['path' => $path]);
        $this->assertDatabaseHas('registration_document_contents', ['registration_document_id' => $document->id]);
        $this->assertStringNotContainsString('private-image-bytes', DB::table('registration_document_contents')->value('encrypted_content'));
        Storage::disk('local')->delete($path);

        $admin = $this->createStaff($this->barangay, 'admin');
        $response = $this->actingAs($admin)
            ->get('/admin/residents/'.$registration->id.'/documents/'.$document->id)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame('private-image-bytes', $response->streamedContent());

        $otherAdmin = $this->createStaff($this->other, 'admin');
        $this->actingAs($otherAdmin)
            ->get('/admin/residents/'.$registration->id.'/documents/'.$document->id)
            ->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | Approval integrity
    |--------------------------------------------------------------------------
    */

    public function test_approval_rejects_non_reviewable_status(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000021',
        ]);
        $registration->update(['status' => 'rejected']);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post('/admin/residents/'.$registration->id.'/approve')
            ->assertStatus(422);

        $this->assertDatabaseMissing('residents', [
            'resident_registration_id' => $registration->id,
        ]);
    }

    public function test_admin_cannot_approve_or_reject_until_needs_correction_is_resubmitted(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000020',
        ]);
        $registration->update(['status' => 'needs_correction']);
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post('/admin/residents/'.$registration->id.'/approve', ['resident_id' => 1])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->post('/admin/residents/'.$registration->id.'/reject', ['reason' => 'Applicant must resubmit.'])
            ->assertStatus(422);

        $this->assertSame('needs_correction', $registration->fresh()->status);
        $this->assertDatabaseMissing('residents', ['resident_registration_id' => $registration->id]);
    }

    public function test_double_approval_is_prevented(): void
    {
        $candidate = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170000022',
        ]);
        $candidate->account()->delete();
        $candidate->update([
            'source' => 'admin',
            'email' => 'applicant@example.test',
            'status' => 'inactive',
        ]);
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000022',
            'email' => 'applicant@example.test',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post('/admin/residents/'.$registration->id.'/approve', [
                'resident_id' => $candidate->id,
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->post('/admin/residents/'.$registration->id.'/approve', [
                'resident_id' => $candidate->id,
            ])
            ->assertStatus(422);

        $this->assertSame(0, Resident::where('resident_registration_id', $registration->id)->count());
        $this->assertSame($candidate->id, $registration->fresh()->matched_resident_id);
        $this->assertSame('approved', $registration->fresh()->status);
        $this->assertSame('active', $candidate->fresh()->status);
        $this->assertSame(1, $candidate->account()->count());
    }

    public function test_approval_blocked_when_resident_already_exists(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000023',
        ]);

        // A permanent resident already points at this (still pending) registration.
        $existingResident = Resident::create([
            'public_id' => (string) Str::uuid(),
            'resident_registration_id' => $registration->id,
            'barangay_id' => $this->barangay->id,
            'purok_id' => $this->purok->id,
            'resident_number' => 'BRGYONE-RES-900001',
            'first_name' => $registration->first_name,
            'last_name' => $registration->last_name,
            'birthdate' => '1990-01-01',
            'sex' => 'Female',
            'civil_status' => 'Single',
            'address_line' => '123 Test Street',
            'mobile' => '09980000023',
            'status' => 'active',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post('/admin/residents/'.$registration->id.'/approve', ['resident_id' => $existingResident->id])
            ->assertSessionHasErrors('approval');

        $this->assertDatabaseHas('resident_registrations', [
            'id' => $registration->id,
            'status' => 'pending',
        ]);
    }

    public function test_missing_candidate_keeps_application_pending_without_creating_records(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000031',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post('/admin/residents/'.$registration->id.'/approve')
            ->assertSessionHasErrors('resident_id');

        $this->assertDatabaseMissing('residents', [
            'resident_registration_id' => $registration->id,
        ]);
        $this->assertSame(1, Resident::query()->count());
        $this->assertSame(1, ResidentAccount::query()->count());
        $this->assertSame('pending', $registration->fresh()->status);
    }

    public function test_approval_stays_pending_when_match_columns_are_not_deployed(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000032',
        ]);
        $admin = $this->createStaff($this->barangay, 'admin');

        Schema::shouldReceive('hasColumn')
            ->once()
            ->with('resident_registrations', 'matched_resident_id')
            ->andReturn(false);

        $this->actingAs($admin)
            ->post('/admin/residents/'.$registration->id.'/approve', [
                'resident_id' => 12345,
            ])
            ->assertSessionHasErrors('approval');

        $this->assertSame('pending', $registration->fresh()->status);
        $this->assertDatabaseMissing('resident_accounts', ['resident_id' => 12345]);
    }

    public function test_invalid_or_foreign_barangay_candidate_is_rejected(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000042',
        ]);
        $otherPurok = $this->createPurok($this->other, 'Purok 2');
        $foreignResident = $this->createResident($this->other, $otherPurok, [
            'mobile' => '09170000042',
        ]);
        $foreignResident->account()->delete();
        $foreignResident->update(['email' => 'applicant@example.test']);
        $registration->update(['email' => 'applicant@example.test']);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post('/admin/residents/'.$registration->id.'/approve', [
                'resident_id' => $foreignResident->id,
            ])
            ->assertSessionHasErrors('resident_id');

        $this->assertSame('pending', $registration->fresh()->status);
        $this->assertNull($registration->fresh()->matched_resident_id);
    }

    public function test_matching_candidate_search_is_limited_to_admin_barangay(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok);
        $own = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Possible', 'last_name' => 'Match', 'mobile' => '09170000061',
        ]);
        $own->update(['source' => 'admin']);
        $otherPurok = $this->createPurok($this->other, 'Purok 2');
        $foreign = $this->createResident($this->other, $otherPurok, [
            'first_name' => 'Possible', 'last_name' => 'Match', 'mobile' => '09170000062',
        ]);
        $foreign->update(['source' => 'admin']);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/resident-registrations/'.$registration->id.'/candidates?q=Possible Match')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id)
            ->assertJsonMissing(['id' => $foreign->id]);
    }

    public function test_matching_candidate_search_requires_admin_authorization(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok);
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->get('/admin/resident-registrations/'.$registration->id.'/candidates?q=Applicant')
            ->assertForbidden();
    }

    public function test_inactive_candidate_cannot_be_linked(): void
    {
        $candidate = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170000075',
        ]);
        $candidate->account()->delete();
        $candidate->update([
            'email' => 'applicant@example.test',
            'status' => 'inactive',
        ]);
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000075', 'email' => 'applicant@example.test',
        ]);
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post('/admin/residents/'.$registration->id.'/approve', ['resident_id' => $candidate->id])
            ->assertSessionHasErrors('resident_id');

        $this->assertSame('pending', $registration->fresh()->status);
        $this->assertSame(0, $candidate->account()->count());
    }

    public function test_existing_account_conflict_preserves_account_and_application(): void
    {
        $candidate = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170000071',
        ]);
        $candidate->update(['source' => 'admin', 'email' => 'same@example.test']);
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000071', 'email' => 'same@example.test',
        ]);
        $existingPassword = $candidate->account->password;
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post('/admin/residents/'.$registration->id.'/approve', ['resident_id' => $candidate->id])
            ->assertSessionHasErrors('resident_id');

        $this->assertSame($existingPassword, $candidate->account()->firstOrFail()->password);
        $this->assertSame('pending', $registration->fresh()->status);
        $this->assertNull($registration->fresh()->matched_resident_id);
    }

    public function test_approval_syncs_a_different_verified_registration_email_to_the_selected_resident(): void
    {
        $candidate = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170000072',
        ]);
        $candidate->account()->delete();
        $candidate->update(['source' => 'admin', 'email' => 'official@example.test']);
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000072', 'email' => 'applicant@example.test',
        ]);
        $registration->forceFill(['email_verified_at' => now()])->save();
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post('/admin/residents/'.$registration->id.'/approve', ['resident_id' => $candidate->id])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('approved', $registration->fresh()->status);
        $candidate->refresh();
        $this->assertSame('applicant@example.test', $candidate->email);
        $this->assertNotNull($candidate->email_verified_at);
        $this->assertSame(1, $candidate->account()->count());
    }

    public function test_approval_populates_a_blank_selected_resident_email_from_the_registration(): void
    {
        $candidate = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170000076',
        ]);
        $candidate->account()->delete();
        $candidate->update(['source' => 'admin', 'email' => '']);
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000076', 'email' => 'verified@example.test',
        ]);
        $registration->forceFill(['email_verified_at' => now()])->save();
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post('/admin/residents/'.$registration->id.'/approve', ['resident_id' => $candidate->id])
            ->assertRedirect()->assertSessionHasNoErrors();

        $candidate->refresh();
        $this->assertSame('verified@example.test', $candidate->email);
        $this->assertNotNull($candidate->email_verified_at);
        $this->assertSame('approved', $registration->fresh()->status);
    }

    public function test_two_applications_cannot_link_the_same_resident(): void
    {
        $candidate = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170000073',
        ]);
        $candidate->account()->delete();
        $candidate->update(['source' => 'admin', 'email' => 'same@example.test']);
        $first = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000073', 'email' => 'same@example.test',
        ]);
        $second = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000074', 'email' => 'same@example.test',
        ]);
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post('/admin/residents/'.$first->id.'/approve', ['resident_id' => $candidate->id])
            ->assertRedirect();
        $this->actingAs($admin)
            ->post('/admin/residents/'.$second->id.'/approve', ['resident_id' => $candidate->id])
            ->assertSessionHasErrors('resident_id');

        $this->assertSame('approved', $first->fresh()->status);
        $this->assertSame('pending', $second->fresh()->status);
        $this->assertSame(
            $first->id,
            ResidentRegistration::query()->where('matched_resident_id', $candidate->id)->value('id')
        );
        $this->assertSame(1, $candidate->account()->count());
    }

    public function test_registration_update_failure_rolls_back_created_account(): void
    {
        $candidate = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170000075',
        ]);
        $candidate->account()->delete();
        $candidate->update(['source' => 'admin', 'email' => 'rollback@example.test']);
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000075',
            'email' => 'rollback@example.test',
        ]);
        $admin = $this->createStaff($this->barangay, 'admin');

        ResidentRegistration::updating(function (ResidentRegistration $record) use ($registration): void {
            if ($record->is($registration) && $record->isDirty('status')) {
                throw new \RuntimeException('Forced registration write failure.');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($admin)
                ->post('/admin/residents/'.$registration->id.'/approve', [
                    'resident_id' => $candidate->id,
                ]);
            $this->fail('Expected the registration update to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Forced registration write failure.', $exception->getMessage());
        }

        $this->assertSame(0, $candidate->account()->count());
        $this->assertSame('pending', $registration->fresh()->status);
        $this->assertNull($registration->fresh()->matched_resident_id);
    }

    public function test_valid_approval_links_selected_resident_without_replacing_identity_or_qr(): void
    {
        $sector = Sector::create([
            'name' => 'Senior Citizen', 'slug' => 'senior',
            'requires_proof' => true, 'is_active' => true,
        ]);
        $candidate = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Official', 'last_name' => 'Resident', 'mobile' => '09170000051',
        ]);
        $candidate->account()->delete();
        $candidate->update(['source' => 'admin', 'email' => 'applicant@example.test']);
        $oldResidentNumber = $candidate->resident_number;
        $lineageRegistrationId = $candidate->resident_registration_id;
        $qr = ResidentQrCredential::create([
            'resident_id' => $candidate->id,
            'token' => 'KBQR-'.Str::random(64),
            'is_active' => true,
            'generated_at' => now(),
        ]);
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09170000052', 'email' => 'APPLICANT@example.test',
        ]);
        $registration->sectors()->create([
            'sector_id' => $sector->id, 'verification_status' => 'pending',
        ]);
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post('/admin/residents/'.$registration->id.'/approve', ['resident_id' => $candidate->id])
            ->assertRedirect();

        $registration->refresh();
        $candidate->refresh();
        $this->assertSame($candidate->id, $registration->matched_resident_id);
        $this->assertSame($admin->id, $registration->matched_by);
        $this->assertNotNull($registration->matched_at);
        $this->assertSame('approved', $registration->status);
        $this->assertSame($lineageRegistrationId, $candidate->resident_registration_id);
        $this->assertSame($oldResidentNumber, $candidate->resident_number);
        $this->assertSame(2, Resident::query()->count());
        $this->assertSame(1, $candidate->account()->count());
        $this->assertTrue($candidate->account->is_active);
        $this->assertSame($qr->id, $candidate->qrCredential()->value('id'));
        $this->assertSame($qr->token, $candidate->qrCredential()->value('token'));
        $this->assertDatabaseHas('resident_sector', [
            'resident_id' => $candidate->id,
            'sector_id' => $sector->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'resident_registration.approved',
            'entity_type' => 'resident_registration',
            'entity_id' => $registration->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'resident_registration.matched',
            'entity_type' => 'resident_registration',
            'entity_id' => $registration->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Rejection integrity
    |--------------------------------------------------------------------------
    */

    public function test_reject_requires_a_reason(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000031',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post('/admin/residents/'.$registration->id.'/reject')
            ->assertSessionHasErrors('reason');

        $this->actingAs($admin)
            ->post('/admin/residents/'.$registration->id.'/reject', ['reason' => 'no'])
            ->assertSessionHasErrors('reason');

        $this->assertDatabaseHas('resident_registrations', [
            'id' => $registration->id,
            'status' => 'pending',
        ]);
    }

    public function test_valid_rejection_records_reviewer_and_reason(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000032',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post('/admin/residents/'.$registration->id.'/reject', [
                'reason' => 'Submitted identification is unreadable.',
            ])
            ->assertRedirect();

        $registration->refresh();

        $this->assertSame('rejected', $registration->status);
        $this->assertSame('Submitted identification is unreadable.', $registration->review_notes);
        $this->assertSame($admin->id, $registration->reviewed_by);
        $this->assertNotNull($registration->reviewed_at);
    }

    public function test_already_reviewed_registration_cannot_be_rejected_again(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000033',
        ]);
        $registration->update(['status' => 'approved']);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post('/admin/residents/'.$registration->id.'/reject', [
                'reason' => 'Trying to reject an approved application.',
            ])
            ->assertStatus(422);

        $this->assertDatabaseHas('resident_registrations', [
            'id' => $registration->id,
            'status' => 'approved',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function createDocument(
        ResidentRegistration $registration,
        array $attributes = []
    ): RegistrationDocument {
        return RegistrationDocument::create([
            'resident_registration_id' => $registration->id,
            'sector_id' => null,
            'document_type' => 'valid_id',
            'disk' => 'local',
            'path' => 'resident-verifications/'.$registration->public_id.'/valid_id.jpg',
            'original_name' => 'valid_id.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1234,
            'sha256' => str_repeat('a', 64),
            'verification_status' => 'pending',
            ...$attributes,
        ]);
    }
}
