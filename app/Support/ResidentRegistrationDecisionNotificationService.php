<?php

namespace App\Support;

use App\Mail\ResidentRegistrationApprovedMail;
use App\Mail\ResidentRegistrationDecisionMail;
use App\Models\ResidentAccount;
use App\Models\ResidentNotification;
use App\Models\ResidentRegistration;
use App\Models\ResidentRegistrationCorrectionCycle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

final class ResidentRegistrationDecisionNotificationService
{
    private const LEASE_MINUTES = 5;

    public function deliverAfterCommit(int $notificationId): void
    {
        DB::afterCommit(function () use ($notificationId): void {
            $notification = ResidentNotification::query()->find($notificationId);
            if ($notification !== null) {
                $this->deliver($notification);
            }
        });
    }

    public function enqueue(
        ResidentRegistration $registration,
        string $decision,
        ?ResidentRegistrationCorrectionCycle $cycle = null,
        ?string $emailSafeReason = null,
    ): ResidentNotification {
        $verification = app(ResidentRegistrationEmailVerificationService::class);
        $recipient = $verification->verifiedDecisionEmail($registration);
        $normalizedAddress = Str::lower(trim((string) $registration->email));
        $recipientName = trim(implode(' ', array_filter([
            $registration->first_name,
            $registration->middle_name,
            $registration->last_name,
            $registration->suffix,
        ])));

        if ($decision === 'correction' && $cycle === null) {
            throw new LogicException('A correction notification requires its persisted cycle.');
        }

        if ($decision === 'rejected') {
            $reviewNumber = ((int) $registration->correctionCycles()->max('cycle_number')) + 1;
            $eventKey = "resident.registration.rejected.{$registration->id}.{$reviewNumber}";
        } elseif ($decision === 'correction') {
            $eventKey = "resident.registration.correction.{$cycle->id}";
        } else {
            $eventKey = "resident.registration.approved.{$registration->id}";
        }

        // A later decision makes older undelivered decisions obsolete.
        ResidentNotification::query()
            ->where('resident_registration_id', $registration->id)
            ->where('type', ResidentNotification::TYPE_REGISTRATION_DECISION)
            ->whereIn('status', [ResidentNotification::STATUS_PENDING, ResidentNotification::STATUS_FAILED])
            ->update([
                'status' => ResidentNotification::STATUS_DEFERRED,
                'deferred_at' => now(),
                'failure_class' => 'obsolete_decision',
                'last_error' => 'Superseded by a later registration decision.',
                'lease_token' => null,
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);

        $eligible = $recipient !== null && $normalizedAddress !== '' &&
            ($decision !== 'rejected' || trim((string) $emailSafeReason) !== '');

        return ResidentNotification::query()->firstOrCreate(
            ['event_key' => $eventKey],
            [
                'type' => ResidentNotification::TYPE_REGISTRATION_DECISION,
                'resident_id' => null,
                'resident_claim_id' => null,
                'resident_registration_id' => $registration->id,
                'correction_cycle_id' => $cycle?->id,
                'decision_type' => $decision,
                'recipient_email' => $recipient ?? $normalizedAddress,
                'recipient_name' => $recipientName,
                'email_safe_reason' => $decision === 'rejected' && $eligible
                    ? trim((string) $emailSafeReason)
                    : null,
                'status' => $eligible
                    ? ResidentNotification::STATUS_PENDING
                    : ResidentNotification::STATUS_DEFERRED,
                'deferred_at' => $eligible ? null : now(),
                'failure_class' => $eligible ? null : $this->ineligibilityClass(
                    $recipient,
                    $normalizedAddress,
                    $decision,
                    $emailSafeReason,
                ),
                'last_error' => $eligible ? null : 'Notification is not currently eligible for delivery.',
            ],
        );
    }

    public function deliver(ResidentNotification $notification): ResidentNotification
    {
        $lease = DB::transaction(function () use ($notification): ?string {
            $locked = ResidentNotification::query()
                ->whereKey($notification->id)
                ->lockForUpdate()
                ->first();

            if (
                $locked === null ||
                $locked->type !== ResidentNotification::TYPE_REGISTRATION_DECISION ||
                $locked->status === ResidentNotification::STATUS_SENT ||
                $locked->status === ResidentNotification::STATUS_DEFERRED ||
                ($locked->lease_expires_at !== null && $locked->lease_expires_at->isFuture())
            ) {
                return null;
            }

            $registration = $locked->registration;
            if (! $this->isCurrentAndEligible($locked, $registration)) {
                $locked->forceFill([
                    'status' => ResidentNotification::STATUS_DEFERRED,
                    'deferred_at' => now(),
                    'failure_class' => 'ineligible_or_obsolete',
                    'last_error' => 'Notification is no longer eligible for delivery.',
                    'lease_token' => null,
                    'lease_expires_at' => null,
                ])->save();

                return null;
            }

            $token = (string) Str::uuid();
            $locked->forceFill([
                'attempts' => $locked->attempts + 1,
                'lease_token' => $token,
                'lease_expires_at' => now()->addMinutes(self::LEASE_MINUTES),
            ])->save();

            return $token;
        });

        if ($lease === null) {
            return $notification->fresh();
        }

        $notification->refresh();

        try {
            Mail::to($notification->recipient_email)->send(
                $this->mailableFor($notification)
            );

            ResidentNotification::query()
                ->whereKey($notification->id)
                ->where('lease_token', $lease)
                ->update([
                    'status' => ResidentNotification::STATUS_SENT,
                    'sent_at' => now(),
                    'failed_at' => null,
                    'last_error' => null,
                    'failure_class' => null,
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'updated_at' => now(),
                ]);
        } catch (Throwable $exception) {
            ResidentNotification::query()
                ->whereKey($notification->id)
                ->where('lease_token', $lease)
                ->update([
                    'status' => ResidentNotification::STATUS_FAILED,
                    'failed_at' => now(),
                    'last_error' => $exception::class,
                    'failure_class' => $exception::class,
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'updated_at' => now(),
                ]);

            Log::warning('Failed to deliver resident registration decision notification.', [
                'notification_id' => $notification->id,
                'decision_type' => $notification->decision_type,
                'exception' => $exception::class,
            ]);
        }

        return $notification->fresh();
    }

    public function retry(ResidentNotification $notification): ResidentNotification
    {
        if (
            $notification->type !== ResidentNotification::TYPE_REGISTRATION_DECISION ||
            $notification->status !== ResidentNotification::STATUS_FAILED
        ) {
            throw new LogicException('Only failed registration decision notifications can be retried.');
        }

        return $this->deliver($notification);
    }

    private function isCurrentAndEligible(
        ResidentNotification $notification,
        ?ResidentRegistration $registration,
    ): bool {
        if ($registration === null) {
            return false;
        }

        $verifiedEmail = app(ResidentRegistrationEmailVerificationService::class)
            ->verifiedDecisionEmail($registration);
        if ($verifiedEmail === null || $verifiedEmail !== $notification->recipient_email) {
            return false;
        }

        return match ($notification->decision_type) {
            'approved' => $registration->status === 'approved' &&
                $registration->matched_resident_id !== null &&
                ResidentAccount::query()
                    ->where('resident_id', $registration->matched_resident_id)
                    ->where('is_active', true)
                    ->exists(),
            'rejected' => $registration->status === 'rejected' &&
                trim((string) $notification->email_safe_reason) !== '',
            'correction' => $this->isCurrentCorrection($registration, $notification),
            default => false,
        };
    }

    private function isCurrentCorrection(
        ResidentRegistration $registration,
        ResidentNotification $notification,
    ): bool {
        if ($registration->status !== 'needs_correction' || $notification->correction_cycle_id === null) {
            return false;
        }

        $cycle = ResidentRegistrationCorrectionCycle::query()
            ->whereKey($notification->correction_cycle_id)
            ->where('resident_registration_id', $registration->id)
            ->first();

        return $cycle !== null && $cycle->resubmitted_at === null &&
            (int) ResidentRegistrationCorrectionCycle::query()
                ->where('resident_registration_id', $registration->id)
                ->max('cycle_number') === (int) $cycle->cycle_number;
    }

    private function mailableFor(ResidentNotification $notification): ResidentRegistrationDecisionMail|ResidentRegistrationApprovedMail
    {
        if ($notification->decision_type === 'approved') {
            $registration = ResidentRegistration::query()
                ->with('matchedResident.qrCredential')
                ->find($notification->resident_registration_id);
            $resident = $registration?->matchedResident;
            $credential = $resident?->qrCredential;

            if (! $registration || ! $resident || ! $credential || ! $credential->is_active || $credential->revoked_at !== null) {
                throw new LogicException('Approved Resident QR credential is unavailable.');
            }

            return new ResidentRegistrationApprovedMail(
                residentName: $notification->recipient_name,
                residentNumber: $resident->resident_number,
                verificationUrl: route('resident.qr.verify', ['token' => $credential->token]),
                approvedAt: $registration->reviewed_at?->format('F j, Y') ?? now()->format('F j, Y'),
            );
        }

        $instructions = $notification->decision_type === 'correction'
            ? $notification->correctionCycle?->instructions
            : null;

        if ($notification->decision_type === 'correction' && $instructions === null) {
            throw new LogicException('Correction instructions are unavailable.');
        }

        return new ResidentRegistrationDecisionMail(
            residentName: $notification->recipient_name,
            decisionType: $notification->decision_type,
            correctionInstructions: $instructions,
            emailSafeReason: $notification->decision_type === 'rejected'
                ? $notification->email_safe_reason
                : null,
        );
    }

    private function ineligibilityClass(
        ?string $recipient,
        string $normalizedAddress,
        string $decision,
        ?string $emailSafeReason,
    ): string {
        if ($recipient === null || $normalizedAddress === '') {
            return 'recipient_unverified';
        }

        if ($decision === 'rejected' && trim((string) $emailSafeReason) === '') {
            return 'resident_reason_unavailable';
        }

        return 'recipient_ineligible';
    }
}
