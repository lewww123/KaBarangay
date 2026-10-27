<?php

namespace App\Support;

use App\Mail\ResidentRegistrationEmailVerificationMail;
use App\Models\ResidentRegistration;
use App\Models\ResidentRegistrationEmailVerificationToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

final class ResidentRegistrationEmailVerificationService
{
    public const TOKEN_TTL_MINUTES = 60;

    public const OTP_TTL_MINUTES = 10;

    public const RESEND_COOLDOWN_SECONDS = 60;

    /**
     * Issue a fresh single-use verification credential and deliver it only to
     * the email currently stored on this registration.
     */
    public function send(ResidentRegistration $registration): bool
    {
        if (! $this->schemaAvailable()) {
            return false;
        }

        $applicationKey = (string) config('app.key');
        if ($applicationKey === '') {
            return false;
        }

        $code = (string) random_int(100000, 999999);
        $tokenHash = $this->otpHash($code, $applicationKey);
        $now = now();

        try {
            $issuedToken = DB::transaction(function () use ($registration, $tokenHash, $now) {
                $locked = ResidentRegistration::query()
                    ->whereKey($registration->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($locked === null || $locked->email_verified_at !== null) {
                    return null;
                }

                $email = $this->normalizedEmail($locked->email);
                if ($email === '') {
                    return null;
                }

                $lastSent = ResidentRegistrationEmailVerificationToken::query()
                    ->where('resident_registration_id', $locked->getKey())
                    ->whereNotNull('last_sent_at')
                    ->orderByDesc('last_sent_at')
                    ->lockForUpdate()
                    ->first();

                if (
                    $lastSent?->last_sent_at !== null &&
                    $lastSent->last_sent_at->gt(now()->subSeconds(self::RESEND_COOLDOWN_SECONDS))
                ) {
                    return null;
                }

                $sendCount = (int) ResidentRegistrationEmailVerificationToken::query()
                    ->where('resident_registration_id', $locked->getKey())
                    ->max('send_count');

                ResidentRegistrationEmailVerificationToken::query()
                    ->where('resident_registration_id', $locked->getKey())
                    ->whereNull('consumed_at')
                    ->update([
                        'consumed_at' => $now,
                        'updated_at' => $now,
                    ]);

                return ResidentRegistrationEmailVerificationToken::query()->create([
                    'resident_registration_id' => $locked->getKey(),
                    'token_hash' => $tokenHash,
                    'email_snapshot' => $email,
                    'expires_at' => $now->copy()->addMinutes(self::OTP_TTL_MINUTES),
                    'consumed_at' => null,
                    'last_sent_at' => $now,
                    'send_count' => $sendCount + 1,
                ]);
            });
        } catch (Throwable $exception) {
            Log::warning('Unable to create resident registration verification token.', [
                'registration_id' => $registration->getKey(),
                'exception' => $exception::class,
            ]);

            return false;
        }

        if ($issuedToken === null) {
            return false;
        }

        $current = $registration->fresh();
        if ($current === null || $this->normalizedEmail($current->email) !== $issuedToken->email_snapshot) {
            $issuedToken->forceFill(['consumed_at' => now()])->save();

            return false;
        }

        try {
            Mail::to($issuedToken->email_snapshot)->send(
                new ResidentRegistrationEmailVerificationMail(
                    applicantName: trim(implode(' ', array_filter([
                        $current->first_name,
                        $current->middle_name,
                        $current->last_name,
                        $current->suffix,
                    ]))),
                    verificationCode: $code,
                    applicationAccessUrl: route('resident.registration.access'),
                )
            );
        } catch (Throwable $exception) {
            $issuedToken->forceFill(['consumed_at' => now()])->save();
            Log::warning('Unable to deliver resident registration verification email.', [
                'registration_id' => $registration->getKey(),
                'exception' => $exception::class,
            ]);

            return false;
        }

        AuditLogger::record(
            'resident_registration.email_verification_sent',
            $current->barangay_id,
            null,
            'resident_registration',
            $current->getKey(),
            [],
        );

        return true;
    }

    /** @return array{status: 'verified'|'expired'|'email_changed'|'invalid', registration_id: int|null} */
    public function verifyOtp(int $registrationId, string $code): array
    {
        if (
            ! $this->schemaAvailable() ||
            preg_match('/^[0-9]{6}$/D', $code) !== 1
        ) {
            return ['status' => 'invalid', 'registration_id' => null];
        }

        $applicationKey = (string) config('app.key');
        if ($applicationKey === '') {
            return ['status' => 'invalid', 'registration_id' => null];
        }

        $tokenHash = $this->otpHash($code, $applicationKey);

        return DB::transaction(function () use ($registrationId, $tokenHash) {
            $registration = ResidentRegistration::query()
                ->whereKey($registrationId)
                ->lockForUpdate()
                ->first();

            if ($registration === null) {
                return ['status' => 'invalid', 'registration_id' => null];
            }

            $record = ResidentRegistrationEmailVerificationToken::query()
                ->where('resident_registration_id', $registration->getKey())
                ->where('token_hash', $tokenHash)
                ->whereNull('consumed_at')
                ->lockForUpdate()
                ->first();

            if ($record === null) {
                return ['status' => 'invalid', 'registration_id' => $registration->getKey()];
            }

            if ($record->expires_at->isPast()) {
                $record->forceFill(['consumed_at' => now()])->save();

                return ['status' => 'expired', 'registration_id' => $registration->getKey()];
            }

            if ($this->normalizedEmail($registration->email) !== $record->email_snapshot) {
                $record->forceFill(['consumed_at' => now()])->save();

                return ['status' => 'email_changed', 'registration_id' => $registration->getKey()];
            }

            $verifiedAt = $registration->email_verified_at ?? now();
            $record->forceFill(['consumed_at' => $verifiedAt])->save();
            $registration->forceFill(['email_verified_at' => $verifiedAt])->save();

            ResidentRegistrationEmailVerificationToken::query()
                ->where('resident_registration_id', $registration->getKey())
                ->whereNull('consumed_at')
                ->update([
                    'consumed_at' => $verifiedAt,
                    'updated_at' => $verifiedAt,
                ]);

            return ['status' => 'verified', 'registration_id' => $registration->getKey()];
        });
    }

    /** @return array{status: 'verified'|'expired'|'email_changed'|'invalid', registration_id: int|null} */
    public function verify(string $token): array
    {
        if (! $this->schemaAvailable() || strlen($token) !== 64) {
            return ['status' => 'invalid', 'registration_id' => null];
        }

        $tokenHash = hash('sha256', $token);

        return DB::transaction(function () use ($tokenHash) {
            $record = ResidentRegistrationEmailVerificationToken::query()
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if ($record === null || $record->consumed_at !== null) {
                return ['status' => 'invalid', 'registration_id' => null];
            }

            $registration = ResidentRegistration::query()
                ->whereKey($record->resident_registration_id)
                ->lockForUpdate()
                ->first();

            if ($registration === null) {
                $record->forceFill(['consumed_at' => now()])->save();

                return ['status' => 'invalid', 'registration_id' => null];
            }

            if ($record->expires_at->isPast()) {
                $record->forceFill(['consumed_at' => now()])->save();

                return ['status' => 'expired', 'registration_id' => $registration->getKey()];
            }

            if ($this->normalizedEmail($registration->email) !== $record->email_snapshot) {
                $record->forceFill(['consumed_at' => now()])->save();

                return ['status' => 'email_changed', 'registration_id' => $registration->getKey()];
            }

            $record->forceFill(['consumed_at' => now()])->save();
            $registration->forceFill(['email_verified_at' => now()])->save();

            ResidentRegistrationEmailVerificationToken::query()
                ->where('resident_registration_id', $registration->getKey())
                ->whereNull('consumed_at')
                ->update([
                    'consumed_at' => now(),
                    'updated_at' => now(),
                ]);

            return ['status' => 'verified', 'registration_id' => $registration->getKey()];
        });
    }

    public function verifiedDecisionEmail(ResidentRegistration $registration): ?string
    {
        if (
            ! $this->schemaAvailable() ||
            $registration->email_verified_at === null
        ) {
            return null;
        }

        $email = $this->normalizedEmail($registration->email);
        if ($email === '') {
            return null;
        }

        $verifiedSnapshotExists = ResidentRegistrationEmailVerificationToken::query()
            ->where('resident_registration_id', $registration->getKey())
            ->where('email_snapshot', $email)
            ->whereNotNull('consumed_at')
            ->where('expires_at', '>', $registration->email_verified_at)
            ->exists();

        return $verifiedSnapshotExists ? $email : null;
    }

    private function normalizedEmail(?string $email): string
    {
        return Str::lower(trim((string) $email));
    }

    private function otpHash(string $code, string $applicationKey): string
    {
        return hash_hmac('sha256', $code, $applicationKey);
    }

    private function schemaAvailable(): bool
    {
        return Schema::hasTable('resident_registration_email_verification_tokens') &&
            Schema::hasColumn('resident_registrations', 'email_verified_at');
    }
}
