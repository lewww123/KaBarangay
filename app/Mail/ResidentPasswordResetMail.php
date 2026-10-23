<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResidentPasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $residentName,
        public string $resetUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reset your KaBarangay password',
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.resident-password-reset',
            with: [
                'residentName' => $this->residentName,
                'resetUrl' => $this->resetUrl,
            ],
        );
    }
}
