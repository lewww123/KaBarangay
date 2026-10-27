<?php

namespace Tests\Feature;

use App\Mail\ResidentClaimInvitationMail;
use App\Models\AuditLog;
use App\Models\Barangay;
use App\Models\Purok;
use App\Models\Resident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class AdminResidentCreationTest extends TestCase
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

        $resident = Resident::query()->firstOrFail();

        $this->assertNull($resident->email);
        $this->assertNull($resident->email_verified_at);
        $this->assertDatabaseCount('resident_accounts', 0);
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

        $resident = Resident::query()->firstOrFail();

        $this->assertSame('resident@example.test', $resident->email);
        $this->assertNull($resident->email_verified_at);
        $this->assertDatabaseHas('resident_notification_outbox', [
            'resident_id' => $resident->id,
            'recipient_email' => 'resident@example.test',
        ]);
        Mail::assertSent(ResidentClaimInvitationMail::class);
    }

    public function test_invalid_supplied_email_is_rejected(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->from('/admin/residents')
            ->post('/admin/residents', [...$this->residentData(), 'email' => 'not-an-email'])
            ->assertRedirect('/admin/residents')
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('residents', 0);
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

        $this->assertDatabaseCount('residents', 1);
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

        $this->assertSame(2, Resident::query()->count());
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

        $this->assertDatabaseCount('residents', 0);
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
