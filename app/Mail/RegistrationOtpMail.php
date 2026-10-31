<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RegistrationOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $verificationCode) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your KaBarangay registration verification code');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.registration-otp');
    }
}
