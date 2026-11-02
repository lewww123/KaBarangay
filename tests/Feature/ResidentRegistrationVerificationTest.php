<?php

namespace Tests\Feature;

use App\Mail\ResidentRegistrationEmailVerificationMail;
use App\Models\Barangay;
use App\Models\Purok;
use App\Models\Resident;
use App\Models\ResidentAccount;
use App\Models\ResidentQrCredential;
use App\Models\ResidentRegistration;
use App\Models\ResidentRegistrationEmailVerificationToken;
use App\Support\ResidentRegistrationEmailVerificationService;
use App\Support\ResidentRegistrationQrMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class ResidentRegistrationVerificationTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private Barangay $barangay;

    private Barangay $otherBarangay;

    private Purok $purok;

    private Purok $otherPurok;

    protected function setUp(): void
    {
        parent::setUp();
        config(['registration.email_verification_required' => true]);

        $this->withoutVite();
        Storage::fake('local');
        Mail::fake();

        $this->barangay = $this->createBarangay('Verification Barangay', 'VERIFY-ONE');
        $this->otherBarangay = $this->createBarangay('Other Barangay', 'VERIFY-TWO');
        $this->purok = $this->createPurok($this->barangay);
        $this->otherPurok = $this->createPurok($this->otherBarangay, 'Purok 2');
    }

    public function test_registration_email_otp_is_hashed_expiring_single_use_and_does_not_create_a_resident(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000301',
        ]);
        $registration->update(['email' => 'applicant@example.test']);
        $verification = app(ResidentRegistrationEmailVerificationService::class);

        $this->assertTrue($verification->send($registration));

        $tokenRow = ResidentRegistrationEmailVerificationToken::query()->firstOrFail();
        $this->assertSame(64, strlen($tokenRow->token_hash));
        $this->assertSame(1, $tokenRow->send_count);
        $this->assertSame('applicant@example.test', $tokenRow->email_snapshot);
        $this->assertGreaterThanOrEqual(9, now()->diffInMinutes($tokenRow->expires_at, false));
        $this->assertLessThanOrEqual(10, now()->diffInMinutes($tokenRow->expires_at, false));

        $code = null;
        Mail::assertSent(ResidentRegistrationEmailVerificationMail::class, function ($mail) use (&$code) {
            $code = $mail->verificationCode;

            return $mail->hasTo('applicant@example.test');
        });

        $this->assertIsString($code);
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $code);
        $this->assertSame(hash_hmac('sha256', $code, (string) config('app.key')), $tokenRow->token_hash);

        $this->post(route('resident.registration.access.authenticate'), [
            'reference' => $registration->public_id,
            'password' => 'password',
        ])->assertRedirect(route('resident.registration.application.show', [
            'reference' => $registration->public_id,
        ]));

        $this->post(route('resident.registration.application.email-verification', [
            'reference' => $registration->public_id,
        ]), ['code' => $code])->assertRedirect();

        $this->assertNotNull($registration->fresh()->email_verified_at);
        $this->assertSame('applicant@example.test', $verification->verifiedDecisionEmail($registration->fresh()));
        $this->assertDatabaseCount('residents', 0);
        $this->assertDatabaseCount('resident_accounts', 0);

        $this->from(route('resident.registration.application.show', [
            'reference' => $registration->public_id,
        ]))->post(route('resident.registration.application.email-verification', [
            'reference' => $registration->public_id,
        ]), ['code' => $code])
            ->assertRedirect(route('resident.registration.application.show', [
                'reference' => $registration->public_id,
            ]))
            ->assertSessionHasErrors('code');
    }

    public function test_registration_resend_uses_a_cooldown_and_generic_feedback(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000302',
        ]);
        $registration->update(['email' => 'applicant@example.test']);

        $response = $this->post(route('resident.registration.verification.resend.store'), [
            'reference' => $registration->public_id,
            'password' => 'password',
        ]);
        $response->assertRedirect(route('resident.registration.verification.resend'));
        $feedback = 'If the application reference and password are correct and a verification code is eligible, it will be sent when the resend limit allows.';
        $response->assertSessionHas('verification_feedback', $feedback);

        $this->post(route('resident.registration.verification.resend.store'), [
            'reference' => $registration->public_id,
            'password' => 'wrong-password',
        ])->assertRedirect(route('resident.registration.verification.resend'));
        $this->assertSame($feedback, session('verification_feedback'));

        $this->assertDatabaseCount('resident_registration_email_verification_tokens', 1);
        Mail::assertSent(ResidentRegistrationEmailVerificationMail::class, 1);
    }

    public function test_registration_email_otp_is_application_scoped_and_expires(): void
    {
        $first = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000320',
            'email' => 'first@example.test',
        ]);
        $second = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000321',
            'email' => 'second@example.test',
        ]);
        $verification = app(ResidentRegistrationEmailVerificationService::class);
        $this->assertTrue($verification->send($second));

        $code = null;
        Mail::assertSent(ResidentRegistrationEmailVerificationMail::class, function ($mail) use (&$code, $second) {
            if (! $mail->hasTo($second->email)) {
                return false;
            }
            $code = $mail->verificationCode;

            return true;
        });
        $this->assertIsString($code);

        $this->post(route('resident.registration.access.authenticate'), [
            'reference' => $first->public_id,
            'password' => 'password',
        ]);
        $this->post(route('resident.registration.application.email-verification', [
            'reference' => $first->public_id,
        ]), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertNull($first->fresh()->email_verified_at);
        $this->assertNull($second->fresh()->email_verified_at);

        $this->post(route('resident.registration.access.authenticate'), [
            'reference' => $second->public_id,
            'password' => 'password',
        ]);
        $this->travel(11)->minutes();
        $this->from(route('resident.registration.application.show', [
            'reference' => $second->public_id,
        ]))->post(route('resident.registration.application.email-verification', [
            'reference' => $second->public_id,
        ]), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertNull($second->fresh()->email_verified_at);
    }

    public function test_registration_email_otp_limits_failed_attempts(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000322',
            'email' => 'attempts@example.test',
        ]);
        $this->assertTrue(app(ResidentRegistrationEmailVerificationService::class)->send($registration));
        $code = null;
        Mail::assertSent(ResidentRegistrationEmailVerificationMail::class, function ($mail) use (&$code) {
            $code = $mail->verificationCode;

            return true;
        });
        $this->assertIsString($code);
        $this->post(route('resident.registration.access.authenticate'), [
            'reference' => $registration->public_id,
            'password' => 'password',
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->from(route('resident.registration.application.show', [
                'reference' => $registration->public_id,
            ]))->post(route('resident.registration.application.email-verification', [
                'reference' => $registration->public_id,
            ]), ['code' => '000000'])->assertSessionHasErrors('code');
        }

        $this->from(route('resident.registration.application.show', [
            'reference' => $registration->public_id,
        ]))->post(route('resident.registration.application.email-verification', [
            'reference' => $registration->public_id,
        ]), ['code' => $code])->assertSessionHasErrors('code');

        $this->assertNull($registration->fresh()->email_verified_at);
    }

    public function test_changing_registration_email_clears_verification_and_invalidates_outstanding_tokens(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000303',
        ]);
        $registration->update(['email' => 'old@example.test']);
        $verification = app(ResidentRegistrationEmailVerificationService::class);
        $this->assertTrue($verification->send($registration));

        $registration->forceFill(['email_verified_at' => now()])->save();
        $registration->update(['email' => 'new@example.test']);

        $this->assertNull($registration->fresh()->email_verified_at);
        $this->assertNotNull(
            ResidentRegistrationEmailVerificationToken::query()->firstOrFail()->consumed_at
        );
        $this->assertNull($verification->verifiedDecisionEmail($registration->fresh()));
    }

    public function test_admin_approval_does_not_require_registration_email_verification(): void
    {
        $candidate = $this->createResident($this->barangay, $this->purok, [
            'email' => 'applicant@example.test',
            'mobile' => '09170000304',
        ]);
        ResidentAccount::query()->where('resident_id', $candidate->id)->delete();
        $candidate->update(['source' => 'admin', 'email' => 'applicant@example.test']);

        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000304',
        ]);
        $registration->update(['email' => 'applicant@example.test']);
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->post('/admin/residents/'.$registration->id.'/approve', [
                'resident_id' => $candidate->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('approved', $registration->fresh()->status);
        $this->assertNull($registration->fresh()->email_verified_at);
        $this->assertDatabaseHas('resident_accounts', ['resident_id' => $candidate->id]);
    }

    public function test_optional_invalid_or_missing_qr_input_does_not_reject_registration_or_create_records(): void
    {
        foreach ([null, 'not-a-valid-credential'] as $index => $token) {
            $payload = $this->registrationPayload(310 + $index);
            if ($token !== null) {
                $payload['qr_token'] = $token;
            }

            $this->verifyRegistrationEmailForSubmission($payload['email']);
            $this->post(route('resident.register.store'), $payload)->assertRedirect();
        }

        $registrations = ResidentRegistration::query()
            ->whereIn('mobile', ['09980000310', '09980000311'])
            ->orderBy('mobile')
            ->get();

        $this->assertCount(2, $registrations);
        $this->assertNull($registrations[0]->qr_credential_id);
        $this->assertNull($registrations[1]->qr_credential_id);
        $this->assertSame(0, Resident::query()->count());
        $this->assertSame(0, ResidentAccount::query()->count());
        foreach ($registrations as $registration) {
            $this->assertArrayNotHasKey('qr_token', $registration->getAttributes());
            $this->assertStringNotContainsString('not-a-valid-credential', json_encode($registration->getAttributes()));
        }
    }

    public function test_valid_qr_resolves_only_as_a_barangay_scoped_admin_suggestion(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Qr',
            'last_name' => 'Candidate',
            'mobile' => '09170000320',
        ]);
        ResidentAccount::query()->where('resident_id', $resident->id)->delete();

        $token = 'KBQR-'.Str::random(64);
        $credential = ResidentQrCredential::create([
            'resident_id' => $resident->id,
            'token' => $token,
            'is_active' => true,
            'generated_at' => now(),
            'revoked_at' => null,
        ]);
        $matcher = app(ResidentRegistrationQrMatcher::class);
        $this->assertSame(
            $credential->id,
            $matcher->resolveCredential($token, $this->barangay->id)?->id,
        );
        $this->assertNull($matcher->resolveCredential($token, $this->otherBarangay->id));

        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000321',
        ]);
        $registration->forceFill(['qr_credential_id' => $credential->id])->save();
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->getJson('/admin/resident-registrations/'.$registration->id.'/candidates?q=NoMatchingName')
            ->assertOk()
            ->assertJsonPath('data.0.id', $resident->id)
            ->assertJsonPath('data.0.qr_suggested', true);

        $this->assertSame('pending', $registration->fresh()->status);
        $this->assertNull($registration->fresh()->matched_resident_id);
        $this->assertSame(2, Resident::query()->count());
    }

    public function test_registration_stores_only_a_valid_qr_reference_and_never_links_automatically(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170000325',
        ]);
        $token = 'KBQR-'.Str::random(64);
        $credential = ResidentQrCredential::create([
            'resident_id' => $resident->id,
            'token' => $token,
            'is_active' => true,
            'generated_at' => now(),
            'revoked_at' => null,
        ]);

        $payload = $this->registrationPayload(325);
        $this->verifyRegistrationEmailForSubmission($payload['email']);
        $this->post(route('resident.register.store'), [
            ...$payload,
            'qr_token' => $token,
        ])->assertRedirect();

        $registration = ResidentRegistration::query()
            ->where('mobile', '09980000325')
            ->firstOrFail();

        $this->assertSame($credential->id, $registration->qr_credential_id);
        $this->assertSame('pending', $registration->status);
        $this->assertNull($registration->matched_resident_id);
        $this->assertArrayNotHasKey('qr_token', $registration->getAttributes());
        $this->assertStringNotContainsString($token, json_encode($registration->getAttributes()));
        $this->assertSame(1, Resident::query()->count());
        $this->assertSame(1, ResidentAccount::query()->count());
    }

    public function test_qr_match_ignores_inactive_revoked_and_foreign_residents(): void
    {
        $activeResident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170000330',
        ]);
        $inactiveResident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170000331',
        ]);
        $inactiveResident->update(['status' => 'inactive']);
        $revokedResident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170000333',
        ]);
        $foreignResident = $this->createResident($this->otherBarangay, $this->otherPurok, [
            'mobile' => '09170000332',
        ]);

        $activeToken = 'KBQR-'.Str::random(64);
        $inactiveToken = 'KBQR-'.Str::random(64);
        $revokedToken = 'KBQR-'.Str::random(64);
        $foreignToken = 'KBQR-'.Str::random(64);
        foreach ([
            [$activeResident, $activeToken, true, null],
            [$inactiveResident, $inactiveToken, true, null],
            [$revokedResident, $revokedToken, false, now()],
            [$foreignResident, $foreignToken, true, null],
        ] as [$resident, $token, $active, $revokedAt]) {
            ResidentQrCredential::create([
                'resident_id' => $resident->id,
                'token' => $token,
                'is_active' => $active,
                'generated_at' => now(),
                'revoked_at' => $revokedAt,
            ]);
        }

        $matcher = app(ResidentRegistrationQrMatcher::class);
        $this->assertNotNull($matcher->resolveCredential($activeToken, $this->barangay->id));
        $this->assertNull($matcher->resolveCredential($inactiveToken, $this->barangay->id));
        $this->assertNull($matcher->resolveCredential($revokedToken, $this->barangay->id));
        $this->assertNull($matcher->resolveCredential($foreignToken, $this->barangay->id));
    }

    private function registrationPayload(int $suffix): array
    {
        return [
            'barangay_id' => $this->barangay->id,
            'purok_id' => $this->purok->id,
            'first_name' => 'Registration',
            'last_name' => 'Applicant '.$suffix,
            'birthdate' => '1995-01-01',
            'sex' => 'Female',
            'civil_status' => 'Single',
            'address_line' => 'Test Street',
            'mobile' => '09980000'.$suffix,
            'email' => 'registration'.$suffix.'@example.test',
            'sectors' => [],
            'password' => 'ResidentPassword123',
            'password_confirmation' => 'ResidentPassword123',
            'valid_id' => UploadedFile::fake()->create('valid-id.pdf', 20, 'application/pdf'),
            'live_identity_photo' => UploadedFile::fake()->createWithContent(
                'live-photo.png',
                base64_decode(self::PNG_1X1, true),
            ),
        ];
    }
}
