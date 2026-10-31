<?php

namespace App\Support;

use App\Mail\RegistrationOtpMail;
use App\Models\ResidentRegistration;
use App\Models\ResidentRegistrationEmailVerificationToken;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class RegistrationEmailOtp
{
    public const SESSION_KEY = 'registration_email_otp';

    public const TTL_SECONDS = 600;

    public const COOLDOWN_SECONDS = 60;

    public const MAX_ATTEMPTS = 5;

    public function send(Request $request, string $email): array
    {
        $email = $this->normalize($email);
        $cooldownKey = $this->cooldownKey($email);
        $resendAt = now()->timestamp + self::COOLDOWN_SECONDS;
        if (! Cache::add($cooldownKey, $resendAt, self::COOLDOWN_SECONDS)) {
            $retryAfter = max(1, (int) Cache::get($cooldownKey) - now()->timestamp);
            throw new HttpResponseException(response()->json([
                'message' => 'Please wait before requesting another OTP.', 'retry_after' => $retryAfter,
            ], 429)->header('Retry-After', (string) $retryAfter));
        }

        $id = (string) Str::uuid();
        $code = sprintf('%06d', random_int(0, 999999));
        try {
            Mail::to($email)->send(new RegistrationOtpMail($code));
            $stored = Cache::put($this->key($id), [
                'email' => $email, 'code_hash' => $this->hash($id.'|'.$email.'|'.$code),
                'verified' => false, 'attempts' => 0, 'sent_at' => now()->timestamp,
                'expires_at' => now()->timestamp + self::TTL_SECONDS,
            ], self::TTL_SECONDS);
            if (! $stored) {
                throw new RuntimeException('OTP cache is unavailable.');
            }
        } catch (Throwable $exception) {
            Cache::forget($cooldownKey);
            Log::warning('Unable to send pre-registration email OTP.', ['exception' => $exception::class]);
            throw new HttpResponseException(response()->json(['message' => 'Unable to send the OTP right now. Please try again.'], 503));
        }

        $previous = $request->session()->get(self::SESSION_KEY);
        if (is_array($previous) && isset($previous['id'])) {
            Cache::forget($this->key($previous['id']));
        }
        $request->session()->put(self::SESSION_KEY, ['id' => $id, 'email' => $email]);

        return $this->state($request);
    }

    public function verify(Request $request, string $email, string $code): array
    {
        $context = $this->context($request, $email);
        if ($context === null) {
            throw ValidationException::withMessages(['code' => 'Send an OTP to this email address first.']);
        }

        Cache::lock($this->key($context['id']).':lock', 60)->block(5, function () use ($context, $code): void {
            $key = $this->key($context['id']);
            $record = Cache::get($key);
            if (! is_array($record) || $record['expires_at'] <= now()->timestamp) {
                throw ValidationException::withMessages(['code' => 'The OTP has expired. Send a new OTP.']);
            }
            if ($record['verified']) {
                // A retry after a lost HTTP response confirms the existing
                // session proof without reusing the code or extending its TTL.
                return;
            }
            if (! hash_equals($record['code_hash'], $this->hash($context['id'].'|'.$context['email'].'|'.$code))) {
                $record['attempts']++;
                if ($record['attempts'] >= self::MAX_ATTEMPTS) {
                    Cache::forget($key);
                    throw ValidationException::withMessages(['code' => 'Too many incorrect attempts. Send a new OTP.']);
                }
                Cache::put($key, $record, max(1, $record['expires_at'] - now()->timestamp));
                throw ValidationException::withMessages(['code' => 'The verification code is incorrect.']);
            }

            unset($record['code_hash']);
            $record['verified'] = true;
            $record['verified_at'] = now()->timestamp;
            $record['expires_at'] = now()->timestamp + self::TTL_SECONDS;
            if (! Cache::put($key, $record, self::TTL_SECONDS)) {
                throw new HttpResponseException(response()->json(['message' => 'Unable to save email verification. Please try again.'], 503));
            }
        });

        return $this->state($request);
    }

    public function isVerified(Request $request, string $email): bool
    {
        $context = $this->context($request, $email);
        $record = $context !== null ? Cache::get($this->key($context['id'])) : null;

        return is_array($record) && $record['email'] === $this->normalize($email)
            && $record['verified'] && $record['expires_at'] > now()->timestamp;
    }

    /** Consume proof only after the registration transaction has succeeded. */
    public function withVerifiedEmail(Request $request, string $email, Closure $create): mixed
    {
        $context = $this->context($request, $email);
        if ($context === null) {
            throw ValidationException::withMessages(['email_verification' => 'Verify your email with an OTP before submitting registration.']);
        }

        return Cache::lock($this->key($context['id']).':lock', 120)->block(5, function () use ($request, $email, $context, $create) {
            if (! $this->isVerified($request, $email)) {
                throw ValidationException::withMessages(['email_verification' => 'Email verification has expired or was already used. Verify your email again.']);
            }
            $proof = [...Cache::get($this->key($context['id'])), 'id' => $context['id']];
            $result = $create($proof);
            Cache::forget($this->key($context['id']));
            $request->session()->forget(self::SESSION_KEY);

            return $result;
        });
    }

    public function recordVerification(ResidentRegistration $registration, array $proof): void
    {
        $verifiedAt = Carbon::createFromTimestamp($proof['verified_at']);
        $registration->forceFill(['email_verified_at' => $verifiedAt])->save();
        ResidentRegistrationEmailVerificationToken::create([
            'resident_registration_id' => $registration->id,
            'token_hash' => $this->hash('pre-registration:'.$proof['id']),
            'email_snapshot' => $proof['email'],
            'expires_at' => Carbon::createFromTimestamp($proof['expires_at']),
            'consumed_at' => $verifiedAt,
            'last_sent_at' => Carbon::createFromTimestamp($proof['sent_at']),
            'send_count' => 1,
        ]);
    }

    public function state(Request $request): array
    {
        $context = $request->session()->get(self::SESSION_KEY);
        $record = is_array($context) && isset($context['id']) ? Cache::get($this->key($context['id'])) : null;
        $email = is_array($context) ? ($context['email'] ?? '') : '';
        $active = is_array($record) && $record['expires_at'] > now()->timestamp;

        return [
            'status' => $active ? ($record['verified'] ? 'verified' : 'pending') : 'unsent',
            'email' => $email,
            'expires_in' => $active ? $record['expires_at'] - now()->timestamp : 0,
            'retry_after' => $email !== '' ? max(0, (int) Cache::get($this->cooldownKey($email), 0) - now()->timestamp) : 0,
        ];
    }

    private function context(Request $request, string $email): ?array
    {
        $context = $request->session()->get(self::SESSION_KEY);

        return is_array($context) && isset($context['id'], $context['email'])
            && $context['email'] === $this->normalize($email) ? $context : null;
    }

    private function key(string $id): string
    {
        return 'registration:email-otp:'.$this->hash($id);
    }

    private function cooldownKey(string $email): string
    {
        return 'registration:email-otp:cooldown:'.$this->hash($email);
    }

    private function hash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    private function normalize(string $email): string
    {
        return Str::lower(trim($email));
    }
}
