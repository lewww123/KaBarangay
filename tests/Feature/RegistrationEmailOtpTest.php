<?php

namespace Tests\Feature;

use App\Mail\RegistrationOtpMail;
use App\Models\ResidentRegistration;
use App\Support\RegistrationEmailOtp;
use App\Support\ResidentRegistrationEmailVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class RegistrationEmailOtpTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['registration.email_verification_required' => true]);
        $this->freezeTime();
        $this->withoutVite();
        Mail::fake();
        Storage::fake('local');
    }

    private function sendCode(string $email = 'resident@example.test'): string
    {
        $this->postJson('/register/send-otp', ['email' => $email])->assertOk()
            ->assertJsonPath('status', 'pending')->assertJsonPath('retry_after', 60);

        return Mail::sent(RegistrationOtpMail::class)->last()->verificationCode;
    }

    public function test_send_delivers_six_digits_and_caches_only_a_hash_for_ten_minutes(): void
    {
        $code = $this->sendCode(' Resident@Example.Test ');
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/D', $code);
        Mail::assertSent(RegistrationOtpMail::class, fn ($mail) => $mail->hasTo('resident@example.test'));
        $context = session(RegistrationEmailOtp::SESSION_KEY);
        $record = Cache::get('registration:email-otp:'.hash_hmac('sha256', $context['id'], config('app.key')));
        $this->assertSame(64, strlen($record['code_hash']));
        $this->assertNotSame($code, $record['code_hash']);
        $this->assertArrayNotHasKey('code', $record);
        $this->assertSame(600, $record['expires_at'] - now()->timestamp);
        $this->assertDatabaseCount('resident_registrations', 0);
        $this->assertDatabaseCount('resident_accounts', 0);
    }

    public function test_send_requires_a_valid_email(): void
    {
        $this->postJson('/register/send-otp', ['email' => 'not-email'])->assertUnprocessable()->assertJsonValidationErrors('email');
        Mail::assertNothingSent();
    }

    public function test_resend_is_blocked_for_sixty_seconds_and_replaces_the_old_code(): void
    {
        $oldCode = $this->sendCode();
        $previousChallenge = session(RegistrationEmailOtp::SESSION_KEY)['id'];
        $this->postJson('/register/send-otp', ['email' => 'resident@example.test'])->assertStatus(429)->assertHeader('Retry-After', '60');
        Mail::assertSent(RegistrationOtpMail::class, 1);
        $this->travel(61)->seconds();
        $newCode = $this->sendCode();
        $this->assertNotSame($previousChallenge, session(RegistrationEmailOtp::SESSION_KEY)['id']);
        // Random codes can coincide; a replaced challenge is identified by its
        // fresh nonce, so only assert old-code rejection when the values differ.
        if ($newCode !== $oldCode) {
            $this->postJson('/register/verify-otp', ['email' => 'resident@example.test', 'code' => $oldCode])->assertUnprocessable();
        }
        $this->postJson('/register/verify-otp', ['email' => 'resident@example.test', 'code' => $newCode])->assertOk()->assertJsonPath('status', 'verified');
    }

    public function test_wrong_and_expired_codes_do_not_verify(): void
    {
        $code = $this->sendCode();
        $wrong = $code === '000000' ? '111111' : '000000';
        $this->postJson('/register/verify-otp', ['email' => 'resident@example.test', 'code' => $wrong])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->travel(601)->seconds();
        $this->postJson('/register/verify-otp', ['email' => 'resident@example.test', 'code' => $code])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_otp_is_bound_to_its_email_and_browser_session(): void
    {
        $code = $this->sendCode();
        $this->postJson('/register/verify-otp', ['email' => 'other@example.test', 'code' => $code])->assertUnprocessable();
        $this->flushSession();
        $this->postJson('/register/verify-otp', ['email' => 'resident@example.test', 'code' => $code])->assertUnprocessable();
    }

    public function test_five_failed_attempts_require_a_fresh_otp(): void
    {
        $code = $this->sendCode();
        $wrong = $code === '000000' ? '111111' : '000000';
        foreach (range(1, 5) as $attempt) {
            $this->postJson('/register/verify-otp', ['email' => 'resident@example.test', 'code' => $wrong])->assertUnprocessable();
        }
        $this->postJson('/register/verify-otp', ['email' => 'resident@example.test', 'code' => $code])->assertUnprocessable();
    }

    public function test_verification_retries_do_not_extend_the_verified_session_proof(): void
    {
        $code = $this->sendCode();
        $this->postJson('/register/verify-otp', ['email' => 'resident@example.test', 'code' => $code])->assertOk()->assertJsonPath('expires_in', 600);
        $this->travel(30)->seconds();
        $this->postJson('/register/verify-otp', ['email' => 'resident@example.test', 'code' => $code])->assertOk()->assertJsonPath('expires_in', 570);
        $this->travel(571)->seconds();
        $this->assertFalse(app(RegistrationEmailOtp::class)->isVerified(request(), 'resident@example.test'));
    }

    public function test_registration_rejects_an_unverified_email_and_forged_verified_flags(): void
    {
        $this->postJson('/resident/register', [...$this->payload(), 'email_verified' => true, 'email_verified_at' => now()->toIso8601String()])
            ->assertUnprocessable()->assertJsonValidationErrors('email_verification');
        $this->assertDatabaseCount('resident_registrations', 0);
        $this->assertDatabaseCount('resident_accounts', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_verified_email_can_submit_once_and_persists_verification_evidence(): void
    {
        $payload = $this->payload();
        $code = $this->sendCode();
        $this->postJson('/register/verify-otp', ['email' => $payload['email'], 'code' => $code])->assertOk();
        $this->post('/resident/register', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $registration = ResidentRegistration::firstOrFail();
        $this->assertSame('pending', $registration->status);
        $this->assertNotNull($registration->email_verified_at);
        $this->assertSame($payload['email'], app(ResidentRegistrationEmailVerificationService::class)->verifiedDecisionEmail($registration));
        $this->assertNull(session(RegistrationEmailOtp::SESSION_KEY));
        $this->assertDatabaseCount('resident_accounts', 0);
        $this->assertDatabaseCount('resident_registration_email_verification_tokens', 1);
        $this->postJson('/register/verify-otp', ['email' => $payload['email'], 'code' => $code])->assertUnprocessable();
    }

    public function test_verified_proof_does_not_authorize_a_different_email(): void
    {
        $payload = $this->payload();
        $code = $this->sendCode();
        $this->postJson('/register/verify-otp', ['email' => $payload['email'], 'code' => $code])->assertOk();
        $this->postJson('/resident/register', [...$payload, 'email' => 'changed@example.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('email_verification');
        $this->assertDatabaseCount('resident_registrations', 0);
    }

    public function test_failed_registration_validation_keeps_the_verified_proof_available(): void
    {
        $payload = $this->payload();
        $code = $this->sendCode();
        $this->postJson('/register/verify-otp', ['email' => $payload['email'], 'code' => $code])->assertOk();
        $this->postJson('/resident/register', [...$payload, 'password_confirmation' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->post('/resident/register', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('resident_registrations', 1);
    }

    public function test_expired_verification_cannot_submit_even_with_a_verified_client_flag(): void
    {
        $payload = $this->payload();
        $code = $this->sendCode();
        $this->postJson('/register/verify-otp', ['email' => $payload['email'], 'code' => $code])->assertOk();
        $this->travel(601)->seconds();
        $this->postJson('/resident/register', [...$payload, 'email_verified' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('email_verification');
        $this->assertDatabaseCount('resident_registrations', 0);
    }

    public function test_failed_registration_transaction_preserves_proof_for_a_successful_retry(): void
    {
        $payload = $this->payload();
        $code = $this->sendCode();
        $this->postJson('/register/verify-otp', ['email' => $payload['email'], 'code' => $code])->assertOk();
        $fail = true;
        ResidentRegistration::creating(function () use (&$fail): void {
            if ($fail) {
                throw new RuntimeException('Simulated registration failure');
            }
        });
        $this->post('/resident/register', $payload)->assertSessionHasErrors('registration');
        $this->assertDatabaseCount('resident_registrations', 0);
        $fail = false;
        $this->post('/resident/register', $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('resident_registrations', 1);
    }

    public function test_mail_failure_returns_error_without_marking_email_verified_or_starting_a_cooldown(): void
    {
        $pending = Mockery::mock(PendingMail::class);
        Mail::shouldReceive('to')->with('resident@example.test')->twice()->andReturn($pending);
        $pending->shouldReceive('send')->twice()->andThrow(new RuntimeException('Simulated SMTP failure'));
        $this->postJson('/register/send-otp', ['email' => 'resident@example.test'])->assertStatus(503);
        $this->postJson('/register/send-otp', ['email' => 'resident@example.test'])->assertStatus(503);
        $this->assertNull(session(RegistrationEmailOtp::SESSION_KEY));
    }

    public function test_registration_can_submit_without_otp_and_without_sending_email(): void
    {
        config(['registration.email_verification_required' => false]);
        $payload = $this->payload();
        $this->post('/resident/register', [...$payload, 'email_verified' => true])->assertRedirect()->assertSessionHasNoErrors();
        $registration = ResidentRegistration::firstOrFail();
        $this->assertSame('pending', $registration->status);
        $this->assertSame($payload['email'], $registration->email);
        $this->assertNull($registration->email_verified_at);
        $this->assertDatabaseCount('resident_registration_email_verification_tokens', 0);
        $this->assertDatabaseCount('resident_accounts', 0);
        Mail::assertNothingSent();
        $this->postJson('/register/send-otp', ['email' => $payload['email']])->assertStatus(409)->assertJsonPath('email_verification_required', false)->assertJsonPath('reload_required', true);
    }

    public function test_email_format_is_still_validated_when_otp_is_disabled(): void
    {
        config(['registration.email_verification_required' => false]);
        $payload = $this->payload();
        $this->postJson('/resident/register', [...$payload, 'email' => 'invalid-email'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('resident_registrations', 0);
        Mail::assertNothingSent();
    }

    private function payload(): array
    {
        $barangay = $this->createBarangay('OTP Registration', 'OTP-REG');
        $purok = $this->createPurok($barangay);

        return [
            'barangay_id' => $barangay->id, 'purok_id' => $purok->id,
            'first_name' => 'OTP', 'last_name' => 'Applicant', 'birthdate' => '1990-01-01',
            'sex' => 'Female', 'civil_status' => 'Single', 'address_line' => 'Test Street',
            'mobile' => '09980000701', 'email' => 'resident@example.test',
            'password' => 'ResidentPassword123', 'password_confirmation' => 'ResidentPassword123',
            'valid_id' => UploadedFile::fake()->create('valid-id.pdf', 20, 'application/pdf'),
            'live_identity_photo' => UploadedFile::fake()->createWithContent('live.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==')),
        ];
    }
}
