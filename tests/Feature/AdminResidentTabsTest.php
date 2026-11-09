<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Barangay;
use App\Models\Purok;
use App\Models\RegistrationDocument;
use App\Models\Resident;
use App\Models\ResidentRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

/**
 * Resident directory category semantics and the read-only detail endpoints.
 */
class AdminResidentTabsTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Barangay $other;

    private Purok $purok;

    private Purok $otherPurok;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $this->other = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $this->purok = $this->createPurok($this->barangay, 'Purok 1');
        $this->otherPurok = $this->createPurok($this->other, 'Purok 2');
    }

    public function test_all_residents_requires_an_active_account_and_approved_association_and_overlaps_admin_source(): void
    {
        $qualifying = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09181000001',
        ]);
        $adminAdded = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09181000002',
        ]);
        $adminAdded->update(['source' => 'admin']);
        $withoutAccount = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09181000003',
        ]);
        $withoutAccount->account()->delete();
        $inactive = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09181000004',
        ]);
        $inactive->update(['status' => 'inactive']);
        $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09981000001',
        ]);
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/residents')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Residents')
                ->where('allResidents.total', 3)
                ->has('allResidents.data', 3)
                ->where('allResidents.data.0.id', $qualifying->id));

        $this->actingAs($admin)
            ->get('/admin/residents', $this->partialHeaders('addedByAdmin'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('addedByAdmin.total', 1)
                ->where('addedByAdmin.data.0.id', $adminAdded->id));

        $this->actingAs($admin)
            ->get('/admin/residents', $this->partialHeaders('pendingRegistrations,needsCorrectionRegistrations,rejectedRegistrations'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pendingRegistrations.total', 1)
                ->where('needsCorrectionRegistrations.total', 0)
                ->where('rejectedRegistrations.total', 0));
    }

    public function test_all_residents_does_not_accept_a_legacy_claim_as_account_qualification(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09181000005',
        ]);
        $resident->registration()->update(['status' => 'pending']);
        $resident->forceFill(['email_verified_at' => null])->save();
        DB::table('resident_claims')->insert([
            'resident_id' => $resident->id,
            'public_id' => (string) Str::uuid(),
            'barangay_id' => $this->barangay->id,
            'purok_id' => $this->purok->id,
            'email' => 'legacy-claim@example.test',
            'password' => bcrypt('Password123'),
            'status' => 'approved',
            'identity_checked_at' => now(),
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/residents')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('allResidents.total', 1));
    }

    public function test_online_accounts_preserves_registration_status_models_and_barangay_scope(): void
    {
        $pending = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09981000011',
        ]);
        $correction = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09981000012',
        ]);
        $correction->update(['status' => 'needs_correction']);
        $rejectedForeign = $this->createPendingRegistration($this->other, $this->otherPurok, [
            'mobile' => '09982000011',
        ]);
        $rejectedForeign->update(['status' => 'rejected']);
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/residents?tab=pending', $this->partialHeaders('pendingRegistrations'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Residents')
                ->where('pendingRegistrations.total', 1)
                ->where('pendingRegistrations.data.0.id', $pending->id));

        $this->actingAs($admin)
            ->get('/admin/residents?tab=needs_correction', $this->partialHeaders('needsCorrectionRegistrations'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('needsCorrectionRegistrations.total', 1)
                ->where('needsCorrectionRegistrations.data.0.id', $correction->id));

        $this->actingAs($admin)
            ->get('/admin/residents?tab=rejected', $this->partialHeaders('rejectedRegistrations'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('rejectedRegistrations.total', 0));
    }

    public function test_verified_directory_search_and_purok_filter_remain_scoped(): void
    {
        $matching = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Alpha',
            'mobile' => '09181000031',
        ]);
        $matching->update(['email_verified_at' => now()]);
        $foreignPurok = $this->createPurok($this->other, 'Purok 3');
        $foreign = $this->createResident($this->other, $foreignPurok, [
            'first_name' => 'Alpha',
            'mobile' => '09182000031',
        ]);
        $foreign->update(['email_verified_at' => now()]);
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/residents?resident_search=Alpha&purok='.$this->purok->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('allResidents.total', 1)
                ->where('allResidents.data.0.id', $matching->id)
                ->where('filters.purok_id', $this->purok->id));
    }
    /*
    |--------------------------------------------------------------------------
    | Read-only details endpoints
    |--------------------------------------------------------------------------
    */

    public function test_resident_details_expose_only_intended_fields(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09181000071',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->getJson('/admin/residents/'.$resident->id.'/details')
            ->assertOk()
            ->assertJsonPath('type', 'resident')
            ->assertJsonPath('data.resident_number', $resident->resident_number)
            ->assertJsonPath('data.mobile', $resident->mobile)
            ->assertJsonPath('data.purok', $this->purok->name)
            ->assertJsonPath('data.account_active', true)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.qr_token')
            ->assertJsonMissingPath('data.token');
    }

    public function test_admin_added_resident_details_use_the_resident_record_id_and_keep_its_resident_number(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09181000075',
            'resident_number' => 'BRGYONE-RES-900075',
        ]);
        $resident->update(['source' => 'admin']);
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->assertNotSame((string) $resident->id, $resident->resident_number);

        $this->actingAs($admin)
            ->getJson('/admin/residents/'.$resident->id.'/details')
            ->assertOk()
            ->assertJsonPath('type', 'resident')
            ->assertJsonPath('data.id', $resident->id)
            ->assertJsonPath('data.source', 'admin')
            ->assertJsonPath('data.resident_number', 'BRGYONE-RES-900075');
    }

    public function test_registration_details_include_status_and_review_information(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09981000071',
        ]);
        $registration->update([
            'status' => 'rejected',
            'review_notes' => 'Blurry identification.',
            'reviewed_at' => now(),
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->getJson('/admin/resident-registrations/'.$registration->id.'/details')
            ->assertOk()
            ->assertJsonPath('type', 'registration')
            ->assertJsonPath('data.reference', $registration->public_id)
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.review_notes', 'Blurry identification.')
            ->assertJsonMissingPath('data.password');
    }

    public function test_registration_details_do_not_expose_the_password_hash(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09981000072',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->getJson('/admin/resident-registrations/'.$registration->id.'/details')
            ->assertOk()
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.qr_token');
    }

    public function test_registration_details_load_without_match_metadata_columns(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09981000073',
        ]);
        $admin = $this->createStaff($this->barangay, 'admin');

        Schema::shouldReceive('hasColumn')
            ->once()
            ->with('resident_registrations', 'matched_resident_id')
            ->andReturn(false);

        $this->actingAs($admin)
            ->getJson('/admin/resident-registrations/'.$registration->id.'/details')
            ->assertOk()
            ->assertJsonPath('type', 'registration')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.matched_at', null)
            ->assertJsonPath('data.matched_resident', null);
    }

    public function test_cross_barangay_resident_details_return_404(): void
    {
        $otherPurok = $this->createPurok($this->other, 'Foreign Purok');
        $foreign = $this->createResident($this->other, $otherPurok, [
            'mobile' => '09181000081',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->getJson('/admin/residents/'.$foreign->id.'/details')
            ->assertStatus(404);
    }

    public function test_cross_barangay_registration_details_return_404(): void
    {
        $otherPurok = $this->createPurok($this->other, 'Foreign Purok');
        $foreign = $this->createPendingRegistration($this->other, $otherPurok, [
            'mobile' => '09981000081',
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->getJson('/admin/resident-registrations/'.$foreign->id.'/details')
            ->assertStatus(404);
    }

    public function test_details_endpoints_require_admin_role(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09181000091',
        ]);
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09981000091',
        ]);

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->getJson('/admin/residents/'.$resident->id.'/details')
            ->assertStatus(403);

        $this->actingAs($clerk)
            ->getJson('/admin/resident-registrations/'.$registration->id.'/details')
            ->assertStatus(403);
    }

    public function test_details_endpoints_block_guests(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09181000092',
        ]);

        $this->get('/admin/residents/'.$resident->id.'/details')
            ->assertRedirect(route('login'));
    }

    public function test_missing_details_records_return_404(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->getJson('/admin/residents/999999/details')
            ->assertStatus(404);

        $this->actingAs($admin)
            ->getJson('/admin/resident-registrations/999999/details')
            ->assertStatus(404);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function partialHeaders(string $props): array
    {
        return [

            'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()) ?? '',
            'X-Inertia-Partial-Component' => 'Admin/Residents',
            'X-Inertia-Partial-Data' => $props,
        ];
    }

    private function createDocument(ResidentRegistration $registration): RegistrationDocument
    {
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
        ]);
    }
}
