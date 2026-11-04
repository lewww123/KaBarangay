<?php

namespace Tests\Feature;

use App\Mail\ResidentPasswordResetMail;
use App\Mail\ResidentRegistrationApprovedMail;
use App\Mail\Transport\GmailApiTransport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class GmailApiMailTest extends TestCase
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const SEND_URL = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'mail.default' => 'gmail',
            'mail.from.address' => 'sender@example.test',
            'mail.from.name' => 'KaBarangay',
            'services.gmail.client_id' => 'test-client',
            'services.gmail.client_secret' => 'test-secret',
            'services.gmail.refresh_token' => 'test-refresh',
        ]);
        Mail::purge('gmail');
    }

    public function test_laravel_mailable_sends_using_refresh_token_and_base64url_mime(): void
    {
        $this->fakeDelivery();

        Mail::to('resident@example.test')->send(new ResidentPasswordResetMail(
            residentName: 'Juan Dela Cruz',
            resetUrl: 'https://example.test/resident/reset-password/example-token',
        ));

        Http::assertSent(fn (Request $request) => $request->url() === self::TOKEN_URL
            && $request['grant_type'] === 'refresh_token'
            && $request['client_id'] === 'test-client'
            && $request['client_secret'] === 'test-secret'
            && $request['refresh_token'] === 'test-refresh');

        Http::assertSent(function (Request $request) {
            if ($request->url() !== self::SEND_URL) {
                return false;
            }

            $this->assertTrue($request->hasHeader('Authorization', 'Bearer test-access'));
            $this->assertDoesNotMatchRegularExpression('/[+=\/]/', $request['raw']);
            $mime = base64_decode(strtr($request['raw'], '-_', '+/'));
            $this->assertStringContainsString('resident@example.test', $mime);
            $this->assertStringContainsString('sender@example.test', $mime);
            $this->assertStringContainsString('Reset your KaBarangay password', $mime);
            $this->assertStringContainsString('example-token', quoted_printable_decode($mime));

            return true;
        });
        Http::assertSentCount(2);
    }

    public function test_approval_email_with_inline_qr_is_sent_through_gmail(): void
    {
        $this->fakeDelivery();

        Mail::to('resident@example.test')->send(new ResidentRegistrationApprovedMail(
            residentName: 'Juan Dela Cruz',
            residentNumber: 'BR-0001',
            verificationUrl: 'https://example.test/resident/qr/example-token',
            approvedAt: 'November 5, 2026',
        ));

        Http::assertSent(function (Request $request) {
            if ($request->url() !== self::SEND_URL) {
                return false;
            }

            $mime = base64_decode(strtr($request['raw'], '-_', '+/'));
            $this->assertStringContainsString('Your KaBarangay registration was approved', $mime);
            $this->assertStringContainsString('resident@example.test', $mime);
            $this->assertStringContainsString('image/png', $mime);
            $this->assertStringContainsString('Content-ID:', $mime);

            return true;
        });
        Http::assertSentCount(2);
    }

    public function test_multipart_attachments_and_bcc_recipients_are_preserved_for_gmail(): void
    {
        $this->fakeDelivery();
        $mail = (new Email)->from('sender@example.test')->to('one@example.test')
            ->cc('two@example.test')->bcc('hidden@example.test')
            ->subject('Barangay document')->text('Maligayang araw, José.')
            ->html('<p>Maligayang araw, José.</p>')
            ->attach('test-document-content', 'notice.txt', 'text/plain');

        $sent = $this->transport()->send($mail);

        $this->assertSame('gmail-message-123', $sent->getMessageId());
        Http::assertSent(function (Request $request) {
            if ($request->url() !== self::SEND_URL) {
                return false;
            }
            $mime = base64_decode(strtr($request['raw'], '-_', '+/'));
            foreach (['To: one@example.test', 'Cc: two@example.test', 'Bcc: hidden@example.test', 'multipart/mixed', 'multipart/alternative', 'notice.txt'] as $expected) {
                $this->assertStringContainsString($expected, $mime);
            }

            return true;
        });
    }

    public function test_envelope_override_cannot_send_to_original_recipients(): void
    {
        $this->fakeDelivery();
        $mail = (new Email)->from('sender@example.test')->to('original@example.test')
            ->cc('original-cc@example.test')->bcc('original-bcc@example.test')
            ->subject('Override')->text('Test body');
        $this->transport()->send($mail, new Envelope(
            new Address('sender@example.test'), [new Address('override@example.test')],
        ));

        Http::assertSent(function (Request $request) {
            if ($request->url() !== self::SEND_URL) {
                return false;
            }
            $mime = base64_decode(strtr($request['raw'], '-_', '+/'));
            $this->assertStringContainsString('override@example.test', $mime);
            $this->assertStringNotContainsString('original', $mime);

            return true;
        });
    }

    public function test_a_valid_access_token_is_reused_within_the_mailer_instance(): void
    {
        $this->fakeDelivery();
        $transport = $this->transport();
        $mail = (new Email)->from('sender@example.test')->to('resident@example.test')->subject('Test')->text('Test');
        $transport->send($mail);
        $transport->send($mail);

        Http::assertSentCount(3);
    }

    public function test_explicit_unauthorized_response_refreshes_once_before_resending(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::sequence()
                ->push(['access_token' => 'first-token', 'expires_in' => 3600])
                ->push(['access_token' => 'new-token', 'expires_in' => 3600]),
            self::SEND_URL => Http::sequence()
                ->push(['error' => 'unauthorized'], 401)
                ->push(['id' => 'accepted']),
        ]);

        $this->assertSame('accepted', $this->transport()->send($this->email())->getMessageId());
        Http::assertSent(fn (Request $request) => $request->url() === self::SEND_URL
            && $request->hasHeader('Authorization', 'Bearer new-token'));
        Http::assertSentCount(4);
    }

    public function test_revoked_refresh_token_returns_safe_error_without_provider_body(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['error' => 'invalid_grant', 'error_description' => 'private-token-and-message'], 400),
        ]);

        try {
            $this->transport()->send($this->email());
            $this->fail('A revoked token must not report successful delivery.');
        } catch (TransportException $exception) {
            $this->assertStringContainsString('revoked', $exception->getMessage());
            $this->assertStringNotContainsString('private-token-and-message', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }

        Http::assertSentCount(1);
    }

    public function test_send_failure_is_sanitized_and_is_not_retried(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'test-access', 'expires_in' => 3600]),
            self::SEND_URL => Http::response(['error' => ['message' => 'private-reset-url-and-address']], 429),
        ]);

        try {
            $this->transport()->send($this->email());
            $this->fail('A rate limit must not report successful delivery.');
        } catch (TransportException $exception) {
            $this->assertStringContainsString('429', $exception->getMessage());
            $this->assertStringNotContainsString('private-reset-url-and-address', $exception->getMessage());
        }

        Http::assertSentCount(2);
    }

    public function test_uncertain_send_timeout_is_not_retried_and_has_no_secret_in_exception(): void
    {
        $sends = 0;
        Http::fake(function (Request $request) use (&$sends) {
            if ($request->url() === self::TOKEN_URL) {
                return Http::response(['access_token' => 'test-access', 'expires_in' => 3600]);
            }
            $sends++;
            throw new ConnectionException('private-request-contents');
        });

        try {
            $this->transport()->send($this->email());
            $this->fail('An uncertain timeout must not report successful delivery.');
        } catch (TransportException $exception) {
            $this->assertStringContainsString('connection failed', $exception->getMessage());
            $this->assertStringNotContainsString('private-request-contents', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }

        $this->assertSame(1, $sends);
    }

    public function test_missing_credentials_make_no_http_requests(): void
    {
        config(['services.gmail.refresh_token' => '']);

        try {
            $this->transport()->send($this->email());
            $this->fail('Missing credentials must fail.');
        } catch (TransportException $exception) {
            $this->assertStringContainsString('credentials are missing', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    private function transport(): GmailApiTransport
    {
        return Mail::mailer('gmail')->getSymfonyTransport();
    }

    private function email(): Email
    {
        return (new Email)->from('sender@example.test')->to('resident@example.test')->subject('Test')->text('Test body');
    }

    private function fakeDelivery(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'test-access', 'expires_in' => 3600]),
            self::SEND_URL => Http::response(['id' => 'gmail-message-123']),
        ]);
    }
}
