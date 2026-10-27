<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResidentClaimInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $residentName,
        public string $registrationUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Create your KaBarangay Resident account',
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.resident-claim-invitation',
            with: [
                'residentName' => $this->residentName,
                'registrationUrl' => $this->registrationUrl,
            ],
        );
    }
}
