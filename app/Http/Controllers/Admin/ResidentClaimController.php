<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ResidentClaim;
use App\Models\ResidentNotification;
use App\Models\ResidentRegistration;
use App\Support\AuditLogger;
use App\Support\ResidentClaimService;
use App\Support\ResidentNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class ResidentClaimController extends Controller
{
    public function __construct(
        private readonly ResidentClaimService $claims,
        private readonly ResidentNotificationService $notifications,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $status = (string) $request->query('status', ResidentClaim::STATUS_PENDING);

        if (! in_array($status, [
            ResidentClaim::STATUS_PENDING,
            'needs_correction',
            ResidentClaim::STATUS_REJECTED,
        ], true)) {
            $status = ResidentClaim::STATUS_PENDING;
        }

        $search = trim((string) $request->query('search', ''));
        $hasRegistrationMatchColumns = Schema::hasColumn(
            'resident_registrations',
            'matched_resident_id'
        );

        $counts = ResidentClaim::query()
            ->where('barangay_id', $user->barangay_id)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $claimsQuery = ResidentClaim::query()
            ->with([
                'resident:id,resident_number,first_name,middle_name,last_name,suffix,email,email_verified_at',
                'reviewer:id,name',
            ])
            ->where('barangay_id', $user->barangay_id)
            ->where('status', $status);

        if ($search !== '') {
            $claimsQuery->where(function ($query) use ($search) {
                $query->where('public_id', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%')
                    ->orWhereHas('resident', fn ($resident) => $resident
                        ->where('resident_number', 'like', '%'.$search.'%')
                        ->orWhere('first_name', 'like', '%'.$search.'%')
                        ->orWhere('last_name', 'like', '%'.$search.'%'));
            });
        }

        $claims = $claimsQuery->latest()
            ->paginate(20, ['*'], 'claims_page')
            ->withQueryString()
            ->through(fn (ResidentClaim $claim) => [
                'id' => $claim->id,
                'reference' => $claim->public_id,
                'residentName' => $claim->resident?->full_name,
                'residentNumber' => $claim->resident?->resident_number,
                'email' => $claim->email,
                'emailVerified' => $claim->resident?->email_verified_at !== null,
                'status' => $claim->status,
                'submittedAt' => $claim->created_at?->format('Y-m-d H:i'),
                'reviewedAt' => $claim->reviewed_at?->format('Y-m-d H:i'),
            ]);

        $registrationRelations = [
            'purok:id,name',
            'resident:id,resident_registration_id,resident_number',
        ];
        if ($hasRegistrationMatchColumns) {
            $registrationRelations[] = 'matchedResident:id,resident_number,first_name,middle_name,last_name,suffix';
        }

        $registrationsQuery = ResidentRegistration::query()
            ->with($registrationRelations)
            ->where('barangay_id', $user->barangay_id)
            ->where('status', $status);

        if ($search !== '') {
            $registrationsQuery->where(function ($query) use ($search, $hasRegistrationMatchColumns) {
                $query->where('public_id', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%')
                    ->orWhere('first_name', 'like', '%'.$search.'%')
                    ->orWhere('middle_name', 'like', '%'.$search.'%')
                    ->orWhere('last_name', 'like', '%'.$search.'%');

                if ($hasRegistrationMatchColumns) {
                    $query->orWhereHas('matchedResident', fn ($resident) => $resident
                        ->where('resident_number', 'like', '%'.$search.'%'));
                }
            });
        }

        $registrations = $registrationsQuery->latest()
            ->paginate(20, ['*'], 'registrations_page')
            ->withQueryString()
            ->through(fn (ResidentRegistration $registration) => [
                'id' => $registration->id,
                'reference' => $registration->public_id,
                'applicantName' => trim(implode(' ', array_filter([
                    $registration->first_name,
                    $registration->middle_name,
                    $registration->last_name,
                    $registration->suffix,
                ]))),
                'email' => $registration->email,
                'status' => $registration->status,
                'submittedAt' => $registration->submitted_at?->format('Y-m-d H:i'),
                'residentNumber' => ($hasRegistrationMatchColumns ? $registration->matchedResident?->resident_number : null)
                    ?? $registration->resident?->resident_number,
            ]);

        return Inertia::render('Admin/ResidentClaims', [
            'claims' => $claims,
            'registrations' => $registrations,
            'status' => $status,
            'search' => $search,
            'counts' => [
                'pending' => (int) ($counts[ResidentClaim::STATUS_PENDING] ?? 0),
                'needs_correction' => ResidentRegistration::query()->where('barangay_id', $user->barangay_id)->where('status', 'needs_correction')->count(),
                'rejected' => (int) ($counts[ResidentClaim::STATUS_REJECTED] ?? 0),
                'registration_pending' => ResidentRegistration::query()->where('barangay_id', $user->barangay_id)->where('status', 'pending')->count(),
                'registration_rejected' => ResidentRegistration::query()->where('barangay_id', $user->barangay_id)->where('status', 'rejected')->count(),
            ],
        ]);
    }

    public function show(Request $request, ResidentClaim $claim): JsonResponse
    {
        $this->authorizeClaim($request, $claim);

        $claim->load([
            'resident:id,resident_number,first_name,middle_name,last_name,suffix,birthdate,sex,civil_status,address_line,mobile,email,email_verified_at,status,purok_id',
            'resident.purok:id,name',
            'purok:id,name',
            'reviewer:id,name',
        ]);

        $notification = $this->latestNotification($claim);

        return response()->json([
            'claim' => [
                'reference' => $claim->public_id,
                'email' => $claim->email,
                'status' => $claim->status,
                'identity_checked_at' => $claim->identity_checked_at?->format('Y-m-d H:i'),
                'identity_method' => $claim->identity_method,
                'review_notes' => $claim->review_notes,
                'submitted_at' => $claim->created_at?->format('Y-m-d H:i'),
                'reviewed_at' => $claim->reviewed_at?->format('Y-m-d H:i'),
                'reviewer' => $claim->reviewer?->name,
            ],
            'resident' => [
                'resident_number' => $claim->resident?->resident_number,
                'full_name' => $claim->resident?->full_name,
                'birthdate' => $claim->resident?->birthdate?->format('Y-m-d'),
                'sex' => $claim->resident?->sex,
                'civil_status' => $claim->resident?->civil_status,
                'address_line' => $claim->resident?->address_line,
                'mobile' => $claim->resident?->mobile,
                'email' => $claim->resident?->email,
                'email_verified' => $claim->resident?->email_verified_at !== null,
                'status' => $claim->resident?->status,
                'purok' => $claim->resident?->purok?->name,
            ],
            'notification' => $notification ? [
                'id' => $notification->id,
                'type' => $notification->type,
                'status' => $notification->status,
                'attempts' => $notification->attempts,
                'sent_at' => $notification->sent_at?->format('Y-m-d H:i'),
                'failed_at' => $notification->failed_at?->format('Y-m-d H:i'),
            ] : null,
        ]);
    }

    public function approve(
        Request $request,
        ResidentClaim $claim
    ): RedirectResponse {
        $this->authorizeClaim($request, $claim);
        $user = $request->user();

        $notification = $this->claims->approve($claim, $user->id);
        $this->notifications->deliver($notification);

        AuditLogger::record(
            'resident_claim.approved',
            $claim->barangay_id,
            $user->id,
            'resident_claim',
            $claim->id,
            [
                'previous_status' => ResidentClaim::STATUS_PENDING,
                'new_status' => ResidentClaim::STATUS_APPROVED,
            ],
            $request->ip(),
        );

        $notification->refresh();

        return back()->with(
            'success',
            $notification->status === ResidentNotification::STATUS_SENT
                ? 'Claim approved, account linked, and confirmation email sent.'
                : 'Claim approved and account linked. The confirmation email failed and can be retried from the claim details.'
        );
    }

    public function reject(
        Request $request,
        ResidentClaim $claim
    ): RedirectResponse {
        $this->authorizeClaim($request, $claim);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $this->claims->reject(
            $claim,
            $request->user()->id,
            $validated['reason']
        );

        AuditLogger::record(
            'resident_claim.rejected',
            $claim->barangay_id,
            $request->user()->id,
            'resident_claim',
            $claim->id,
            [
                'previous_status' => ResidentClaim::STATUS_PENDING,
                'new_status' => ResidentClaim::STATUS_REJECTED,
            ],
            $request->ip(),
        );

        return back()->with('success', 'Resident claim rejected.');
    }

    public function retryNotification(
        Request $request,
        ResidentClaim $claim
    ): RedirectResponse {
        $this->authorizeClaim($request, $claim);

        $notification = $this->latestNotification($claim);

        if (! $notification) {
            throw ValidationException::withMessages([
                'notification' => 'This claim has no notification to retry.',
            ]);
        }

        $this->notifications->deliver($notification);
        $notification->refresh();

        return back()->with(
            $notification->status === ResidentNotification::STATUS_SENT
                ? 'success'
                : 'error',
            $notification->status === ResidentNotification::STATUS_SENT
                ? 'Confirmation email sent.'
                : 'The confirmation email could not be sent.'
        );
    }

    private function authorizeClaim(Request $request, ResidentClaim $claim): void
    {
        abort_unless(
            (int) $claim->barangay_id ===
                (int) $request->user()->barangay_id,
            404
        );
    }

    private function latestNotification(
        ResidentClaim $claim
    ): ?ResidentNotification {
        return ResidentNotification::query()
            ->where('resident_claim_id', $claim->id)
            ->latest('id')
            ->first();
    }
}
