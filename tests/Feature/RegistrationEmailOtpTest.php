<?php
namespace Tests\Feature;
use App\Models\ResidentRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;
class RegistrationEmailOtpTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;
    protected function setUp(): void { parent::setUp(); $this->withoutVite(); Storage::fake('local'); Mail::fake(); }
    public function test_optional_email_and_unverified_mobile_submission_without_otp(): void
    {
        foreach ([null, '   ', 'optional@example.test'] as $index => $email) {
            $payload = $this->payload((string) $index);
            $payload['email'] = $email;
            $payload['last_name'] = 'Applicant'.$index;
            $payload['mobile'] = '0998000070'.$index;
            $payload['mobile_verified_at'] = now()->toIso8601String();
            $payload['email_verified_at'] = now()->toIso8601String();
            $payload['email_verified'] = true;
            $payload['status'] = 'approved';
            $this->post('/resident/register', $payload)->assertSessionHasNoErrors()->assertRedirect(route('resident.registration.submitted'));
            $registration = ResidentRegistration::where('mobile', $payload['mobile'])->sole();
            $this->assertSame($index < 2 ? null : $email, $registration->email);
            $this->assertSame('pending', $registration->status);
            $this->assertNull($registration->mobile_verified_at);
            $this->assertNull($registration->email_verified_at);
        }
        $this->assertDatabaseCount('resident_accounts', 0);
        $this->assertDatabaseCount('resident_registration_email_verification_tokens', 0);
        Mail::assertNothingSent();
    }
    public function test_removed_registration_otp_endpoints_cannot_send_or_verify(): void
    {
        foreach (['/register/send-otp', '/register/verify-otp', '/resident/register/verification/resend', '/resident/application/123/email-verification'] as $path) {
            $this->postJson($path, ['email' => 'test@example.test', 'code' => '123456'])->assertNotFound();
        }
        Mail::assertNothingSent();
    }
    public function test_invalid_email_required_mobile_and_documents_remain_enforced(): void
    {
        $payload = $this->payload(); $payload['email'] = 'invalid'; $payload['mobile'] = '';
        unset($payload['valid_id'], $payload['live_identity_photo']);
        $this->postJson('/resident/register', $payload)->assertUnprocessable()->assertJsonValidationErrors(['email', 'mobile', 'valid_id', 'live_identity_photo']);
    }

    public function test_valid_id_rejects_pdf_and_accepts_camera_captured_image_only(): void
    {
        $payload = $this->payload();
        $payload['valid_id'] = UploadedFile::fake()->create('valid-id.pdf', 20, 'application/pdf');
        $this->postJson('/resident/register', $payload)->assertUnprocessable()->assertJsonValidationErrors('valid_id');

        $payload = $this->payload('img');
        $payload['valid_id'] = UploadedFile::fake()->createWithContent('valid-id.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $this->post('/resident/register', $payload)->assertSessionHasNoErrors()->assertRedirect(route('resident.registration.submitted'));
    }
    public function test_duplicate_email_and_mobile_still_reject_existing_applicants(): void
    {
        $payload = $this->payload();
        $this->post('/resident/register', $payload)->assertSessionHasNoErrors();
        $second = $payload; $second['first_name'] = 'Different'; $second['mobile'] = '09980000799';
        $this->postJson('/resident/register', $second)->assertUnprocessable()->assertJsonValidationErrors('registration');
        $second['email'] = null; $second['mobile'] = $payload['mobile'];
        $this->postJson('/resident/register', $second)->assertUnprocessable()->assertJsonValidationErrors('registration');
    }
    private function payload(string $codeSuffix = ''): array
    {
        $barangay = $this->createBarangay('OTP Registration', 'OTP-REG'.$codeSuffix);
        $purok = $this->createPurok($barangay);

        return [
            'barangay_id' => $barangay->id, 'purok_id' => $purok->id,
            'first_name' => 'OTP', 'last_name' => 'Applicant', 'birthdate' => '1990-01-01',
            'sex' => 'Female', 'civil_status' => 'Single', 'address_line' => 'Test Street',
            'mobile' => '09980000701', 'email' => 'resident@example.test',
            'password' => 'ResidentPassword123', 'password_confirmation' => 'ResidentPassword123',
            'valid_id' => UploadedFile::fake()->createWithContent('valid-id.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==')),
            'live_identity_photo' => UploadedFile::fake()->createWithContent('live.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==')),
        ];
    }
}
