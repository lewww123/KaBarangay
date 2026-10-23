<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Purok;
use App\Models\RegistrationDocument;
use App\Models\Resident;
use App\Models\ResidentQrCredential;
use App\Models\ResidentRegistration;
use App\Models\Sector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
                ->where('stats.totalResidents', 1)
                ->where('stats.activeResidents', 1)
                ->where('stats.pendingRegistrations', 1));
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
        $this->get('/admin/residents')->assertRedirect('/login');
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

    public function test_double_approval_is_prevented(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000022',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post('/admin/residents/'.$registration->id.'/approve')
            ->assertRedirect();

        $this->actingAs($admin)
            ->post('/admin/residents/'.$registration->id.'/approve')
            ->assertStatus(422);

        $this->assertSame(
            1,
            Resident::where('resident_registration_id', $registration->id)->count()
        );
    }

    public function test_approval_blocked_when_resident_already_exists(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000023',
        ]);

        // A permanent resident already points at this (still pending) registration.
        Resident::create([
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
            ->post('/admin/residents/'.$registration->id.'/approve')
            ->assertSessionHasErrors('approval');

        $this->assertDatabaseHas('resident_registrations', [
            'id' => $registration->id,
            'status' => 'pending',
        ]);
    }

    public function test_duplicate_mobile_is_handled_without_database_exception(): void
    {
        $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170000031',
        ]);

        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09170000031',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post('/admin/residents/'.$registration->id.'/approve')
            ->assertSessionHasErrors('approval');

        $this->assertDatabaseMissing('residents', [
            'resident_registration_id' => $registration->id,
        ]);
    }

    public function test_duplicate_email_is_handled_without_database_exception(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170000041',
        ]);
        $resident->update(['email' => 'duplicate@example.com']);

        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09170000042',
        ]);
        // Case-insensitive duplicate.
        $registration->update(['email' => 'DUPLICATE@example.com']);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post('/admin/residents/'.$registration->id.'/approve')
            ->assertSessionHasErrors('approval');

        $this->assertDatabaseMissing('residents', [
            'resident_registration_id' => $registration->id,
        ]);
    }

    public function test_valid_approval_creates_resident_account_sector_and_qr(): void
    {
        $sector = Sector::create([
            'name' => 'Senior Citizen',
            'slug' => 'senior',
            'requires_proof' => true,
            'is_active' => true,
        ]);

        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09170000051',
        ]);
        $registration->sectors()->create([
            'sector_id' => $sector->id,
            'verification_status' => 'pending',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post('/admin/residents/'.$registration->id.'/approve')
            ->assertRedirect();

        $resident = Resident::where('resident_registration_id', $registration->id)
            ->firstOrFail();

        $this->assertSame('active', $resident->status);
        $this->assertSame($this->barangay->id, $resident->barangay_id);
        $this->assertNotNull($resident->approved_at);
        $this->assertMatchesRegularExpression(
            '/^BRGYONE-RES-\d{6}$/',
            $resident->resident_number
        );

        $this->assertDatabaseHas('resident_accounts', [
            'resident_id' => $resident->id,
            'is_active' => 1,
        ]);

        $this->assertDatabaseHas('resident_sector', [
            'resident_id' => $resident->id,
            'sector_id' => $sector->id,
        ]);

        $qr = ResidentQrCredential::where('resident_id', $resident->id)->firstOrFail();
        $this->assertTrue($qr->is_active);
        $this->assertStringStartsWith('KBQR-', $qr->token);

        $this->assertDatabaseHas('resident_registrations', [
            'id' => $registration->id,
            'status' => 'approved',
            'reviewed_by' => $admin->id,
        ]);

        $registration->refresh();
        $this->assertNotNull($registration->reviewed_at);
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
