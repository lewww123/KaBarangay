<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureResidentRegistrationAccess;
use App\Models\Barangay;
use App\Models\Purok;
use App\Models\ResidentAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class ResidentRegistrationAccessTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Purok $purok;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->barangay = $this->createBarangay('Access Barangay', 'ACCESS-ONE');
        $this->purok = $this->createPurok($this->barangay);
    }

    public function test_reference_alone_does_not_authorize_status_or_details(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'first_name' => 'Private',
            'last_name' => 'Applicant',
            'mobile' => '09980000401',
        ]);

        $this->get('/resident/application/'.$registration->public_id)
            ->assertRedirect(route('resident.registration.access'))
            ->assertDontSee('Private Applicant');

        $this->get('/resident/register/submitted/'.$registration->public_id)
            ->assertRedirect(route('resident.registration.submitted'));

        $this->get('/resident/register/submitted')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Resident/RegistrationPending')
                ->missing('registration'))
            ->assertDontSee($registration->public_id)
            ->assertDontSee('Private Applicant');
    }

    public function test_valid_credentials_rotate_session_and_show_only_safe_own_application_data(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'first_name' => 'Own',
            'last_name' => 'Applicant',
            'mobile' => '09980000402',
        ]);
        $registration->update(['review_notes' => 'private reviewer-only note']);

        $this->withSession(['session_marker' => 'before-access']);
        $originalSessionId = $this->app['session']->getId();

        $this->post(route('resident.registration.access.authenticate'), [
            'reference' => $registration->public_id,
            'password' => 'password',
        ])->assertRedirect(route('resident.registration.application.show', [
            'reference' => $registration->public_id,
        ]));

        $this->assertNotSame($originalSessionId, $this->app['session']->getId());
        $this->assertSame('before-access', session('session_marker'));

        $this->get('/resident/application/'.$registration->public_id)
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertInertia(fn ($page) => $page
                ->component('Resident/RegistrationApplication')
                ->where('application.reference', $registration->public_id)
                ->where('application.status', 'pending')
                ->where('application.applicant_name', 'Own Applicant')
                ->missing('application.email')
                ->missing('application.review_notes')
                ->missing('application.documents')
                ->missing('application.password'))
            ->assertDontSee('private reviewer-only note');

        $this->assertSame(0, ResidentAccount::query()->count());
    }

    public function test_unknown_reference_and_wrong_password_have_the_same_generic_failure(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000403',
        ]);
        $message = 'The application reference or password is incorrect.';

        $this->post(route('resident.registration.access.authenticate'), [
            'reference' => $registration->public_id,
            'password' => 'wrong-password',
        ])->assertRedirect()
            ->assertSessionHasErrors(['credentials' => $message]);

        $this->post(route('resident.registration.access.authenticate'), [
            'reference' => (string) Str::uuid(),
            'password' => 'wrong-password',
        ])->assertRedirect()
            ->assertSessionHasErrors(['credentials' => $message]);

        $this->assertFalse(session()->has(EnsureResidentRegistrationAccess::SESSION_KEY));
        $this->assertSame(0, ResidentAccount::query()->count());
    }

    public function test_session_cannot_open_another_application_and_expires_after_inactivity(): void
    {
        $first = $this->createPendingRegistration($this->barangay, $this->purok, [
            'first_name' => 'First',
            'mobile' => '09980000404',
        ]);
        $second = $this->createPendingRegistration($this->barangay, $this->purok, [
            'first_name' => 'Second',
            'mobile' => '09980000405',
        ]);

        $this->post(route('resident.registration.access.authenticate'), [
            'reference' => $first->public_id,
            'password' => 'password',
        ])->assertRedirect();

        $this->get('/resident/application/'.$second->public_id)->assertNotFound();
        $this->get('/resident/application/'.$first->public_id)->assertOk();

        $context = session(EnsureResidentRegistrationAccess::SESSION_KEY);
        $context['idle_expires_at'] = now()->subSecond()->getTimestamp();
        $this->withSession([EnsureResidentRegistrationAccess::SESSION_KEY => $context]);

        $this->get('/resident/application/'.$first->public_id)
            ->assertRedirect(route('resident.registration.access'))
            ->assertSessionHas('access_feedback');

        $this->assertFalse(session()->has(EnsureResidentRegistrationAccess::SESSION_KEY));
    }

    public function test_sign_out_clears_only_application_access_and_terminal_records_remain_read_only(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000406',
        ]);
        $registration->update(['status' => 'rejected']);

        $this->withSession(['resident_id' => 731])
            ->post(route('resident.registration.access.authenticate'), [
                'reference' => $registration->public_id,
                'password' => 'password',
            ])->assertRedirect();

        $this->get('/resident/application/'.$registration->public_id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('application.status', 'rejected'));

        $this->post('/resident/application/'.$registration->public_id, [
            'status' => 'pending',
        ])->assertMethodNotAllowed();
        $this->assertSame('rejected', $registration->fresh()->status);

        $this->post('/resident/application/'.$registration->public_id.'/logout')
            ->assertRedirect(route('resident.registration.access', [
                'reference' => $registration->public_id,
            ]));
        $this->assertFalse(session()->has(EnsureResidentRegistrationAccess::SESSION_KEY));
        $this->assertSame(731, session('resident_id'));
        $this->get('/resident/application/'.$registration->public_id)
            ->assertRedirect(route('resident.registration.access'));
    }

    public function test_access_login_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('resident.registration.access.authenticate'), [
                'reference' => (string) Str::uuid(),
                'password' => 'wrong-password',
            ])->assertRedirect();
        }

        $this->post(route('resident.registration.access.authenticate'), [
            'reference' => (string) Str::uuid(),
            'password' => 'wrong-password',
        ])->assertTooManyRequests();
    }
}
