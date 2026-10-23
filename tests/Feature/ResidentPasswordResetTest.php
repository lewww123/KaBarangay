<?php

namespace Tests\Feature;

use App\Mail\ResidentPasswordResetMail;
use App\Models\Barangay;
use App\Models\Purok;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class ResidentPasswordResetTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Purok $purok;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->barangay = $this->createBarangay('Barangay Test', 'BRGY-TEST');
        $this->purok = $this->createPurok($this->barangay);
    }

    public function test_forgot_password_page_renders(): void
    {
        $this->get('/resident/forgot-password')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Resident/ForgotPassword'));
    }

    public function test_reset_password_page_renders(): void
    {
        $this->get('/resident/reset-password/some-token?email=resident@example.com')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Resident/ResetPassword')
                ->where('token', 'some-token')
                ->where('email', 'resident@example.com'));
    }

    public function test_reset_link_is_emailed_to_active_resident(): void
    {
        Mail::fake();

        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170000031']);
        $resident->update(['email' => 'reset-me@example.com']);

        $this->post('/resident/forgot-password', ['email' => 'reset-me@example.com'])
            ->assertRedirect('/resident/forgot-password')
            ->assertSessionHas('success');

        Mail::assertSent(ResidentPasswordResetMail::class);

        $this->assertDatabaseHas('resident_password_reset_tokens', [
            'resident_id' => $resident->id,
            'email' => 'reset-me@example.com',
        ]);
    }

    public function test_unknown_email_sends_no_mail_and_leaks_nothing(): void
    {
        Mail::fake();

        $this->post('/resident/forgot-password', ['email' => 'nobody@example.com'])
            ->assertRedirect('/resident/forgot-password')
            ->assertSessionHas('success');

        Mail::assertNothingSent();
        $this->assertDatabaseCount('resident_password_reset_tokens', 0);
    }

    public function test_valid_token_resets_the_resident_password(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170000032']);
        $resident->update(['email' => 'valid-reset@example.com']);

        $token = 'valid-reset-token';

        DB::table('resident_password_reset_tokens')->insert([
            'resident_id' => $resident->id,
            'email' => 'valid-reset@example.com',
            'token' => Hash::make($token),
            'created_at' => now(),
        ]);

        $this->post('/resident/reset-password', [
            'token' => $token,
            'email' => 'valid-reset@example.com',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertRedirect('/resident/login');

        $this->assertTrue(
            Hash::check('brand-new-password', $resident->account()->first()->password)
        );

        $this->assertDatabaseCount('resident_password_reset_tokens', 0);
    }

    public function test_invalid_token_is_rejected(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170000033']);
        $resident->update(['email' => 'invalid-reset@example.com']);

        DB::table('resident_password_reset_tokens')->insert([
            'resident_id' => $resident->id,
            'email' => 'invalid-reset@example.com',
            'token' => Hash::make('the-real-token'),
            'created_at' => now(),
        ]);

        $this->post('/resident/reset-password', [
            'token' => 'wrong-token',
            'email' => 'invalid-reset@example.com',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(
            Hash::check('password', $resident->account()->first()->password)
        );
    }

    public function test_expired_token_is_rejected_and_removed(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170000034']);
        $resident->update(['email' => 'expired-reset@example.com']);

        $token = 'expired-reset-token';

        DB::table('resident_password_reset_tokens')->insert([
            'resident_id' => $resident->id,
            'email' => 'expired-reset@example.com',
            'token' => Hash::make($token),
            'created_at' => now()->subMinutes(61),
        ]);

        $this->post('/resident/reset-password', [
            'token' => $token,
            'email' => 'expired-reset@example.com',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertSessionHasErrors('email');

        $this->assertDatabaseCount('resident_password_reset_tokens', 0);
    }

    public function test_shared_email_across_barangays_issues_distinct_tokens(): void
    {
        Mail::fake();

        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $otherPurok = $this->createPurok($otherBarangay, 'Purok 2');

        $first = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170000035']);
        $second = $this->createResident($otherBarangay, $otherPurok, ['mobile' => '09170000036']);

        $first->update(['email' => 'shared@example.com']);
        $second->update(['email' => 'shared@example.com']);

        $this->post('/resident/forgot-password', ['email' => 'shared@example.com'])
            ->assertRedirect('/resident/forgot-password');

        Mail::assertSent(ResidentPasswordResetMail::class, 2);

        $this->assertDatabaseHas('resident_password_reset_tokens', ['resident_id' => $first->id]);
        $this->assertDatabaseHas('resident_password_reset_tokens', ['resident_id' => $second->id]);
    }

    public function test_reset_token_cannot_be_reused(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170000037']);
        $resident->update(['email' => 'single-use@example.com']);

        DB::table('resident_password_reset_tokens')->insert([
            'resident_id' => $resident->id,
            'email' => 'single-use@example.com',
            'token' => Hash::make('single-use-token'),
            'created_at' => now(),
        ]);

        $this->post('/resident/reset-password', [
            'token' => 'single-use-token',
            'email' => 'single-use@example.com',
            'password' => 'first-new-password',
            'password_confirmation' => 'first-new-password',
        ])->assertRedirect('/resident/login');

        $this->assertTrue(Hash::check('first-new-password', $resident->account()->first()->password));

        $this->post('/resident/reset-password', [
            'token' => 'single-use-token',
            'email' => 'single-use@example.com',
            'password' => 'second-new-password',
            'password_confirmation' => 'second-new-password',
        ])->assertSessionHasErrors('email');

        $this->assertDatabaseCount('resident_password_reset_tokens', 0);
        $this->assertFalse(Hash::check('second-new-password', $resident->account()->first()->password));
    }

    public function test_pending_token_is_removed_when_mail_delivery_fails(): void
    {
        Log::spy();

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP unavailable'));

        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170000038']);
        $resident->update(['email' => 'smtp-down@example.com']);

        $this->post('/resident/forgot-password', ['email' => 'smtp-down@example.com'])
            ->assertRedirect('/resident/forgot-password')
            ->assertSessionHas('success');

        $this->assertDatabaseCount('resident_password_reset_tokens', 0);

        Log::shouldHaveReceived('warning')->once();
    }

    public function test_delivery_failure_does_not_log_the_reset_url_or_token(): void
    {
        Log::spy();

        // A transport failure can surface the rendered message (and therefore
        // the plaintext reset URL/token) in its exception message.
        Mail::shouldReceive('to')->andThrow(
            new \RuntimeException(
                'Transport failed for https://example.test/resident/reset-password/leaked-token?email=leak@example.com'
            )
        );

        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170000039']);
        $resident->update(['email' => 'leak@example.com']);

        $this->post('/resident/forgot-password', ['email' => 'leak@example.com'])
            ->assertRedirect('/resident/forgot-password')
            ->assertSessionHas('success');

        $this->assertDatabaseCount('resident_password_reset_tokens', 0);

        Log::shouldHaveReceived('warning')
            ->withArgs(function ($message, $context = []) {
                $logged = $message.' '.json_encode($context);

                return ($context['exception'] ?? null) === \RuntimeException::class
                    && ! str_contains($logged, 'leaked-token')
                    && ! str_contains($logged, 'reset-password');
            })
            ->once();
    }

    public function test_inactive_account_is_not_sent_a_reset_link(): void
    {
        Mail::fake();

        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170000040']);
        $resident->update(['email' => 'inactive@example.com']);
        $resident->account()->update(['is_active' => false]);

        $this->post('/resident/forgot-password', ['email' => 'inactive@example.com'])
            ->assertRedirect('/resident/forgot-password')
            ->assertSessionHas('success');

        Mail::assertNothingSent();
        $this->assertDatabaseCount('resident_password_reset_tokens', 0);
    }
}
