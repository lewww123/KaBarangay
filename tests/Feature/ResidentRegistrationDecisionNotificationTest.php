<?php

namespace Tests\Feature;

use App\Mail\ResidentRegistrationApprovedMail;
use App\Mail\ResidentRegistrationDecisionMail;
use App\Models\Barangay;
use App\Models\Purok;
use App\Models\ResidentAccount;
use App\Models\ResidentNotification;
use App\Models\ResidentRegistration;
use App\Models\ResidentRegistrationCorrectionCycle;
use App\Models\ResidentRegistrationEmailVerificationToken;
use App\Support\ResidentRegistrationDecisionNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class ResidentRegistrationDecisionNotificationTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Purok $purok;

    protected function setUp(): void
    {
        parent::setUp();
        config(['registration.email_verification_required' => true]);
        $this->withoutVite();
        Mail::fake();
        $this->barangay = $this->createBarangay('Decision Mail Barangay', 'DECISION-MAIL');
        $this->purok = $this->createPurok($this->barangay, 'Purok 1');
    }

    public function test_correction_notification_uses_verified_recipient_and_persisted_instructions_after_commit(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'email' => 'applicant@example.test',
            'mobile' => '09980000901',
        ]);
        $registration->update(['email' => 'applicant@example.test']);
        $this->markEmailVerified($registration);
        $admin = $this->createStaff($this->barangay, 'admin');

        DB::beginTransaction();
        $response = $this->actingAs($admin)->post('/admin/residents/'.$registration->id.'/correction', [
            'instructions' => 'Upload a clearer identity document.',
            'field_targets' => ['first_name'],
        ]);
        Mail::assertNothingSent();
        DB::commit();
        $response->assertRedirect();

        $notice = ResidentNotification::query()->firstOrFail();
        $cycle = ResidentRegistrationCorrectionCycle::query()->firstOrFail();
        $this->assertSame('sent', $notice->status);
        $this->assertSame('applicant@example.test', $notice->recipient_email);
        $this->assertSame($cycle->id, $notice->correction_cycle_id);
        $this->assertSame('needs_correction', $registration->fresh()->status);
        $this->actingAs($admin)->post('/admin/residents/'.$registration->id.'/correction', [
            'instructions' => 'Duplicate instruction.',
            'field_targets' => ['last_name'],
        ])->assertStatus(422);
        $this->assertSame(1, ResidentNotification::query()->count());
        Mail::assertSent(ResidentRegistrationDecisionMail::class, fn ($mail) => $mail->decisionType === 'correction' &&
            $mail->correctionInstructions === 'Upload a clearer identity document.' &&
            $mail->hasTo('applicant@example.test')
        );
    }

    public function test_rejection_keeps_internal_note_separate_from_optional_resident_reason(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'email' => 'applicant@example.test',
            'mobile' => '09980000902',
        ]);
        $registration->update(['email' => 'applicant@example.test']);
        $this->markEmailVerified($registration);
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)->post('/admin/residents/'.$registration->id.'/reject', [
            'reason' => 'Internal: possible document mismatch; verify staff notes.',
            'email_safe_reason' => 'The submitted identity document could not be verified. <script>alert(1)</script>',
        ])->assertRedirect();

        $notice = ResidentNotification::query()->firstOrFail();
        $this->assertSame('rejected', $registration->fresh()->status);
        $this->assertSame('Internal: possible document mismatch; verify staff notes.', $registration->fresh()->review_notes);
        $this->assertSame('The submitted identity document could not be verified. <script>alert(1)</script>', $notice->email_safe_reason);
        $this->assertSame('sent', $notice->status);
        Mail::assertSent(ResidentRegistrationDecisionMail::class, fn ($mail) => $mail->decisionType === 'rejected' &&
            $mail->emailSafeReason === 'The submitted identity document could not be verified. <script>alert(1)</script>' &&
            ! str_contains($mail->render(), '<script>') &&
            str_contains($mail->render(), '&lt;script&gt;alert(1)&lt;/script&gt;')
        );
    }

    public function test_missing_resident_facing_reason_preserves_rejection_without_sending_internal_note(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'email' => 'applicant@example.test',
            'mobile' => '09980000903',
        ]);
        $registration->update(['email' => 'applicant@example.test']);
        $this->markEmailVerified($registration);
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)->post('/admin/residents/'.$registration->id.'/reject', [
            'reason' => 'Internal-only review detail.',
            'email_safe_reason' => 'no',
        ])->assertRedirect();

        $notice = ResidentNotification::query()->firstOrFail();
        $this->assertSame('rejected', $registration->fresh()->status);
        $this->assertNull($notice->email_safe_reason);
        $this->assertSame('deferred', $notice->status);
        $this->assertSame('resident_reason_unavailable', $notice->failure_class);
        Mail::assertNothingSent();
    }

    public function test_changed_registration_email_defers_unsent_decision_notice_and_clears_verification(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'email' => 'applicant@example.test',
            'mobile' => '09980000904',
        ]);
        $registration->update(['email' => 'applicant@example.test']);
        $this->markEmailVerified($registration);
        $admin = $this->createStaff($this->barangay, 'admin');
        $cycle = ResidentRegistrationCorrectionCycle::query()->create([
            'resident_registration_id' => $registration->id,
            'cycle_number' => 1,
            'instructions' => 'Correct the name on your application.',
            'field_targets' => ['first_name'],
            'document_targets' => [],
            'prior_field_snapshot' => [],
            'reviewer_id' => $admin->id,
            'requested_at' => now(),
        ]);
        $notice = app(ResidentRegistrationDecisionNotificationService::class)
            ->enqueue($registration, 'correction', $cycle);
        $registration->update(['email' => 'new-address@example.test']);

        $this->assertNull($registration->fresh()->email_verified_at);
        $this->assertSame('deferred', $notice->fresh()->status);
        $this->assertSame('recipient_email_changed', $notice->fresh()->failure_class);
    }

    public function test_unverified_recipient_defers_notice_without_blocking_registration_decision(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000907',
            'email' => 'unverified@example.test',
        ]);
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)->post('/admin/residents/'.$registration->id.'/reject', [
            'reason' => 'Internal review note.',
            'email_safe_reason' => 'This explanation is appropriate for the applicant.',
        ])->assertRedirect();

        $notice = ResidentNotification::query()->firstOrFail();
        $this->assertSame('rejected', $registration->fresh()->status);
        $this->assertSame('deferred', $notice->status);
        $this->assertSame('recipient_unverified', $notice->failure_class);
        Mail::assertNothingSent();
    }

    public function test_approval_notification_is_created_with_approved_state_and_active_account(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'email' => 'applicant@example.test',
            'mobile' => '09980000905',
        ]);
        $registration->update(['email' => 'applicant@example.test']);
        $this->markEmailVerified($registration);
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170000905',
            'email' => 'applicant@example.test',
        ]);
        $resident->update(['source' => 'admin', 'email' => 'applicant@example.test']);
        $resident->account()->delete();
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)->post('/admin/residents/'.$registration->id.'/approve', [
            'resident_id' => $resident->id,
        ])->assertRedirect();

        $notice = ResidentNotification::query()->firstOrFail();
        $this->assertSame('approved', $registration->fresh()->status);
        $this->assertTrue(ResidentAccount::query()->where('resident_id', $resident->id)->where('is_active', true)->exists());
        $this->assertSame('sent', $notice->status);
        $this->assertSame('approved', $notice->decision_type);
        Mail::assertSent(ResidentRegistrationApprovedMail::class, fn ($mail) => $mail->residentNumber === $resident->fresh()->resident_number &&
            str_contains($mail->render(), route('resident.login')) &&
            str_contains($mail->render(), 'Resident QR Code') &&
            getimagesizefromstring($mail->qrCodePng)['mime'] === 'image/png'
        );
    }

    public function test_admin_retry_delivers_only_failed_eligible_registration_decision_notice(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'email' => 'applicant@example.test',
            'mobile' => '09980000906',
        ]);
        $registration->update(['email' => 'applicant@example.test']);
        $this->markEmailVerified($registration);
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->actingAs($admin)->post('/admin/residents/'.$registration->id.'/reject', [
            'reason' => 'Internal-only note.',
            'email_safe_reason' => 'The submitted document could not be verified.',
        ])->assertRedirect();
        $notice = ResidentNotification::query()->firstOrFail();
        $notice->forceFill(['status' => 'failed', 'attempts' => 125])->save();

        $this->actingAs($admin)->post('/admin/resident-registration-notifications/'.$notice->id.'/retry')
            ->assertRedirect();

        $this->assertSame('sent', $notice->fresh()->status);
        $this->assertSame(126, $notice->fresh()->attempts);
        Mail::assertSent(ResidentRegistrationDecisionMail::class);
    }

    public function test_delivery_failure_is_recorded_without_persisting_exception_message(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000909',
            'email' => 'applicant@example.test',
        ]);
        $registration->update(['email' => 'applicant@example.test', 'status' => 'needs_correction']);
        $this->markEmailVerified($registration);
        $admin = $this->createStaff($this->barangay, 'admin');
        $cycle = ResidentRegistrationCorrectionCycle::query()->create([
            'resident_registration_id' => $registration->id,
            'cycle_number' => 1,
            'instructions' => 'Correct your name.',
            'field_targets' => ['first_name'],
            'document_targets' => [],
            'prior_field_snapshot' => [],
            'reviewer_id' => $admin->id,
            'requested_at' => now(),
        ]);
        $notice = app(ResidentRegistrationDecisionNotificationService::class)
            ->enqueue($registration, 'correction', $cycle);

        Mail::shouldReceive('to')
            ->once()
            ->with('applicant@example.test')
            ->andThrow(new RuntimeException('private provider response detail'));

        app(ResidentRegistrationDecisionNotificationService::class)->deliver($notice);

        $this->assertSame('failed', $notice->fresh()->status);
        $this->assertSame(RuntimeException::class, $notice->fresh()->last_error);
        $this->assertSame(RuntimeException::class, $notice->fresh()->failure_class);
        $this->assertSame(1, $notice->fresh()->attempts);
        $this->assertStringNotContainsString('private provider response detail', $notice->fresh()->last_error);
    }

    public function test_retry_rejects_cross_barangay_access_and_defers_obsolete_correction(): void
    {
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, [
            'mobile' => '09980000908',
            'email' => 'applicant@example.test',
        ]);
        $registration->update(['email' => 'applicant@example.test', 'status' => 'needs_correction']);
        $this->markEmailVerified($registration);
        $admin = $this->createStaff($this->barangay, 'admin');
        $cycle = ResidentRegistrationCorrectionCycle::query()->create([
            'resident_registration_id' => $registration->id,
            'cycle_number' => 1,
            'instructions' => 'Please correct your name.',
            'field_targets' => ['first_name'],
            'document_targets' => [],
            'prior_field_snapshot' => [],
            'reviewer_id' => $admin->id,
            'requested_at' => now(),
        ]);
        $notice = app(ResidentRegistrationDecisionNotificationService::class)
            ->enqueue($registration, 'correction', $cycle);
        $notice->forceFill(['status' => 'failed'])->save();

        $otherBarangay = $this->createBarangay('Other Decision Mail', 'DECISION-OTHER');
        $otherAdmin = $this->createStaff($otherBarangay, 'admin');
        $this->actingAs($otherAdmin)
            ->post('/admin/resident-registration-notifications/'.$notice->id.'/retry')
            ->assertNotFound();

        $cycle->forceFill(['resubmitted_at' => now()])->save();
        $registration->update(['status' => 'pending']);

        $this->actingAs($admin)
            ->post('/admin/resident-registration-notifications/'.$notice->id.'/retry')
            ->assertRedirect();

        $this->assertSame('deferred', $notice->fresh()->status);
        $this->assertSame('ineligible_or_obsolete', $notice->fresh()->failure_class);
        Mail::assertNothingSent();
    }

    private function markEmailVerified(ResidentRegistration $registration): void
    {
        $verifiedAt = now();
        ResidentRegistrationEmailVerificationToken::query()->create([
            'resident_registration_id' => $registration->id,
            'token_hash' => hash_hmac('sha256', 'test-marker', (string) config('app.key')),
            'email_snapshot' => strtolower(trim((string) $registration->email)),
            'expires_at' => $verifiedAt->copy()->addMinutes(10),
            'consumed_at' => $verifiedAt,
            'last_sent_at' => $verifiedAt,
            'send_count' => 1,
        ]);
        $registration->forceFill(['email_verified_at' => $verifiedAt])->save();
    }

    public function test_unverified_applicant_receives_correction_email_when_otp_is_disabled(): void
    {
        config(['registration.email_verification_required' => false]);
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, ['email' => 'no-otp@example.test']);
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->actingAs($admin)->post('/admin/residents/'.$registration->id.'/correction', ['instructions' => 'Please correct your first name.', 'field_targets' => ['first_name']])->assertRedirect();
        Mail::assertSent(ResidentRegistrationDecisionMail::class, fn ($mail) => $mail->decisionType === 'correction' && $mail->hasTo('no-otp@example.test'));
        $this->assertNull($registration->fresh()->email_verified_at);
    }

    public function test_unverified_applicant_receives_safe_rejection_email_when_otp_is_disabled(): void
    {
        config(['registration.email_verification_required' => false]);
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, ['email' => 'no-otp@example.test']);
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->actingAs($admin)->post('/admin/residents/'.$registration->id.'/reject', ['reason' => 'Private admin review notes.'])->assertRedirect();
        Mail::assertSent(ResidentRegistrationDecisionMail::class, fn ($mail) => $mail->decisionType === 'rejected' && $mail->hasTo('no-otp@example.test') && !str_contains($mail->render(), 'Private admin review notes.'));
    }

    public function test_unverified_applicant_receives_approval_email_when_otp_is_disabled(): void
    {
        config(['registration.email_verification_required' => false]);
        $registration = $this->createPendingRegistration($this->barangay, $this->purok, ['email' => 'no-otp@example.test']);
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170000999', 'email' => 'no-otp@example.test']);
        $resident->update(['source' => 'admin']);
        $resident->account()->delete();
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->actingAs($admin)->post('/admin/residents/'.$registration->id.'/approve', ['resident_id' => $resident->id])->assertRedirect();
        $this->assertSame('approved', $registration->fresh()->status);
        Mail::assertSent(ResidentRegistrationApprovedMail::class, fn ($mail) => $mail->hasTo('no-otp@example.test'));
    }
}
