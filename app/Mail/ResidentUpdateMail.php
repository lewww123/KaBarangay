<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Messages\MailMessage;

class ResidentUpdateMail extends Mailable
{
    public function __construct(public MailMessage $update) {}

    public function build(): static
    {
        return $this->subject($this->update->subject)
            ->markdown('notifications::email', $this->update->data());
    }
}
