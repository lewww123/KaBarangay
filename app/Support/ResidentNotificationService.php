<?php

namespace App\Support;

use App\Mail\ResidentApprovalMail;
use App\Mail\LuponSummonsIssuedMail;
use App\Mail\ResidentRegistrationInvitationMail;
use App\Models\LuponCase;
use App\Models\Resident;
use App\Models\ResidentNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use LogicException;
use Throwable;

final class ResidentNotificationService
{
    public function enqueueRegistrationInvitation(Resident $resident): ResidentNotification
    {
        return ResidentNotification::query()->firstOrCreate(
            [
                'event_key' => 'resident.registration-invitation.'.$resident->id,
            ],
            [
                'type' => ResidentNotification::TYPE_REGISTRATION_INVITATION,
                'resident_id' => $resident->id,
                'resident_claim_id' => null,
                'recipient_email' => (string) $resident->email,
                'recipient_name' => $resident->full_name,
                'status' => ResidentNotification::STATUS_PENDING,
            ],
        );
    }

    public function enqueueLuponSummons(Resident $resident, LuponCase $case): ResidentNotification
    {
        return ResidentNotification::query()->firstOrCreate(
            ['event_key' => 'lupon.summons-issued.'.$case->id.'.'.$resident->id],
            [
                'type' => ResidentNotification::TYPE_LUPON_SUMMONS_ISSUED,
                'resident_id' => $resident->id,
                'recipient_email' => (string) $resident->email,
                'recipient_name' => $resident->full_name,
                'status' => ResidentNotification::STATUS_PENDING,
                'context' => ['case_number' => $case->case_number],
            ],
        );
    }

    /**
     * Deliver an outbox row. Must be called after the surrounding transaction
     * has committed so an email can never describe an approval that rolled back.
     */
    public function deliver(ResidentNotification $notification): ResidentNotification
    {
        if (DB::transactionLevel() > 0) {
            throw new LogicException(
                'Resident notifications must be delivered after commit.'
            );
        }

        $notification->refresh();

        if ($notification->status === ResidentNotification::STATUS_SENT) {
            return $notification;
        }

        $notification->attempts++;

        try {
            Mail::to($notification->recipient_email)->send(
                $this->mailableFor($notification)
            );

            $notification->forceFill([
                'status' => ResidentNotification::STATUS_SENT,
                'sent_at' => now(),
                'failed_at' => null,
                'last_error' => null,
            ])->save();
        } catch (Throwable $exception) {
            $notification->forceFill([
                'status' => ResidentNotification::STATUS_FAILED,
                'failed_at' => now(),
                'last_error' => $exception::class,
            ])->save();

            Log::warning('Failed to deliver resident notification.', [
                'notification_id' => $notification->id,
                'type' => $notification->type,
                'exception' => $exception::class,
            ]);
        }

        return $notification;
    }

    private function mailableFor(ResidentNotification $notification): object
    {
        return match ($notification->type) {
            ResidentNotification::TYPE_APPROVAL_CONFIRMATION =>
                new ResidentApprovalMail(
                    residentName: $notification->recipient_name,
                    loginUrl: route('resident.login'),
                ),
            ResidentNotification::TYPE_REGISTRATION_INVITATION =>
                new ResidentRegistrationInvitationMail(
                    residentName: $notification->recipient_name,
                    registrationUrl: route('resident.register'),
                ),
            ResidentNotification::TYPE_LUPON_SUMMONS_ISSUED =>
                new LuponSummonsIssuedMail(
                    residentName: $notification->recipient_name,
                    caseNumber: (string) ($notification->context['case_number'] ?? 'KP case'),
                ),
            default => throw new LogicException(
                'Unsupported resident notification type.'
            ),
        };
    }
}
