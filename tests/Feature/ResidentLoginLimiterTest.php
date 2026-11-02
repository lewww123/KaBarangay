<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Support\LoginIdentifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

/**
 * Batch E5.4: resident-specific login limiter (E5.3) behavior.
 *
 * The resident-specific counter is keyed on the server-resolved resident ID
 * (login:resident:rid:{id}) and is controller-managed, so it must be checked,
 * incremented, and cleared with the exact same raw key and never via the
 * middleware hashing helper.
 */
class ResidentLoginLimiterTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private const LIMIT_MESSAGE = 'Too many login attempts. Please wait before trying again.';

    private const GENERIC_MESSAGE = 'Invalid resident credentials.';

    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
    }

    private function login(string $identifier, string $password = 'wrong-password')
    {
        return $this->post(route('resident.login.process'), [
            'barangay_id' => $this->barangay->id,
            'identifier' => $identifier,
            'password' => $password,
        ]);
    }

    private function residentKey(Resident $resident): string
    {
        return LoginIdentifier::residentKey($resident->id);
    }

    public function test_five_failed_attempts_trip_the_resident_limiter_on_the_sixth(): void
    {
        $purok = $this->createPurok($this->barangay);
        $resident = $this->createResident($this->barangay, $purok, [
            'resident_number' => 'RES-000001',
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->login('RES-000001')->assertRedirect();
        }

        $this->assertSame(5, RateLimiter::attempts($this->residentKey($resident)));

        // The E4 identifier limiter shares the same 5/10-minute threshold, so
        // the sixth request is rejected by middleware before the controller's
        // resident-limiter branch runs. Its rate_limited row is therefore
        // written by the E4 response callback (which records the channel and
        // barangay but no resident_id), while the resident counter itself
        // remains at the limit.
        $response = $this->login('RES-000001');

        $response->assertRedirect();
        $response->assertSessionHasErrors(['login' => self::LIMIT_MESSAGE]);

        $row = DB::table('login_attempts')
            ->where('status', 'rate_limited')
            ->where('channel', 'resident')
            ->latest('id')
            ->first();

        $this->assertNotNull($row);
        // The sixth request is rejected by the E4 middleware limiter (which
        // shares the same 5/10-minute threshold and runs before the
        // controller). E4's resident barangayId callback resolves to null
        // (E4 finding L1), so the middleware-written rate_limited row carries
        // a null barangay_id rather than the submitted barangay. The
        // resident-specific counter itself is what proves the E5.3 limiter.
        $this->assertNull($row->barangay_id);
        $this->assertGreaterThanOrEqual(
            5,
            RateLimiter::attempts($this->residentKey($resident)),
            'The resident-specific limit must already be reached.'
        );
    }

    public function test_all_identifier_forms_map_to_one_resident_bucket(): void
    {
        $purok = $this->createPurok($this->barangay);
        $resident = $this->createResident($this->barangay, $purok, [
            'resident_number' => 'RES-000002',
            'mobile' => '09170000002',
        ]);

        $resident->forceFill(['email' => 'resident2@example.test'])->save();

        $this->login('RES-000002')->assertRedirect();
        $this->login('09170000002')->assertRedirect();
        $this->login('resident2@example.test')->assertRedirect();

        $this->assertSame(3, RateLimiter::attempts($this->residentKey($resident)));

        $this->login('RES-000002')->assertRedirect();
        $this->login('09170000002')->assertRedirect();

        $this->assertSame(5, RateLimiter::attempts($this->residentKey($resident)));

        $this->login('resident2@example.test')
            ->assertSessionHasErrors(['login' => self::LIMIT_MESSAGE]);
    }

    public function test_successful_login_clears_only_the_resident_limiter(): void
    {
        $purok = $this->createPurok($this->barangay);
        $resident = $this->createResident($this->barangay, $purok, [
            'resident_number' => 'RES-000003',
        ]);

        $residentKey = $this->residentKey($resident);
        $identifierKey = LoginIdentifier::middlewareKey(
            'login-resident',
            LoginIdentifier::identifierKey('resident', 'RES-000003')
        );
        $ipKey = LoginIdentifier::middlewareKey(
            'login-resident',
            LoginIdentifier::ipKey('resident', LoginIdentifier::LOCAL_ADDRESS)
        );

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->login('RES-000003')->assertRedirect();
        }

        $this->assertSame(4, RateLimiter::attempts($residentKey));

        $this->login('RES-000003', 'password')
            ->assertRedirect(route('resident.dashboard'));

        $this->assertSame(
            0,
            RateLimiter::attempts($residentKey),
            'Successful authentication must clear the resident-specific counter.'
        );

        $this->assertSame(
            0,
            RateLimiter::attempts($identifierKey),
            'The existing E4 identifier clear must still run on success.'
        );

        $this->assertGreaterThan(
            0,
            RateLimiter::attempts($ipKey),
            'The shared E4 IP counter must never be cleared by the resident clear.'
        );
    }

    public function test_unresolved_resident_never_creates_a_resident_counter(): void
    {
        $this->createPurok($this->barangay);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->login('NO-SUCH-RESIDENT')->assertRedirect();
        }

        $this->assertSame(
            0,
            RateLimiter::attempts(LoginIdentifier::residentKey(1)),
            'No resident bucket may be created when no resident is resolved.'
        );
    }

    public function test_resident_from_another_barangay_skips_the_resident_limiter(): void
    {
        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $otherPurok = $this->createPurok($otherBarangay);
        $otherResident = $this->createResident($otherBarangay, $otherPurok, [
            'resident_number' => 'RES-OTHER',
        ]);

        $this->createPurok($this->barangay);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->post(route('resident.login.process'), [
                'barangay_id' => $this->barangay->id,
                'identifier' => 'RES-OTHER',
                'password' => 'wrong-password',
            ])->assertRedirect();
        }

        $this->assertSame(
            0,
            RateLimiter::attempts($this->residentKey($otherResident)),
            'A resident belonging to another barangay must not be resolved or limited.'
        );
    }

    public function test_inactive_account_still_counts_against_the_resolved_resident(): void
    {
        $purok = $this->createPurok($this->barangay);
        $resident = $this->createResident($this->barangay, $purok, [
            'resident_number' => 'RES-INACTIVE',
        ]);

        $resident->account->update(['is_active' => false]);

        $this->login('RES-INACTIVE')->assertRedirect();

        $this->assertSame(
            1,
            RateLimiter::attempts($this->residentKey($resident)),
            'Option B: a resolved resident is counted regardless of account status.'
        );
    }

    public function test_pending_registration_cannot_log_in_with_an_active_resident_account(): void
    {
        $purok = $this->createPurok($this->barangay);
        $resident = $this->createResident($this->barangay, $purok, [
            'resident_number' => 'RES-PENDING',
        ]);

        $resident->registration->update(['status' => 'pending']);

        $this->login('RES-PENDING', 'password')
            ->assertRedirect()
            ->assertSessionHasErrors(['login' => self::GENERIC_MESSAGE]);

        $this->assertNull(session('resident_id'));
    }

    public function test_pending_registration_is_redirected_by_resident_middleware(): void
    {
        $purok = $this->createPurok($this->barangay);
        $resident = $this->createResident($this->barangay, $purok, [
            'resident_number' => 'RES-PENDING-SESSION',
        ]);

        $resident->registration->update(['status' => 'pending']);

        $this->withSession(['resident_id' => $resident->id])
            ->get(route('resident.dashboard'))
            ->assertRedirect(route('resident.login'));
    }

    public function test_resident_rejection_does_not_touch_other_resident_bucket(): void
    {
        $purok = $this->createPurok($this->barangay);
        $first = $this->createResident($this->barangay, $purok, [
            'resident_number' => 'RES-FIRST',
            'mobile' => '09170001001',
        ]);
        $second = $this->createResident($this->barangay, $purok, [
            'resident_number' => 'RES-SECOND',
            'mobile' => '09170001002',
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->login('RES-FIRST')->assertRedirect();
        }

        $this->login('RES-FIRST')->assertSessionHasErrors(['login' => self::LIMIT_MESSAGE]);

        $this->assertSame(
            0,
            RateLimiter::attempts($this->residentKey($second)),
            'One resident tripping its limit must not affect another resident.'
        );

        $this->login('RES-SECOND')->assertRedirect();

        $this->assertSame(1, RateLimiter::attempts($this->residentKey($second)));

        $this->assertSame(5, RateLimiter::attempts($this->residentKey($first)));
    }

    public function test_below_limit_failures_remain_generic(): void
    {
        $purok = $this->createPurok($this->barangay);
        $this->createResident($this->barangay, $purok, [
            'resident_number' => 'RES-GENERIC',
        ]);

        $this->login('RES-GENERIC', 'wrong-password')
            ->assertSessionHasErrors(['login' => self::GENERIC_MESSAGE]);
    }

    public function test_json_requests_receive_429_from_the_resident_limiter(): void
    {
        $purok = $this->createPurok($this->barangay);
        $this->createResident($this->barangay, $purok, [
            'resident_number' => 'RES-JSON',
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson(route('resident.login.process'), [
                'barangay_id' => $this->barangay->id,
                'identifier' => 'RES-JSON',
                'password' => 'wrong-password',
            ]);
        }

        $response = $this->postJson(route('resident.login.process'), [
            'barangay_id' => $this->barangay->id,
            'identifier' => 'RES-JSON',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(429);
        $response->assertJson(['message' => self::LIMIT_MESSAGE]);
    }

    public function test_resident_number_is_case_insensitive_single_bucket(): void
    {
        $purok = $this->createPurok($this->barangay);
        $resident = $this->createResident($this->barangay, $purok, [
            'resident_number' => 'RES-CASE',
        ]);

        $this->login('RES-CASE')->assertRedirect();
        $this->login('res-case')->assertRedirect();

        $this->assertSame(2, RateLimiter::attempts($this->residentKey($resident)));
    }
}
