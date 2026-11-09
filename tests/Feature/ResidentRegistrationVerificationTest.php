<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Purok;
use App\Models\Resident;
use App\Models\ResidentAccount;
use App\Models\ResidentQrCredential;
use App\Models\ResidentRegistration;
use App\Models\ResidentRegistrationEmailVerificationToken;
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

        $this->withoutVite();
        Storage::fake('local');
        Mail::fake();

        $this->barangay = $this->createBarangay('Verification Barangay', 'VERIFY-ONE');
        $this->otherBarangay = $this->createBarangay('Other Barangay', 'VERIFY-TWO');
        $this->purok = $this->createPurok($this->barangay);
        $this->otherPurok = $this->createPurok($this->otherBarangay, 'Purok 2');
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
        $this->assertNull($registration->fresh()->mobile_verified_at);
        $this->assertNull($candidate->fresh()->mobile_verified_at);
        $this->assertDatabaseHas('resident_accounts', ['resident_id' => $candidate->id]);
    }

    public function test_optional_invalid_or_missing_qr_input_does_not_reject_registration_or_create_records(): void
    {
        foreach ([null, 'not-a-valid-credential'] as $index => $token) {
            $payload = $this->registrationPayload(310 + $index);
            if ($token !== null) {
                $payload['qr_token'] = $token;
            }

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
            'valid_id' => UploadedFile::fake()->createWithContent(
                'valid-id.png',
                base64_decode(self::PNG_1X1, true),
            ),
            'live_identity_photo' => UploadedFile::fake()->createWithContent(
                'live-photo.png',
                base64_decode(self::PNG_1X1, true),
            ),
        ];
    }
}
