<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResidentApprovalMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $residentName,
        public string $loginUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your KaBarangay resident account is ready',
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.resident-approval',
            with: [
                'residentName' => $this->residentName,
                'loginUrl' => $this->loginUrl,
            ],
        );
    }
}
