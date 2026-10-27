<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResidentRegistrationEmailVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $applicantName,
        public string $verificationCode,
        public string $applicationAccessUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Verify your KaBarangay registration email');
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.resident-registration-email-verification',
            with: [
                'applicantName' => $this->applicantName,
                'verificationCode' => $this->verificationCode,
                'applicationAccessUrl' => $this->applicationAccessUrl,
            ],
        );
    }
}
