<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Purok;
use App\Models\Resident;
use App\Models\ResidentQrCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

/**
 * Staff QR token resolution API (GET /api/qr/verify-token).
 *
 * Resolves a scanned token (an active, non-revoked QR credential or a resident
 * number) to a resident within the caller's barangay. Requires authentication
 * and one of the staff roles; it is rate limited per authenticated user.
 */
class StaffQrTokenResolutionTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Purok $purok;

    protected function setUp(): void
    {
        parent::setUp();

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $this->purok = $this->createPurok($this->barangay, 'Purok 1');
    }

    private function makeCredential(Resident $resident, array $attributes = []): ResidentQrCredential
    {
        return ResidentQrCredential::create([
            'resident_id' => $resident->id,
            'token' => $attributes['token'] ?? 'KBQR-'.Str::random(64),
            'is_active' => $attributes['is_active'] ?? true,
            'generated_at' => now(),
            'revoked_at' => $attributes['revoked_at'] ?? null,
        ]);
    }

    public function test_valid_active_token_resolves_resident(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170003001']);
        $credential = $this->makeCredential($resident);

        $this->actingAs($clerk)
            ->getJson('/api/qr/verify-token?token='.$credential->token)
            ->assertOk()
            ->assertJsonPath('resident.id', $resident->id)
            ->assertJsonPath('resident.full_name', $resident->full_name)
            ->assertJsonPath('resident.resident_number', $resident->resident_number);
    }

    public function test_invalid_token_is_not_found(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->getJson('/api/qr/verify-token?token=KBQR-'.Str::random(64))
            ->assertStatus(404);
    }

    public function test_inactive_credential_is_not_found(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170003002']);
        $credential = $this->makeCredential($resident, ['is_active' => false]);

        $this->actingAs($clerk)
            ->getJson('/api/qr/verify-token?token='.$credential->token)
            ->assertStatus(404);
    }

    public function test_revoked_credential_is_not_found(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170003003']);
        $credential = $this->makeCredential($resident, ['revoked_at' => now()]);

        $this->actingAs($clerk)
            ->getJson('/api/qr/verify-token?token='.$credential->token)
            ->assertStatus(404);
    }

    public function test_inactive_resident_is_not_found(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170003004']);
        $resident->update(['status' => 'inactive']);
        $credential = $this->makeCredential($resident);

        $this->actingAs($clerk)
            ->getJson('/api/qr/verify-token?token='.$credential->token)
            ->assertStatus(404);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/qr/verify-token?token=TEST')
            ->assertStatus(401);
    }

    public function test_unauthorized_role_is_forbidden(): void
    {
        $lupon = $this->createStaff($this->barangay, 'lupon');

        $this->actingAs($lupon)
            ->getJson('/api/qr/verify-token?token=TEST')
            ->assertStatus(403);
    }

    public function test_token_resolution_is_rate_limited_per_user(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170003005']);
        $credential = $this->makeCredential($resident);

        $url = '/api/qr/verify-token?token='.$credential->token;

        for ($i = 0; $i < 60; $i++) {
            $this->actingAs($clerk)->getJson($url)->assertOk();
        }

        $this->actingAs($clerk)->getJson($url)->assertStatus(429);
    }

    /*
    |--------------------------------------------------------------------------
    | Verification URL payloads
    |--------------------------------------------------------------------------
    |
    | A resident QR code encodes the public verification URL rather than the
    | raw credential token, so the scanner submits the whole URL. The token
    | is taken from that exact path shape, and every other payload keeps its
    | previous meaning.
    |
    */

    private function verificationUrl(string $token, string $host = 'localhost'): string
    {
        return 'http://'.$host.'/verify/resident/'.$token;
    }

    public function test_full_verification_url_resolves_resident(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170003006']);
        $credential = $this->makeCredential($resident);

        $url = route('resident.qr.verify', ['token' => $credential->token]);

        $this->actingAs($clerk)
            ->getJson('/api/qr/verify-token?token='.$url)
            ->assertOk()
            ->assertJsonPath('resident.id', $resident->id)
            ->assertJsonPath('resident.full_name', $resident->full_name)
            ->assertJsonPath('resident.resident_number', $resident->resident_number);
    }

    public function test_url_encoded_verification_url_resolves_resident(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170003007']);
        $credential = $this->makeCredential($resident);

        // This is how the scanner client actually sends the scanned URL.
        $url = route('resident.qr.verify', ['token' => $credential->token]);

        $this->actingAs($clerk)
            ->getJson('/api/qr/verify-token?token='.urlencode($url))
            ->assertOk()
            ->assertJsonPath('resident.id', $resident->id)
            ->assertJsonPath('resident.full_name', $resident->full_name);
    }

    public function test_foreign_origin_verification_url_resolves_resident(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170003008']);
        $credential = $this->makeCredential($resident);

        // The credential token is the secret, not the host that rendered the
        // QR code, so a verification URL from another host still resolves.
        $url = $this->verificationUrl($credential->token, 'other-host.example');

        $this->actingAs($clerk)
            ->getJson('/api/qr/verify-token?token='.urlencode($url))
            ->assertOk()
            ->assertJsonPath('resident.id', $resident->id)
            ->assertJsonPath('resident.full_name', $resident->full_name);
    }

    public function test_verification_url_with_query_and_fragment_resolves_resident(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170003009']);
        $credential = $this->makeCredential($resident);

        $url = $this->verificationUrl($credential->token).'?source=scanner#scan';

        $this->actingAs($clerk)
            ->getJson('/api/qr/verify-token?token='.urlencode($url))
            ->assertOk()
            ->assertJsonPath('resident.id', $resident->id);
    }

    public function test_relative_verification_paths_resolve_resident(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170003010']);
        $credential = $this->makeCredential($resident);

        $paths = [
            '//localhost/verify/resident/'.$credential->token,
            '/verify/resident/'.$credential->token,
        ];

        foreach ($paths as $path) {
            $this->actingAs($clerk)
                ->getJson('/api/qr/verify-token?token='.urlencode($path))
                ->assertOk()
                ->assertJsonPath('resident.id', $resident->id);
        }
    }

    public function test_verification_url_token_segment_is_decoded_exactly_once(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170003011']);

        // The QR encodes the token percent-encoded, so the segment has to be
        // decoded once. The second token keeps a literal "%20", which a
        // second decode would consume and break the match.
        $credentials = [
            $this->makeCredential($resident, ['token' => 'KBQR-enc oded '.Str::random(8)]),
        ];

        $second = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170003012']);
        $credentials[] = $this->makeCredential($second, ['token' => 'KBQR-double%20decode '.Str::random(8)]);

        foreach ($credentials as $credential) {
            $url = $this->verificationUrl(rawurlencode($credential->token));

            $this->actingAs($clerk)
                ->getJson('/api/qr/verify-token?token='.urlencode($url))
                ->assertOk()
                ->assertJsonPath('resident.id', $credential->resident_id);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function unusableVerificationPayloadProvider(): array
    {
        return [
            'empty token segment' => ['http://localhost/verify/resident/'],
            'extra path segment' => ['http://localhost/verify/resident/{token}/extra'],
            'unrelated path' => ['http://localhost/resident/{token}'],
            'near miss plural path' => ['http://localhost/verify/residents/{token}'],
            'prefixed path' => ['http://localhost/prefix/verify/resident/{token}'],
            'malformed url' => ['http://'],
        ];
    }

    #[DataProvider('unusableVerificationPayloadProvider')]
    public function test_malformed_or_unrelated_verification_paths_are_not_found(string $payload): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170003013']);
        $credential = $this->makeCredential($resident);

        $url = str_replace('{token}', $credential->token, $payload);

        $response = $this->actingAs($clerk)
            ->getJson('/api/qr/verify-token?token='.urlencode($url));

        $response->assertStatus(404);
        $this->assertArrayNotHasKey('resident', $response->json());
    }

    public function test_verification_url_for_revoked_credential_is_not_found(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170003014']);
        $credential = $this->makeCredential($resident, ['revoked_at' => now()]);

        $url = $this->verificationUrl($credential->token);

        $response = $this->actingAs($clerk)
            ->getJson('/api/qr/verify-token?token='.urlencode($url));

        $response->assertStatus(404);
        $this->assertArrayNotHasKey('resident', $response->json());
    }

    public function test_verification_url_for_inactive_credential_is_not_found(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170003015']);
        $credential = $this->makeCredential($resident, ['is_active' => false]);

        $url = $this->verificationUrl($credential->token);

        $response = $this->actingAs($clerk)
            ->getJson('/api/qr/verify-token?token='.urlencode($url));

        $response->assertStatus(404);
        $this->assertArrayNotHasKey('resident', $response->json());
    }

    public function test_verification_url_for_inactive_resident_is_not_found(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170003016']);
        $resident->update(['status' => 'inactive']);
        $credential = $this->makeCredential($resident);

        $url = $this->verificationUrl($credential->token);

        $response = $this->actingAs($clerk)
            ->getJson('/api/qr/verify-token?token='.urlencode($url));

        $response->assertStatus(404);
        $this->assertArrayNotHasKey('resident', $response->json());
    }

    public function test_verification_url_for_foreign_barangay_resident_is_not_found(): void
    {
        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $otherPurok = $this->createPurok($otherBarangay, 'Purok 2');

        $clerk = $this->createStaff($this->barangay, 'clerk');
        $foreignResident = $this->createResident($otherBarangay, $otherPurok, ['mobile' => '09170004001']);
        $foreignCredential = $this->makeCredential($foreignResident);

        $url = $this->verificationUrl($foreignCredential->token);

        $response = $this->actingAs($clerk)
            ->getJson('/api/qr/verify-token?token='.urlencode($url));

        $response->assertStatus(404);
        $this->assertArrayNotHasKey('resident', $response->json());
    }
}
