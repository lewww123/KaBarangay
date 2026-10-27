<?php

namespace App\Http\Controllers\Resident;

use App\Http\Controllers\Controller;
use App\Models\ResidentClaim;
use App\Support\AuditLogger;
use App\Support\ResidentEmailVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ResidentClaimController extends Controller
{
    public function __construct(
        private readonly ResidentEmailVerificationService $emailVerification,
    ) {}

    public function create(): RedirectResponse
    {
        return redirect()->route('resident.register');
    }

    public function store(): RedirectResponse
    {
        return redirect()
            ->route('resident.register')
            ->with('error', 'Resident account applications are submitted through Create Resident Registration.');
    }

    public function submitted(string $publicId): Response
    {
        $claim = ResidentClaim::query()
            ->where('public_id', $publicId)
            ->first();

        abort_if($claim === null, 404);

        return Inertia::render('Resident/ClaimSubmitted', [
            'claim' => [
                'reference' => $claim->public_id,
                'status' => $claim->status,
            ],
        ]);
    }

    public function resendVerification(
        Request $request,
        string $publicId
    ): RedirectResponse {
        $claim = ResidentClaim::query()
            ->with('resident')
            ->where('public_id', $publicId)
            ->firstOrFail();

        abort_unless(
            $claim->status === ResidentClaim::STATUS_PENDING,
            422
        );

        $sent = $claim->resident?->email_verified_at === null && $claim->resident !== null
            ? $this->emailVerification->send($claim->resident, $claim)
            : false;

        if ($sent) {
            AuditLogger::record(
                'resident.email_verification_sent',
                $claim->barangay_id,
                null,
                'resident',
                $claim->resident_id,
                [],
                $request->ip(),
            );
        }

        return back()->with(
            'success',
            'If this application is eligible, a verification message will be sent to the registered address.'
        );
    }
}
