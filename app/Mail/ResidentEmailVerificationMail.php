<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResidentEmailVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $residentName,
        public string $verificationUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Verify your KaBarangay email address',
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.resident-email-verification',
            with: [
                'residentName' => $this->residentName,
                'verificationUrl' => $this->verificationUrl,
            ],
        );
    }
}
