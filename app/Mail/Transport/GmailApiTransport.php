<?php

namespace App\Mail\Transport;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;

class GmailApiTransport extends AbstractTransport
{
    private ?string $accessToken = null;

    private int $expiresAt = 0;

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $refreshToken,
    ) {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'gmail+https';
    }

    protected function doSend(SentMessage $message): void
    {
        $raw = rtrim(strtr(base64_encode($this->mimeMessage($message)), '+/', '-_'), '=');

        try {
            $response = $this->sendRaw($raw, $this->token());

            // A rejected access token can be refreshed safely. Do not retry
            // timeouts or other send failures: Gmail might already have accepted the mail.
            if ($response->status() === 401) {
                $this->accessToken = null;
                $response = $this->sendRaw($raw, $this->token());
            }
        } catch (ConnectionException) {
            throw new GmailApiTransportException('Gmail API connection failed. Check HTTPS connectivity and try again.');
        }

        if (! $response->successful()) {
            $reason = match ($response->status()) {
                401 => 'Authorization failed; reconnect the sender account.',
                403 => 'Check that Gmail API is enabled, gmail.send is authorized, and the sender account is permitted.',
                429 => 'Sending rate limit reached; try again later.',
                default => 'The provider did not accept the email.',
            };

            throw new GmailApiTransportException('Gmail API send failed (HTTP '.$response->status().'). '.$reason);
        }

        $id = $response->json('id');
        if (! is_string($id) || $id === '') {
            throw new GmailApiTransportException('Gmail API returned an invalid delivery acknowledgement.');
        }

        $message->setMessageId($id);
    }

    private function token(): string
    {
        if ($this->accessToken !== null && time() < $this->expiresAt) {
            return $this->accessToken;
        }

        if (trim($this->clientId) === '' || trim($this->clientSecret) === '' || trim($this->refreshToken) === '') {
            throw new GmailApiTransportException('Gmail API credentials are missing. Configure GMAIL_CLIENT_ID, GMAIL_CLIENT_SECRET and GMAIL_REFRESH_TOKEN.');
        }

        $response = $this->request()->asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $this->refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        // Never throw an HTTP RequestException with Google's response body:
        // it may contain credentials or message content that would reach logs.
        if (! $response->successful()) {
            $reason = match ($response->json('error')) {
                'invalid_grant' => 'The refresh token expired or was revoked; reconnect the sender account.',
                'invalid_client' => 'Check the OAuth Client ID and Client Secret.',
                default => 'Check the sender OAuth configuration.',
            };

            throw new GmailApiTransportException('Gmail OAuth refresh failed (HTTP '.$response->status().'). '.$reason);
        }

        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new GmailApiTransportException('Gmail OAuth returned an invalid access token.');
        }

        $this->accessToken = $token;
        $this->expiresAt = time() + max(0, (int) $response->json('expires_in', 3600) - 60);

        return $token;
    }

    private function sendRaw(string $raw, string $token): Response
    {
        return $this->request()->withToken($token)
            ->post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', ['raw' => $raw]);
    }

    private function request(): PendingRequest
    {
        $caBundle = config('services.gmail.ca_bundle');

        return Http::acceptJson()->connectTimeout(5)->timeout(30)
            ->withOptions([
                'allow_redirects' => false,
                'verify' => is_string($caBundle) && $caBundle !== '' ? $caBundle : true,
            ]);
    }

    private function mimeMessage(SentMessage $message): string
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $body = $email->getBody();
        $headers = $email->getPreparedHeaders();
        $recipients = $message->getEnvelope()->getRecipients();
        $addresses = fn (array $items): array => array_map(fn (Address $item) => strtolower($item->getAddress()), $items);
        $allowed = $addresses($recipients);
        $to = array_values(array_filter($email->getTo(), fn (Address $item) => in_array(strtolower($item->getAddress()), $allowed, true)));
        $cc = array_values(array_filter($email->getCc(), fn (Address $item) => in_array(strtolower($item->getAddress()), $allowed, true)));
        $visible = $addresses([...$to, ...$cc]);
        $bcc = array_values(array_filter($recipients, fn (Address $item) => ! in_array(strtolower($item->getAddress()), $visible, true)));

        // Gmail reads recipients from MIME headers, unlike SMTP's envelope.
        // Symfony strips Bcc during serialization; restore it for Gmail's API,
        // while respecting envelope overrides such as Laravel's alwaysTo().
        foreach (['To' => $to, 'Cc' => $cc, 'Bcc' => $bcc] as $name => $items) {
            $headers->remove($name);
            if ($items !== []) {
                $headers->addMailboxListHeader($name, $items);
            }
        }

        return $headers->toString().($body?->toString() ?? "\r\n");
    }
}
