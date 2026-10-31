<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResidentRegistrationDecisionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $residentName,
        public string $decisionType,
        public ?string $correctionInstructions = null,
        public ?string $emailSafeReason = null,
    ) {}

    public function envelope(): Envelope
    {
        $subject = match ($this->decisionType) {
            'approved' => 'Your KaBarangay registration was approved',
            'correction' => 'Action needed for your KaBarangay registration',
            'rejected' => 'Update on your KaBarangay registration',
            default => 'Update on your KaBarangay registration',
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.resident-registration-decision',
            with: [
                'residentName' => $this->residentName,
                'decisionType' => $this->decisionType,
                'correctionInstructions' => $this->correctionInstructions,
                'emailSafeReason' => $this->emailSafeReason,
                'accessUrl' => $this->decisionType === 'approved'
                    ? route('resident.login')
                    : route('resident.registration.access'),
            ],
        );
    }
}
