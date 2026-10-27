<?php

namespace App\Http\Controllers\Resident;

use App\Http\Controllers\Controller;
use App\Models\Resident;
use App\Support\AuditLogger;
use App\Support\ResidentEmailVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ResidentEmailVerificationController extends Controller
{
    public function __construct(
        private readonly ResidentEmailVerificationService $emailVerification
    ) {}

    public function verify(Request $request, string $token): Response
    {
        $result = $this->emailVerification->verify($token);

        if ($result['status'] === 'verified' &&
            $result['resident_id'] !== null) {
            AuditLogger::record(
                'resident.email_verified',
                Resident::query()
                    ->whereKey($result['resident_id'])
                    ->value('barangay_id'),
                null,
                'resident',
                $result['resident_id'],
                [],
                $request->ip(),
            );
        }

        [$title, $message] = match ($result['status']) {
            'verified' => [
                'Email verified',
                'The Resident email address has been verified. You can close this page. A barangay administrator can now review the claim.',
            ],
            'expired' => [
                'Verification link expired',
                'This verification link has expired. Return to the claim page and request a new link.',
            ],
            'email_changed' => [
                'Email address changed',
                'The Resident email address changed after this link was issued. Request a new verification link.',
            ],
            default => [
                'Invalid verification link',
                'This verification link is invalid or has already been used.',
            ],
        };

        return Inertia::render('Resident/EmailVerification', [
            'status' => $result['status'],
            'title' => $title,
            'message' => $message,
        ]);
    }

    public function resend(Request $request): RedirectResponse
    {
        $resident = Resident::query()
            ->whereKey($request->session()->get('resident_id'))
            ->where('status', 'active')
            ->firstOrFail();

        if ($resident->email_verified_at !== null) {
            return back()->with(
                'success',
                'Your email address is already verified.'
            );
        }

        $sent = $this->emailVerification->send($resident);

        if ($sent) {
            AuditLogger::record(
                'resident.email_verification_sent',
                $resident->barangay_id,
                null,
                'resident',
                $resident->id,
                [],
                $request->ip(),
            );
        }

        return back()->with(
            $sent ? 'success' : 'error',
            $sent
                ? 'A verification link was sent to your email address.'
                : 'A verification link could not be sent yet. Wait a minute and try again.'
        );
    }
}
