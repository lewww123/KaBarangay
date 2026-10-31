<?php

namespace Tests\Feature;

use App\Mail\ResidentEmailVerificationMail;
use App\Mail\ResidentRegistrationInvitationMail;
use App\Models\ResidentEmailVerificationToken;
use App\Support\ResidentEmailVerificationService;
use App\Support\ResidentNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use LogicException;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class ResidentAccountRegistrationWorkflowTest extends TestCase
{
    use CreatesBarangayFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
        $this->withoutVite();
    }

    public function test_all_public_and_admin_claim_endpoints_are_removed(): void
    {
        foreach ([
            ['GET', '/resident/claim'], ['POST', '/resident/claim'],
            ['GET', '/resident/claim/submitted/legacy'], ['POST', '/resident/claim/legacy/resend-verification'],
            ['GET', '/admin/resident-claims'], ['GET', '/admin/resident-claims/1/details'],
            ['POST', '/admin/resident-claims/1/approve'], ['POST', '/admin/resident-claims/1/reject'],
            ['POST', '/admin/resident-claims/1/notification/retry'],
        ] as [$method, $path]) {
            $this->call($method, $path)->assertNotFound();
        }
        $this->assertDatabaseCount('resident_accounts', 0);
        $this->assertDatabaseCount('resident_claims', 0);
    }

    public function test_approval_rejects_missing_or_non_admin_added_residents_without_creating_an_account(): void
    {
        Mail::fake();
        $barangay = $this->createBarangay('Registration', 'REG-ADMIN');
        $purok = $this->createPurok($barangay);
        $admin = $this->createStaff($barangay, 'admin');
        $resident = $this->createResident($barangay, $purok);
        $resident->account()->delete();
        $resident->update(['email' => 'person@example.test']);
        $registration = $this->createPendingRegistration($barangay, $purok, ['mobile' => '09980000002', 'email' => 'person@example.test']);

        foreach ([999999, $resident->id] as $id) {
            $this->actingAs($admin)->post('/admin/residents/'.$registration->id.'/approve', ['resident_id' => $id])
                ->assertSessionHasErrors('resident_id');
        }
        $this->assertDatabaseCount('resident_accounts', 1);
        $this->assertSame('pending', $registration->fresh()->status);
        $this->assertNull($registration->fresh()->matched_resident_id);
    }

    public function test_resident_verification_tokens_expire_and_cannot_be_reused(): void
    {
        Mail::fake();
        $barangay = $this->createBarangay('Verification', 'REG-VERIFY');
        $resident = $this->createResident($barangay, $this->createPurok($barangay));
        $resident->update(['email' => 'person@example.test']);
        app(ResidentEmailVerificationService::class)->send($resident);
        $url = Mail::sent(ResidentEmailVerificationMail::class)->first()->verificationUrl;
        ResidentEmailVerificationToken::firstOrFail()->update(['expires_at' => now()->subMinute()]);

        $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertInertia(fn ($page) => $page->where('status', 'expired'));
        $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertInertia(fn ($page) => $page->where('status', 'invalid'));
        $this->assertNull($resident->fresh()->email_verified_at);
    }

    public function test_verification_link_is_invalid_after_the_resident_email_changes(): void
    {
        Mail::fake();
        $barangay = $this->createBarangay('Verification', 'REG-VERIFY');
        $resident = $this->createResident($barangay, $this->createPurok($barangay));
        $resident->update(['email' => 'person@example.test']);
        app(ResidentEmailVerificationService::class)->send($resident);
        $url = Mail::sent(ResidentEmailVerificationMail::class)->first()->verificationUrl;
        $resident->update(['email' => 'changed@example.test']);

        $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertInertia(fn ($page) => $page->where('status', 'email_changed'));
        $this->assertNull($resident->fresh()->email_verified_at);
    }

    public function test_profile_email_changes_clear_verification_and_send_a_new_link(): void
    {
        Mail::fake();
        $barangay = $this->createBarangay('Verification', 'REG-VERIFY');
        $resident = $this->createResident($barangay, $this->createPurok($barangay));
        $resident->update(['email' => 'old@example.test', 'email_verified_at' => now()]);
        $this->withSession(['resident_id' => $resident->id])->from('/resident/profile')->post('/resident/profile/contact', [
            'mobileNumber' => $resident->mobile, 'email' => 'new@example.test',
        ])->assertRedirect('/resident/profile')->assertSessionHasNoErrors();
        $this->assertNull($resident->fresh()->email_verified_at);
        Mail::assertSent(ResidentEmailVerificationMail::class, fn ($mail) => $mail->hasTo('new@example.test'));
    }

    public function test_registration_invitation_delivery_is_idempotent_and_requires_a_commit(): void
    {
        Mail::fake();
        $barangay = $this->createBarangay('Invitation', 'REG-INVITE');
        $resident = $this->createResident($barangay, $this->createPurok($barangay));
        $resident->update(['source' => 'admin', 'email' => 'person@example.test']);
        $service = app(ResidentNotificationService::class);
        $first = $service->enqueueRegistrationInvitation($resident);
        $this->assertSame($first->id, $service->enqueueRegistrationInvitation($resident)->id);
        $service->deliver($first);
        $service->deliver($first);
        Mail::assertSent(ResidentRegistrationInvitationMail::class, 1);

        DB::beginTransaction();
        try {
            $this->expectException(LogicException::class);
            $service->deliver($first);
        } finally {
            DB::rollBack();
        }
    }
}
