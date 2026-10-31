<?php

namespace App\Console\Commands;

use App\Mail\TestResendMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class TestResendMailCommand extends Command
{
    protected $signature = 'mail:test-resend {email : Recipient email address permitted by your Resend account}';

    protected $description = 'Send a synchronous test email through the configured Resend SMTP connection';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));
        if (Validator::make(['email' => $email], ['email' => ['required', 'email:rfc']])->fails()) {
            $this->error('Please provide a valid recipient email address.');

            return self::INVALID;
        }

        $mailer = config('mail.default');
        $transport = config("mail.mailers.{$mailer}.transport");
        if ($transport !== 'smtp') {
            $this->error("Configured mailer [{$mailer}] is not an SMTP transport. Set MAIL_MAILER=smtp and run php artisan config:clear.");

            return self::FAILURE;
        }

        $this->line("Sending Resend SMTP test to {$email}...");

        try {
            Mail::to($email)->send(new TestResendMail);
        } catch (TransportExceptionInterface $exception) {
            $this->error('SMTP delivery failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("SMTP server accepted the test email for {$email}.");
        $this->line('Check the inbox or spam folder to confirm mailbox delivery.');

        return self::SUCCESS;
    }
}
