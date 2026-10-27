<?php

namespace App\Http\Controllers\Resident;

use App\Http\Controllers\Controller;
use App\Models\ResidentRegistration;
use App\Support\AuditLogger;
use App\Support\ResidentRegistrationEmailVerificationService;
use App\Http\Middleware\EnsureResidentRegistrationAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class RegistrationEmailVerificationController extends Controller
{
    private const DUMMY_PASSWORD_HASH = '$2y$12$S4su.OH..2iC5npAkAcXSOgsdPhGeKICW25xFk9YBiIBwLMpXveem';

    public function __construct(
        private readonly ResidentRegistrationEmailVerificationService $verification,
    ) {}

    public function verify(Request $request, string $token): Response
    {
        $result = $this->verification->verify($token);

        if ($result['status'] === 'verified' && $result['registration_id'] !== null) {
            $registration = ResidentRegistration::query()
                ->whereKey($result['registration_id'])
                ->first(['id', 'barangay_id']);

            if ($registration !== null) {
                AuditLogger::record(
                    'resident_registration.email_verified',
                    $registration->barangay_id,
                    null,
                    'resident_registration',
                    $registration->id,
                    [],
                    $request->ip(),
                );
            }
        }

        [$title, $message] = match ($result['status']) {
            'verified' => [
                'Email verified',
                'The email address for this registration is verified. This does not approve the application; authorized barangay personnel will continue the review.',
            ],
            'expired' => [
                'Verification link expired',
                'This verification link has expired. Use your application reference and registration password to request another link.',
            ],
            'email_changed' => [
                'Email address changed',
                'This link no longer matches the email address on the application. Request a new link using the updated address.',
            ],
            default => [
                'Verification link unavailable',
                'This link is invalid, expired, or already used. If you still need to verify your registration email, request a new link.',
            ],
        };

        return Inertia::render('Resident/RegistrationEmailVerification', [
            'status' => $result['status'],
            'title' => $title,
            'message' => $message,
        ]);
    }

    public function showResend(Request $request): Response
    {
        return Inertia::render('Resident/RegistrationVerificationResend', [
            'feedback' => $request->session()->get('verification_feedback'),
        ]);
    }

    public function resend(Request $request): RedirectResponse
    {
        $reference = $request->input('reference');
        $password = $request->input('password');
        $registration = is_string($reference) && Str::isUuid($reference)
            ? ResidentRegistration::query()->where('public_id', $reference)->first()
            : null;

        $passwordMatches = Hash::check(
            is_string($password) ? $password : '',
            $registration?->password ?? self::DUMMY_PASSWORD_HASH,
        );

        if ($registration !== null && $passwordMatches) {
            if ($this->verification->send($registration)) {
                RateLimiter::clear($this->otpRateLimitKey($registration->id, $request));
            }
        }

        return redirect()
            ->route('resident.registration.verification.resend')
            ->with(
                'verification_feedback',
                'If the application reference and password are correct and a verification code is eligible, it will be sent when the resend limit allows.',
            );
    }

    public function verifyOtp(Request $request, string $reference): RedirectResponse
    {
        $access = $request->session()->get(EnsureResidentRegistrationAccess::SESSION_KEY);
        abort_unless(is_array($access) && isset($access['registration_id']), 404);

        $registration = ResidentRegistration::query()
            ->where('public_id', $reference)
            ->firstOrFail(['id', 'public_id', 'barangay_id']);

        abort_unless((int) $access['registration_id'] === (int) $registration->id, 404);

        $key = $this->otpRateLimitKey((int) $registration->id, $request);
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors([
                'code' => 'Too many code attempts. Request a new code after the cooldown and try again.',
            ]);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/D'],
        ]);
        $result = $this->verification->verifyOtp(
            (int) $registration->id,
            $validated['code'],
        );

        if ($result['status'] !== 'verified') {
            RateLimiter::hit($key, 600);

            return back()->withErrors([
                'code' => 'That code is invalid, expired, or already used. Request a new code if needed.',
            ]);
        }

        RateLimiter::clear($key);
        AuditLogger::record(
            'resident_registration.email_verified',
            $registration->barangay_id,
            null,
            'resident_registration',
            $registration->id,
            [],
            $request->ip(),
        );

        return back()->with(
            'email_verification_feedback',
            'Email verified. This confirms control of the email address; it does not approve the application.',
        );
    }

    private function otpRateLimitKey(int $registrationId, Request $request): string
    {
        return 'resident-registration-email-otp:'.$registrationId.':'.hash(
            'sha256',
            (string) $request->ip(),
        );
    }
}
