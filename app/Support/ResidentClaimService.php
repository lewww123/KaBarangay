<?php

namespace App\Support;

use App\Models\Resident;
use App\Models\ResidentAccount;
use App\Models\ResidentClaim;
use App\Models\ResidentNotification;
use App\Models\ResidentQrCredential;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ResidentClaimService
{
    public function __construct(
        private readonly ResidentNotificationService $notifications
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function submit(array $data): ResidentClaim
    {
        return DB::transaction(function () use ($data) {
            $residentNumber = Str::upper(trim((string) $data['resident_number']));

            $resident = Resident::query()
                ->where('resident_number', $residentNumber)
                ->lockForUpdate()
                ->first();

            if (! $resident || $resident->status !== 'active') {
                throw $this->invalidClaim();
            }

            $token = $this->extractQrToken((string) $data['qr_reference']);

            $credential = ResidentQrCredential::query()
                ->where('resident_id', $resident->id)
                ->where('token', $token)
                ->where('is_active', true)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->first();

            if (! $credential) {
                throw $this->invalidClaim();
            }

            if (Str::lower(trim((string) $data['last_name'])) !==
                Str::lower(trim($resident->last_name))) {
                throw $this->invalidClaim();
            }

            $birthdate = Carbon::parse($data['birthdate'])->toDateString();

            if ($birthdate !== $resident->birthdate?->toDateString()) {
                throw $this->invalidClaim();
            }

            $email = Str::lower(trim((string) $data['email']));

            if ($resident->email === null ||
                Str::lower(trim($resident->email)) !== $email) {
                throw ValidationException::withMessages([
                    'email' => 'Use the email address currently recorded for this Resident.',
                ]);
            }

            if ($resident->account()->exists()) {
                throw ValidationException::withMessages([
                    'resident_number' => 'This Resident already has a portal account.',
                ]);
            }

            $existingClaim = ResidentClaim::query()
                ->where('resident_id', $resident->id)
                ->whereIn('status', [
                    ResidentClaim::STATUS_PENDING,
                    ResidentClaim::STATUS_APPROVED,
                ])
                ->lockForUpdate()
                ->exists();

            if ($existingClaim) {
                throw ValidationException::withMessages([
                    'resident_number' => 'A claim for this Resident is already under review.',
                ]);
            }

            return ResidentClaim::create([
                'public_id' => (string) Str::uuid(),
                'resident_id' => $resident->id,
                'barangay_id' => $resident->barangay_id,
                'purok_id' => $resident->purok_id,
                'qr_credential_id' => $credential->id,
                'email' => $email,
                'password' => Hash::make((string) $data['password']),
                'status' => ResidentClaim::STATUS_PENDING,
                'identity_checked_at' => now(),
                'identity_method' => 'last_name_and_birthdate',
            ]);
        });
    }

    public function approve(
        ResidentClaim $claim,
        int $reviewerId
    ): ResidentNotification {
        return DB::transaction(function () use ($claim, $reviewerId) {
            $lockedClaim = ResidentClaim::query()
                ->whereKey($claim->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedClaim->status !== ResidentClaim::STATUS_PENDING) {
                throw $this->claimNotPending();
            }

            $resident = Resident::query()
                ->whereKey($lockedClaim->resident_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($resident->status !== 'active') {
                throw ValidationException::withMessages([
                    'claim' => 'This Resident is inactive and cannot be linked.',
                ]);
            }

            if ($resident->account()->exists()) {
                throw ValidationException::withMessages([
                    'claim' => 'This Resident already has a portal account.',
                ]);
            }

            if ($resident->email_verified_at === null ||
                Str::lower(trim((string) $resident->email)) !==
                    Str::lower(trim($lockedClaim->email))) {
                throw ValidationException::withMessages([
                    'claim' => 'The Resident email has not been verified. Ask the claimant to verify it before approving.',
                ]);
            }

            $credential = ResidentQrCredential::query()
                ->whereKey($lockedClaim->qr_credential_id)
                ->where('resident_id', $resident->id)
                ->where('is_active', true)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->first();

            if (! $credential) {
                throw ValidationException::withMessages([
                    'claim' => 'The QR credential used for this claim is no longer active. Reissue a QR code and ask the claimant to submit a new claim.',
                ]);
            }

            ResidentAccount::create([
                'resident_id' => $resident->id,
                'password' => $lockedClaim->password,
                'is_active' => true,
                'last_login_at' => null,
                'password_changed_at' => null,
            ]);

            $lockedClaim->forceFill([
                'status' => ResidentClaim::STATUS_APPROVED,
                'review_notes' => null,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
            ])->save();

            return $this->notifications->enqueueApprovalConfirmation(
                $resident,
                $lockedClaim
            );
        });
    }

    public function reject(
        ResidentClaim $claim,
        int $reviewerId,
        string $reason
    ): ResidentClaim {
        return DB::transaction(function () use ($claim, $reviewerId, $reason) {
            $lockedClaim = ResidentClaim::query()
                ->whereKey($claim->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedClaim->status !== ResidentClaim::STATUS_PENDING) {
                throw $this->claimNotPending();
            }

            $lockedClaim->forceFill([
                'status' => ResidentClaim::STATUS_REJECTED,
                'review_notes' => trim($reason),
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
            ])->save();

            return $lockedClaim;
        });
    }

    private function extractQrToken(string $reference): string
    {
        $reference = trim($reference);

        if (preg_match('/(KBQR-[A-Za-z0-9]+)/', $reference, $matches) === 1) {
            return $matches[1];
        }

        return $reference;
    }

    private function invalidClaim(): ValidationException
    {
        return ValidationException::withMessages([
            'identity' => 'The Resident number, QR reference, name, or birthdate could not be verified.',
        ]);
    }

    private function claimNotPending(): ValidationException
    {
        return ValidationException::withMessages([
            'claim' => 'This claim is no longer awaiting review.',
        ]);
    }
}
