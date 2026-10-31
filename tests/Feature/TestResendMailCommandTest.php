<?php

namespace Tests\Feature;

use App\Mail\TestResendMail;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class TestResendMailCommandTest extends TestCase
{
    public function test_command_sends_the_mailable_and_reports_smtp_acceptance(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp']);

        $this->artisan('mail:test-resend', ['email' => 'recipient@example.com'])
            ->expectsOutput('Sending Resend SMTP test to recipient@example.com...')
            ->expectsOutput('SMTP server accepted the test email for recipient@example.com.')
            ->assertSuccessful();

        Mail::assertSent(TestResendMail::class, fn ($mail) => $mail->hasTo('recipient@example.com'));
        (new TestResendMail)->assertSeeInHtml('Resend SMTP integration');
    }

    public function test_command_rejects_an_invalid_recipient_without_sending(): void
    {
        Mail::fake();
        $this->artisan('mail:test-resend', ['email' => 'not-an-email'])
            ->expectsOutput('Please provide a valid recipient email address.')
            ->assertExitCode(2);
        Mail::assertNothingSent();
    }

    public function test_log_mailer_cannot_be_reported_as_a_successful_smtp_test(): void
    {
        Mail::fake();
        config(['mail.default' => 'log']);
        $this->artisan('mail:test-resend', ['email' => 'recipient@example.com'])
            ->expectsOutput('Configured mailer [log] is not an SMTP transport. Set MAIL_MAILER=smtp and run php artisan config:clear.')
            ->assertFailed();
        Mail::assertNothingSent();
    }

    public function test_smtp_errors_are_reported_with_a_failure_exit_code(): void
    {
        config(['mail.default' => 'smtp']);
        $pending = Mockery::mock(PendingMail::class);
        Mail::shouldReceive('to')->once()->with('recipient@example.com')->andReturn($pending);
        $pending->shouldReceive('send')->once()->with(Mockery::type(TestResendMail::class))
            ->andThrow(new TransportException('SMTP authentication failed (535).'));

        $this->artisan('mail:test-resend', ['email' => 'recipient@example.com'])
            ->expectsOutput('SMTP delivery failed: SMTP authentication failed (535).')
            ->assertFailed();
    }
}
