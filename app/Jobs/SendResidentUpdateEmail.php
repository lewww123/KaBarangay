<?php

namespace App\Jobs;

use App\Mail\ResidentUpdateMail;
use App\Models\Resident;
use App\Models\ResidentInboxNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Mail;

class SendResidentUpdateEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $notificationId)
    {
        $this->onQueue('resident-updates');
    }

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        $notification = ResidentInboxNotification::find($this->notificationId);
        if (! $notification) {
            return;
        }
        $resident = Resident::query()->approvedAccount()
            ->whereKey($notification->resident_id)
            ->where('barangay_id', $notification->barangay_id)->first();
        if (! $resident?->email || ! $resident->email_verified_at) {
            return;
        }

        $message = (new MailMessage)->subject($notification->title)
            ->greeting('Hello!')->line($notification->message)
            ->action('View update', url($notification->url))
            ->line('Sign in to your KaBarangay account to see the details.');
        Mail::to($resident->email)->send(new ResidentUpdateMail($message));
    }
}
