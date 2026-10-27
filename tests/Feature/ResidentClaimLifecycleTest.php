<?php

namespace Tests\Feature;

use App\Mail\ResidentApprovalMail;
use App\Mail\ResidentClaimInvitationMail;
use App\Mail\ResidentEmailVerificationMail;
use App\Models\Barangay;
use App\Models\Purok;
use App\Models\Resident;
use App\Models\ResidentAccount;
use App\Models\ResidentClaim;
use App\Models\ResidentEmailVerificationToken;
use App\Models\ResidentNotification;
use App\Models\ResidentQrCredential;
use App\Support\ResidentNotificationService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class ResidentClaimLifecycleTest extends TestCase
{
    use CreatesBarangayFixtures;

    private Barangay $barangay;

    private Barangay $other;

    private Purok $purok;

    private Purok $otherPurok;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh');

        $this->withoutVite();
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $this->other = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $this->purok = $this->createPurok($this->barangay, 'Purok 1');
        $this->otherPurok = $this->createPurok($this->other, 'Purok 2');
    }

    public function test_public_claim_transition_redirects_and_legacy_status_page_discloses_status_only(): void
    {
        $this->get('/resident/claim')
            ->assertRedirect(route('resident.register'));

        [$resident, $credential] = $this->createClaimableResident();
        $claim = $this->createLegacyClaim($resident, $credential);

        $this->post('/resident/claim', $this->claimPayload($resident, $credential))
            ->assertRedirect(route('resident.register'));
        $this->assertDatabaseCount('resident_claims', 1);

        $this->get('/resident/claim/submitted/'.$claim->public_id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Resident/ClaimSubmitted')
                ->where('claim.reference', $claim->public_id)
                ->where('claim.status', ResidentClaim::STATUS_PENDING)
                ->missing('claim.email')
                ->missing('claim.email_verified')
                ->missing('claim.resident_number'));

        $this->get('/resident/claim/submitted/'.Str::uuid())->assertNotFound();
    }

    public function test_admin_can_create_a_masterlist_resident_and_issue_a_claim_credential(): void
    {
        Mail::fake();

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post('/admin/residents', [
                'purok_id' => $this->purok->id,
                'first_name' => 'Maria',
                'middle_name' => 'Santos',
                'last_name' => 'Reyes',
                'suffix' => null,
                'birthdate' => '1992-03-04',
                'sex' => 'Female',
                'civil_status' => 'Married',
                'address_line' => '123 Mabini Street',
                'mobile' => '09171234567',
                'email' => 'maria.reyes@example.com',
            ])
            ->assertSessionHasNoErrors();

        $resident = Resident::query()
            ->where('email', 'maria.reyes@example.com')
            ->firstOrFail();

        $this->assertSame('admin', $resident->source);
        $this->assertSame($admin->id, $resident->created_by);
        $this->assertNull($resident->resident_registration_id);
        $this->assertNull($resident->email_verified_at);
        $this->assertSame('BRGYONE-01-0001', $resident->resident_number);

        $this->assertDatabaseHas('resident_qr_credentials', [
            'resident_id' => $resident->id,
            'is_active' => true,
        ]);

        $notification = ResidentNotification::query()
            ->where('event_key', 'resident.claim-invitation.'.$resident->id)
            ->firstOrFail();

        $this->assertSame(ResidentNotification::STATUS_SENT, $notification->status);
        Mail::assertSent(ResidentClaimInvitationMail::class, function (ResidentClaimInvitationMail $mail) use ($resident): bool {
            $body = $mail->render();

            return $mail->registrationUrl === route('resident.register')
                && str_contains($body, 'Resident ID and QR reference')
                && ! str_contains($body, $resident->resident_number)
                && ! str_contains($body, $resident->qrCredential->token);
        });

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'resident.created',
            'entity_type' => 'resident',
            'entity_id' => $resident->id,
        ]);
    }

    public function test_online_accounts_has_no_linked_review_filter(): void
    {
        $own = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170000015',
        ]);
        $other = $this->createResident($this->other, $this->otherPurok, [
            'mobile' => '09170000016',
        ]);
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/resident-claims')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/ResidentClaims')
                ->where('status', 'pending')
                ->missing('linkedAccounts'));

        $this->assertNotSame($own->resident_number, $other->resident_number);
    }

    public function test_online_accounts_page_loads_when_deployed_schema_has_no_match_column(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000017',
        ]);
        $admin = $this->createStaff($this->barangay, 'admin');

        Schema::shouldReceive('hasColumn')
            ->once()
            ->with('resident_registrations', 'matched_resident_id')
            ->andReturn(false);

        $this->actingAs($admin)
            ->get('/admin/resident-claims')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/ResidentClaims')
                ->where('registrations.total', 1)
                ->where('registrations.data.0.id', $registration->id)
                ->where('registrations.data.0.residentNumber', null));
    }

    public function test_admin_creation_requires_an_email_and_admin_role(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $payload = [
            'purok_id' => $this->purok->id,
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'birthdate' => '1991-01-01',
            'sex' => 'Female',
            'civil_status' => 'Single',
            'address_line' => '1 Test Street',
            'mobile' => '09170001001',
            'email' => '',
        ];

        $this->actingAs($admin)
            ->post('/admin/residents', $payload)
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('residents', 0);

        $payload['email'] = 'ana.cruz@example.com';

        $this->actingAs($clerk)
            ->post('/admin/residents', $payload)
            ->assertForbidden();

        $this->assertDatabaseCount('residents', 0);
    }

    public function test_admin_qr_reveal_is_scoped_and_audited(): void
    {
        [$resident, $credential] = $this->createClaimableResident();
        $admin = $this->createStaff($this->barangay, 'admin');
        $otherAdmin = $this->createStaff($this->other, 'admin');

        $this->actingAs($otherAdmin)
            ->getJson('/admin/residents/'.$resident->id.'/qr/credential')
            ->assertNotFound();

        $this->actingAs($admin)
            ->getJson('/admin/residents/'.$resident->id.'/qr/credential')
            ->assertOk()
            ->assertJsonPath('resident_number', $resident->resident_number)
            ->assertJsonPath(
                'verification_url',
                route('resident.qr.verify', ['token' => $credential->token])
            );

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'resident.qr.delivered',
            'actor_id' => $admin->id,
            'entity_type' => 'resident',
            'entity_id' => $resident->id,
        ]);
    }

    public function test_legacy_claim_email_verification_and_admin_approval_still_work(): void
    {
        Mail::fake();

        [$resident, $credential] = $this->createClaimableResident(
            attributes: [
                'last_name' => 'Dela Cruz',
                'birthdate' => '1990-01-01',
            ]
        );

        $claim = $this->createLegacyClaim($resident, $credential);

        $this->assertSame(ResidentClaim::STATUS_PENDING, $claim->status);
        $this->assertSame($resident->id, $claim->resident_id);
        $this->assertTrue(Hash::check('Password123', $claim->password));
        $this->assertDatabaseMissing('resident_accounts', [
            'resident_id' => $resident->id,
        ]);
        $this->assertDatabaseCount('resident_claims', 1);

        app(\App\Support\ResidentEmailVerificationService::class)->send($resident, $claim);

        Mail::assertSent(
            ResidentEmailVerificationMail::class,
            fn (ResidentEmailVerificationMail $mail) => $mail->hasTo($resident->email)
        );

        $verificationUrl = Mail::sent(ResidentEmailVerificationMail::class)
            ->first()
            ->verificationUrl;

        $this->get($this->pathFromUrl($verificationUrl))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Resident/EmailVerification')
                ->where('status', 'verified'));

        $resident->refresh();
        $this->assertNotNull($resident->email_verified_at);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post('/admin/resident-claims/'.$claim->id.'/approve')
            ->assertSessionHasNoErrors();

        $claim->refresh();
        $this->assertSame(ResidentClaim::STATUS_APPROVED, $claim->status);
        $this->assertSame($admin->id, $claim->reviewed_by);

        $account = ResidentAccount::query()
            ->where('resident_id', $resident->id)
            ->firstOrFail();

        $this->assertTrue($account->is_active);
        $this->assertTrue(Hash::check('Password123', $account->password));
        $this->assertSame(1, ResidentAccount::query()
            ->where('resident_id', $resident->id)
            ->count());
        $this->assertSame(1, Resident::query()
            ->where('resident_number', $resident->resident_number)
            ->count());

        $notification = ResidentNotification::query()
            ->where('event_key', 'resident.approval.'.$claim->id)
            ->firstOrFail();

        $this->assertSame(ResidentNotification::STATUS_SENT, $notification->status);
        $this->assertSame(1, $notification->attempts);

        Mail::assertSent(
            ResidentApprovalMail::class,
            fn (ResidentApprovalMail $mail) => $mail->hasTo($resident->email)
        );

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'resident_claim.approved',
            'entity_type' => 'resident_claim',
            'entity_id' => $claim->id,
        ]);
    }

    public function test_claim_cannot_be_approved_before_the_resident_email_is_verified(): void
    {
        Mail::fake();

        [$resident, $credential] = $this->createClaimableResident();

        $claim = $this->createLegacyClaim($resident, $credential);
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post('/admin/resident-claims/'.$claim->id.'/approve')
            ->assertSessionHasErrors('claim');

        $this->assertDatabaseMissing('resident_accounts', [
            'resident_id' => $resident->id,
        ]);
        $this->assertSame(
            ResidentClaim::STATUS_PENDING,
            $claim->fresh()->status
        );
        $this->assertDatabaseCount('resident_notification_outbox', 0);
    }

    public function test_public_claim_post_does_not_create_a_claim(): void
    {
        Mail::fake();

        [$resident, $credential] = $this->createClaimableResident();

        $this->from('/resident/claim')
            ->post('/resident/claim', $this->claimPayload(
                $resident,
                $credential,
                ['last_name' => 'Wrong Name']
            ))
            ->assertRedirect(route('resident.register'));

        $this->assertDatabaseCount('resident_claims', 0);
        $this->assertDatabaseMissing('resident_accounts', [
            'resident_id' => $resident->id,
        ]);
    }

    public function test_legacy_pending_claim_can_resend_verification_but_finalized_claim_cannot(): void
    {
        Mail::fake();
        [$resident, $credential] = $this->createClaimableResident();
        $claim = $this->createLegacyClaim($resident, $credential);

        $this->post('/resident/claim/'.$claim->public_id.'/resend-verification')
            ->assertRedirect()
            ->assertSessionHas('success');

        Mail::assertSent(ResidentEmailVerificationMail::class);
        $claim->update(['status' => ResidentClaim::STATUS_REJECTED]);

        $this->post('/resident/claim/'.$claim->public_id.'/resend-verification')
            ->assertStatus(422);

        Mail::assertSent(ResidentEmailVerificationMail::class, 1);
    }

    public function test_claim_approval_fails_if_the_qr_credential_was_revoked(): void
    {
        Mail::fake();

        [$resident, $credential] = $this->createClaimableResident();

        $claim = $this->createLegacyClaim($resident, $credential);
        app(\App\Support\ResidentEmailVerificationService::class)->send($resident, $claim);
        $verificationUrl = Mail::sent(ResidentEmailVerificationMail::class)
            ->first()
            ->verificationUrl;

        $this->get($this->pathFromUrl($verificationUrl))->assertOk();

        $credential->forceFill([
            'is_active' => false,
            'revoked_at' => now(),
        ])->save();

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post('/admin/resident-claims/'.$claim->id.'/approve')
            ->assertSessionHasErrors('claim');

        $this->assertSame(
            ResidentClaim::STATUS_PENDING,
            $claim->fresh()->status
        );
        $this->assertDatabaseMissing('resident_accounts', [
            'resident_id' => $resident->id,
        ]);
    }

    public function test_cross_barangay_claim_review_is_hidden(): void
    {
        [$otherResident, $otherCredential] = $this->createClaimableResident(
            $this->other,
            $this->otherPurok,
            ['last_name' => 'Other']
        );

        $claim = ResidentClaim::query()->create([
            'public_id' => (string) Str::uuid(),
            'resident_id' => $otherResident->id,
            'barangay_id' => $this->other->id,
            'purok_id' => $this->otherPurok->id,
            'qr_credential_id' => $otherCredential->id,
            'email' => $otherResident->email,
            'password' => Hash::make('Password123'),
            'status' => ResidentClaim::STATUS_PENDING,
            'identity_checked_at' => now(),
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->getJson('/admin/resident-claims/'.$claim->id.'/details')
            ->assertNotFound();

        $this->actingAs($admin)
            ->post('/admin/resident-claims/'.$claim->id.'/approve')
            ->assertNotFound();

        $this->assertSame(
            ResidentClaim::STATUS_PENDING,
            $claim->fresh()->status
        );
    }

    public function test_verification_tokens_expire_and_cannot_be_reused(): void
    {
        Mail::fake();

        [$resident, $credential] = $this->createClaimableResident();

        $claim = $this->createLegacyClaim($resident, $credential);
        app(\App\Support\ResidentEmailVerificationService::class)->send($resident, $claim);

        $verificationUrl = Mail::sent(ResidentEmailVerificationMail::class)
            ->first()
            ->verificationUrl;

        $token = ResidentEmailVerificationToken::query()->firstOrFail();
        $token->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->get($this->pathFromUrl($verificationUrl))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('status', 'expired'));

        $this->assertNull($resident->fresh()->email_verified_at);
        $this->assertDatabaseCount('resident_email_verification_tokens', 0);
    }

    public function test_email_verification_link_is_invalid_after_the_resident_email_changes(): void
    {
        Mail::fake();

        [$resident, $credential] = $this->createClaimableResident();

        $claim = $this->createLegacyClaim($resident, $credential);
        app(\App\Support\ResidentEmailVerificationService::class)->send($resident, $claim);

        $verificationUrl = Mail::sent(ResidentEmailVerificationMail::class)
            ->first()
            ->verificationUrl;

        $resident->forceFill(['email' => 'changed@example.com'])->save();

        $this->get($this->pathFromUrl($verificationUrl))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('status', 'email_changed'));

        $this->assertNull($resident->fresh()->email_verified_at);
    }

    public function test_changing_profile_email_clears_verification_and_sends_a_new_link(): void
    {
        Mail::fake();

        $resident = $this->createResident($this->barangay, $this->purok);
        $resident->forceFill([
            'email' => 'old.address@example.com',
            'email_verified_at' => now(),
        ])->save();

        $this->withSession(['resident_id' => $resident->id])
            ->from('/resident/profile')
            ->post('/resident/profile/contact', [
                'mobileNumber' => $resident->mobile,
                'email' => 'new.address@example.com',
            ])
            ->assertRedirect('/resident/profile')
            ->assertSessionHasNoErrors();

        $resident->refresh();
        $this->assertSame('new.address@example.com', $resident->email);
        $this->assertNull($resident->email_verified_at);

        Mail::assertSent(
            ResidentEmailVerificationMail::class,
            fn (ResidentEmailVerificationMail $mail) => $mail->hasTo('new.address@example.com')
        );

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'resident.email_changed',
            'entity_type' => 'resident',
            'entity_id' => $resident->id,
        ]);
    }

    public function test_notification_delivery_is_idempotent_and_refuses_open_transactions(): void
    {
        Mail::fake();

        [$resident, $credential] = $this->createClaimableResident();

        $claim = ResidentClaim::query()->create([
            'public_id' => (string) Str::uuid(),
            'resident_id' => $resident->id,
            'barangay_id' => $this->barangay->id,
            'purok_id' => $this->purok->id,
            'qr_credential_id' => $credential->id,
            'email' => $resident->email,
            'password' => Hash::make('Password123'),
            'status' => ResidentClaim::STATUS_APPROVED,
            'identity_checked_at' => now(),
        ]);

        $notifications = app(ResidentNotificationService::class);

        $first = $notifications->enqueueApprovalConfirmation($resident, $claim);
        $second = $notifications->enqueueApprovalConfirmation($resident, $claim);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('resident_notification_outbox', 1);

        DB::beginTransaction();

        try {
            $this->expectException(LogicException::class);
            $notifications->deliver($first);
        } finally {
            DB::rollBack();
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: Resident, 1: ResidentQrCredential}
     */
    private function createClaimableResident(
        ?Barangay $barangay = null,
        ?Purok $purok = null,
        array $attributes = []
    ): array {
        $barangay ??= $this->barangay;
        $purok ??= $this->purok;
        $suffix = Str::lower(Str::random(6));

        $resident = Resident::query()->create([
            'public_id' => (string) Str::uuid(),
            'resident_registration_id' => null,
            'barangay_id' => $barangay->id,
            'purok_id' => $purok->id,
            'resident_number' => 'TEST-RES-'.Str::upper($suffix),
            'source' => 'admin',
            'first_name' => $attributes['first_name'] ?? 'Juan',
            'middle_name' => null,
            'last_name' => $attributes['last_name'] ?? 'Dela Cruz',
            'suffix' => null,
            'birthdate' => $attributes['birthdate'] ?? '1990-01-01',
            'sex' => 'Male',
            'civil_status' => 'Single',
            'address_line' => '123 Test Street',
            'mobile' => $attributes['mobile'] ?? '0917'.random_int(1000000, 9999999),
            'email' => $attributes['email'] ?? $suffix.'@example.com',
            'email_verified_at' => null,
            'status' => 'active',
            'approved_at' => now(),
        ]);

        $credential = ResidentQrCredential::query()->create([
            'resident_id' => $resident->id,
            'token' => 'KBQR-'.Str::random(64),
            'is_active' => true,
            'generated_at' => now(),
            'revoked_at' => null,
        ]);

        return [$resident, $credential];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function claimPayload(
        Resident $resident,
        ResidentQrCredential $credential,
        array $overrides = []
    ): array {
        return [
            'resident_number' => $resident->resident_number,
            'qr_reference' => route('resident.qr.verify', [
                'token' => $credential->token,
            ]),
            'last_name' => $resident->last_name,
            'birthdate' => $resident->birthdate->format('Y-m-d'),
            'email' => $resident->email,
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            ...$overrides,
        ];
    }

    private function createLegacyClaim(
        Resident $resident,
        ResidentQrCredential $credential
    ): ResidentClaim {
        return ResidentClaim::query()->create([
            'public_id' => (string) Str::uuid(),
            'resident_id' => $resident->id,
            'barangay_id' => $resident->barangay_id,
            'purok_id' => $resident->purok_id,
            'qr_credential_id' => $credential->id,
            'email' => $resident->email,
            'password' => Hash::make('Password123'),
            'status' => ResidentClaim::STATUS_PENDING,
            'identity_checked_at' => now(),
            'identity_method' => 'legacy',
        ]);
    }

    private function pathFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        $this->assertIsString($path);

        return $path;
    }
}
