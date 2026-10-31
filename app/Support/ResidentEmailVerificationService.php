<?php

namespace App\Support;

use App\Mail\ResidentEmailVerificationMail;
use App\Models\Resident;
use App\Models\ResidentEmailVerificationToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

final class ResidentEmailVerificationService
{
    public const TTL_MINUTES = 60;

    public const RESEND_COOLDOWN_SECONDS = 60;

    public function send(
        Resident $resident,
        bool $ignoreCooldown = false
    ): bool {
        $email = $this->normalizedEmail($resident->email);

        if ($email === '') {
            return false;
        }

        if ($resident->email_verified_at !== null) {
            return false;
        }

        if (! $ignoreCooldown && $this->recentTokenExists($resident->id, $email)) {
            return false;
        }

        $token = Str::random(64);
        $tokenHash = hash('sha256', $token);
        $expiresAt = now()->addMinutes(self::TTL_MINUTES);

        DB::transaction(function () use ($resident, $email, $tokenHash, $expiresAt) {
            ResidentEmailVerificationToken::query()
                ->where('resident_id', $resident->id)
                ->whereNull('consumed_at')
                ->delete();

            ResidentEmailVerificationToken::create([
                'resident_id' => $resident->id,
                'resident_claim_id' => null,
                'email' => $email,
                'token_hash' => $tokenHash,
                'expires_at' => $expiresAt,
            ]);
        });

        try {
            Mail::to($email)->send(new ResidentEmailVerificationMail(
                residentName: $resident->full_name,
                verificationUrl: route('resident.email.verify', [
                    'token' => $token,
                ]),
            ));
        } catch (Throwable $exception) {
            ResidentEmailVerificationToken::query()
                ->where('resident_id', $resident->id)
                ->where('token_hash', $tokenHash)
                ->delete();

            Log::warning('Failed to send resident email verification.', [
                'resident_id' => $resident->id,
                'exception' => $exception::class,
            ]);

            return false;
        }

        return true;
    }

    /**
     * @return array{status: string, resident_id: int|null}
     */
    public function verify(string $token): array
    {
        $tokenHash = hash('sha256', $token);

        return DB::transaction(function () use ($tokenHash) {
            $record = ResidentEmailVerificationToken::query()
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if (! $record || $record->consumed_at !== null) {
                return ['status' => 'invalid', 'resident_id' => null];
            }

            if ($record->expires_at->isPast()) {
                $record->delete();

                return ['status' => 'expired', 'resident_id' => null];
            }

            $resident = Resident::query()
                ->whereKey($record->resident_id)
                ->lockForUpdate()
                ->first();

            if (! $resident) {
                $record->delete();

                return ['status' => 'invalid', 'resident_id' => null];
            }

            if ($this->normalizedEmail($resident->email) !==
                $this->normalizedEmail($record->email)) {
                $record->delete();

                return [
                    'status' => 'email_changed',
                    'resident_id' => $resident->id,
                ];
            }

            $record->delete();

            ResidentEmailVerificationToken::query()
                ->where('resident_id', $resident->id)
                ->whereNull('consumed_at')
                ->delete();

            if ($resident->email_verified_at === null) {
                $resident->forceFill([
                    'email_verified_at' => now(),
                ])->save();
            }

            return [
                'status' => 'verified',
                'resident_id' => $resident->id,
            ];
        });
    }

    public function invalidate(Resident $resident): void
    {
        ResidentEmailVerificationToken::query()
            ->where('resident_id', $resident->id)
            ->whereNull('consumed_at')
            ->delete();
    }

    public function normalizedEmail(?string $email): string
    {
        return Str::lower(trim((string) $email));
    }

    private function recentTokenExists(int $residentId, string $email): bool
    {
        return ResidentEmailVerificationToken::query()
            ->where('resident_id', $residentId)
            ->where('email', $email)
            ->whereNull('consumed_at')
            ->where('created_at', '>=', now()->subSeconds(
                self::RESEND_COOLDOWN_SECONDS
            ))
            ->exists();
    }
}
