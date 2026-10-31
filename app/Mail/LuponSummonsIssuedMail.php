<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LuponSummonsIssuedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $residentName,
        public readonly string $caseNumber,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Katarungang Pambarangay summons issued');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.lupon-summons-issued');
    }
}
