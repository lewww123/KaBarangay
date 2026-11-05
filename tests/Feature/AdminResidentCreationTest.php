<?php

namespace Tests\Feature;

use App\Mail\ResidentRegistrationInvitationMail;
use App\Models\Barangay;
use App\Models\Purok;
use App\Models\Resident;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class AdminResidentCreationTest extends TestCase
{
    use CreatesBarangayFixtures;

    private Barangay $barangay;

    private Barangay $otherBarangay;

    private Purok $purok;

    private Purok $otherPurok;

    protected function setUp(): void
    {
        parent::setUp();
        // Invitation delivery requires a real commit, not RefreshDatabase's outer transaction.
        $this->artisan('migrate:fresh');

        $this->withoutVite();
        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $this->otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $this->purok = $this->createPurok($this->barangay, 'Purok 1');
        $this->otherPurok = $this->createPurok($this->otherBarangay, 'Purok 2');
    }

    public function test_admin_can_create_resident_without_email_and_without_queuing_invitation(): void
    {
        Mail::fake();
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post('/admin/residents', $this->residentData())
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Resident created. No registration invitation was sent because no email address was provided.');

        $resident = Resident::query()->where('mobile', '09175550101')->sole();

        $this->assertNull($resident->email);
        $this->assertNull($resident->email_verified_at);
        $this->assertSame('inactive', $resident->status);
        $this->assertNull($resident->approved_at);
        $this->assertDatabaseCount('resident_accounts', 1);
        $this->assertDatabaseCount('resident_notification_outbox', 0);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'resident.created',
            'entity_id' => $resident->id,
        ]);
        Mail::assertNothingOutgoing();
    }

    public function test_blank_email_is_normalized_to_null(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post('/admin/residents', [...$this->residentData(), 'email' => '   '])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertNull(Resident::query()->firstOrFail()->email);
        $this->assertDatabaseCount('resident_notification_outbox', 0);
    }

    public function test_valid_email_preserves_invitation_and_contact_storage(): void
    {
        Mail::fake();
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post('/admin/residents', [...$this->residentData(), 'email' => '  resident@example.test  '])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $resident = Resident::query()->where('mobile', '09175550101')->sole();

        $this->assertSame('resident@example.test', $resident->email);
        $this->assertNull($resident->email_verified_at);
        $this->assertDatabaseHas('resident_notification_outbox', [
            'resident_id' => $resident->id,
            'recipient_email' => 'resident@example.test',
        ]);
        Mail::assertSent(ResidentRegistrationInvitationMail::class);
    }

    public function test_invalid_supplied_email_is_rejected(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->from('/admin/residents')
            ->post('/admin/residents', [...$this->residentData(), 'email' => 'not-an-email'])
            ->assertRedirect('/admin/residents')
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('residents', 1);
    }

    public function test_duplicate_supplied_email_is_rejected_within_the_barangay(): void
    {
        $existing = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170000001',
        ]);
        $existing->update(['email' => 'resident@example.test']);
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->from('/admin/residents')
            ->post('/admin/residents', [...$this->residentData(), 'email' => 'RESIDENT@example.test'])
            ->assertRedirect('/admin/residents')
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('residents', 2);
    }

    public function test_same_email_in_another_barangay_does_not_block_creation(): void
    {
        $existing = $this->createResident($this->otherBarangay, $this->otherPurok, [
            'mobile' => '09170000002',
        ]);
        $existing->update(['email' => 'resident@example.test']);
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post('/admin/residents', [...$this->residentData(), 'email' => 'resident@example.test'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(3, Resident::query()->count());
    }

    public function test_phone_number_remains_required_and_duplicate_protected(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->from('/admin/residents')
            ->post('/admin/residents', array_diff_key($this->residentData(), ['mobile' => true]))
            ->assertRedirect('/admin/residents')
            ->assertSessionHasErrors('mobile');

        $this->actingAs($admin)
            ->from('/admin/residents')
            ->post('/admin/residents', [...$this->residentData(), 'mobile' => '12345'])
            ->assertRedirect('/admin/residents')
            ->assertSessionHasErrors('mobile');

        $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09175550101',
        ]);

        $this->actingAs($admin)
            ->from('/admin/residents')
            ->post('/admin/residents', $this->residentData())
            ->assertRedirect('/admin/residents')
            ->assertSessionHasErrors('mobile');
    }

    public function test_admin_authorization_and_barangay_purok_isolation_remain_enforced(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->post('/admin/residents', $this->residentData())
            ->assertForbidden();

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->from('/admin/residents')
            ->post('/admin/residents', [
                ...$this->residentData(),
                'purok_id' => $this->otherPurok->id,
            ])
            ->assertRedirect('/admin/residents')
            ->assertSessionHasErrors('purok_id');

        $this->assertDatabaseCount('residents', 2);
    }

    public function test_application_submitted_before_admin_creation_can_find_and_link_the_new_inactive_record(): void
    {
        Mail::fake();
        config(['registration.email_verification_required' => false]);
        $application = $this->createPendingRegistration($this->barangay, $this->purok, ['first_name' => 'Maria', 'last_name' => 'Santos', 'sex' => 'Female', 'birthdate' => '1990-01-01', 'mobile' => '09175550101', 'email' => 'maria@example.test']);
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->actingAs($admin)->post('/admin/residents', [...$this->residentData(), 'email' => 'maria@example.test'])->assertRedirect()->assertSessionHasNoErrors();
        $resident = Resident::query()->where('source', 'admin')->where('mobile', '09175550101')->sole();
        $this->assertSame('inactive', $resident->status);
        $this->assertFalse($resident->account()->exists());
        $residentCount = Resident::count();
        $this->getJson('/admin/resident-registrations/'.$application->id.'/candidates?q=Maria%20Santos')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $resident->id)->assertJsonPath('data.0.status', 'inactive')->assertJsonPath('data.0.account_exists', false);
        $this->post('/admin/residents/'.$application->id.'/approve', ['resident_id' => $resident->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('approved', $application->fresh()->status);
        $this->assertSame($resident->id, $application->fresh()->matched_resident_id);
        $this->assertSame('active', $resident->fresh()->status);
        $this->assertTrue($resident->account()->firstOrFail()->is_active);
        $this->assertSame($application->password, $resident->account()->firstOrFail()->password);
        $this->assertSame($residentCount, Resident::count());
    }

    public function test_unmapped_dummy_purok_returns_validation_error_and_rolls_back_creation(): void
    {
        Mail::fake();
        $admin = $this->createStaff($this->barangay, 'admin');
        $dummy = Purok::query()->create([
            'barangay_id' => $this->barangay->id,
            'name' => 'Dummy Staff',
            'code' => 'BRGY-ONE-DUMMY',
            'sort_order' => 0,
            'is_active' => true,
        ]);
        $residentCount = Resident::count();

        $this->actingAs($admin)
            ->from('/admin/residents')
            ->post('/admin/residents', [
                ...$this->residentData(),
                'purok_id' => $dummy->id,
                'household_mode' => 'new',
                'household_relationship' => 'Head',
            ])
            ->assertRedirect('/admin/residents')
            ->assertSessionHasErrors('purok_id');

        $this->assertDatabaseCount('residents', $residentCount);
        $this->assertDatabaseCount('households', 0);
        $this->assertDatabaseCount('resident_number_sequences', 0);
        $this->assertDatabaseCount('resident_notification_outbox', 0);
        Mail::assertNothingOutgoing();
    }

    /** @return array<string, int|string> */
    private function residentData(): array
    {
        return [
            'purok_id' => $this->purok->id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'birthdate' => '1990-01-01',
            'sex' => 'Female',
            'civil_status' => 'Single',
            'address_line' => '123 Test Street',
            'mobile' => '09175550101',
        ];
    }
}
