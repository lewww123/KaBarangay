<?php

namespace App\Observers;

use App\Jobs\SendResidentUpdateEmail;
use App\Models\AnnouncementAttendance;
use App\Models\CertificateRequest;
use App\Models\ResidentInboxNotification;

class ResidentUpdateObserver
{
    public function saved(CertificateRequest|AnnouncementAttendance $record): void
    {
        if (! $record->wasRecentlyCreated && ! $record->wasChanged('status')) {
            return;
        }

        if ($record instanceof CertificateRequest) {
            if ($record->status !== 'Ready for Claim') {
                return;
            }
            $title = 'Certificate ready for pickup';
            $message = "Your certificate request {$record->reference} is ready for claim. Please visit the barangay office during office hours and bring your valid ID.";
            $url = '/resident/certificates';
        } else {
            if (! in_array($record->status, ['present', 'claimed'], true)) {
                return;
            }
            $record->loadMissing('announcement');
            $title = $record->status === 'present' ? 'Attendance confirmed' : 'Aid claim confirmed';
            $program = $record->announcement?->title ?? 'your program';
            $message = $record->status === 'present'
                ? "Your attendance at {$program} has been recorded by barangay staff."
                : "Your aid claim for {$program} has been recorded by barangay staff.";
            $url = '/resident/programs';
        }

        $notification = ResidentInboxNotification::firstOrCreate(
            ['event_key' => $record->getTable().':'.$record->id.':'.$record->status],
            [
                'resident_id' => $record->resident_id,
                'barangay_id' => $record->barangay_id,
                'title' => $title,
                'message' => $message,
                'url' => $url,
            ],
        );

        if ($notification->wasRecentlyCreated) {
            SendResidentUpdateEmail::dispatch($notification->id)->afterCommit();
        }
    }
}
