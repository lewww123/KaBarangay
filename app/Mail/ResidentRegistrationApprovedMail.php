<?php

namespace App\Mail;

use App\Support\ResidentQrCodePng;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResidentRegistrationApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public readonly string $qrCodePng;

    public function __construct(
        public string $residentName,
        public string $residentNumber,
        public string $verificationUrl,
        public string $approvedAt,
    ) {
        $this->qrCodePng = ResidentQrCodePng::make($this->verificationUrl);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your KaBarangay registration was approved');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.resident-registration-approved',
            text: 'emails.resident-registration-approved-text',
            with: [
                'residentName' => $this->residentName,
                'residentNumber' => $this->residentNumber,
                'approvedAt' => $this->approvedAt,
                'verificationUrl' => $this->verificationUrl,
                'accessUrl' => route('resident.login'),
                'qrCodePng' => $this->qrCodePng,
            ],
        );
    }
}
