<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreResidentRequest;
use App\Models\Barangay;
use App\Models\Resident;
use App\Models\ResidentQrCredential;
use App\Support\AuditLogger;
use App\Support\ResidentNotificationService;
use App\Support\ResidentNumberGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ResidentController extends Controller
{
    public function __construct(
        private readonly ResidentNumberGenerator $residentNumbers,
        private readonly ResidentNotificationService $notifications,
    ) {}

    public function store(StoreResidentRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        [$resident, $notification] = DB::transaction(function () use ($validated, $user) {
            $barangay = Barangay::query()
                ->whereKey($user->barangay_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertContactIsUnique($barangay->id, $validated);

            $resident = Resident::query()->create([
                'public_id' => (string) Str::uuid(),
                'resident_registration_id' => null,
                'barangay_id' => $barangay->id,
                'purok_id' => $validated['purok_id'],
                'resident_number' => $this->residentNumbers->next(
                    $barangay,
                    (int) $validated['purok_id']
                ),
                'source' => 'admin',
                'created_by' => $user->id,
                'first_name' => $validated['first_name'],
                'middle_name' => $validated['middle_name'] ?? null,
                'last_name' => $validated['last_name'],
                'suffix' => $validated['suffix'] ?? null,
                'birthdate' => $validated['birthdate'],
                'sex' => $validated['sex'],
                'civil_status' => $validated['civil_status'],
                'address_line' => $validated['address_line'],
                'mobile' => $validated['mobile'],
                'email' => $validated['email'],
                'email_verified_at' => null,
                'status' => 'active',
                'approved_at' => now(),
            ]);

            ResidentQrCredential::query()->create([
                'resident_id' => $resident->id,
                'token' => 'KBQR-'.Str::random(64),
                'is_active' => true,
                'generated_at' => now(),
                'revoked_at' => null,
            ]);

            $notification = $resident->email
                ? $this->notifications->enqueueRegistrationInvitation($resident)
                : null;

            return [$resident, $notification];
        });

        if ($notification) {
            $notification = $this->notifications->deliver($notification);
        }

        AuditLogger::record(
            'resident.created',
            $resident->barangay_id,
            $user->id,
            'resident',
            $resident->id,
            ['new_status' => $resident->status],
            $request->ip(),
        );

        $message = match (true) {
            ! $resident->email => 'Resident created. No registration invitation was sent because no email address was provided.',
            $notification?->status === \App\Models\ResidentNotification::STATUS_SENT => 'Resident created. The registration invitation asks them to prepare their Resident ID and QR reference.',
            default => 'Resident created. The registration invitation could not be delivered and can be retried from the notification queue.',
        };

        return back()->with('success', $message);
    }

    public function showQrCredential(
        Request $request,
        Resident $resident
    ): JsonResponse {
        abort_unless(
            (int) $resident->barangay_id ===
                (int) $request->user()->barangay_id,
            404
        );

        $credential = ResidentQrCredential::query()
            ->where('resident_id', $resident->id)
            ->where('is_active', true)
            ->whereNull('revoked_at')
            ->first();

        if (! $credential) {
            throw ValidationException::withMessages([
                'qr' => 'This resident has no active QR credential. Reissue one first.',
            ]);
        }

        AuditLogger::record(
            'resident.qr.delivered',
            $resident->barangay_id,
            $request->user()->id,
            'resident',
            $resident->id,
            [],
            $request->ip(),
        );

        return response()
            ->json([
                'resident_number' => $resident->resident_number,
                'full_name' => $resident->full_name,
                'verification_url' => route('resident.qr.verify', [
                    'token' => $credential->token,
                ]),
            ])
            ->header('Cache-Control', 'private, no-store, max-age=0');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertContactIsUnique(int $barangayId, array $data): void
    {
        $mobileExists = Resident::query()
            ->where('barangay_id', $barangayId)
            ->where('mobile', $data['mobile'])
            ->exists();

        if ($mobileExists) {
            throw ValidationException::withMessages([
                'mobile' => 'A resident with this mobile number already exists in your barangay.',
            ]);
        }

        if (! empty($data['email'])) {
            $emailExists = Resident::query()
                ->where('barangay_id', $barangayId)
                ->whereRaw('LOWER(email) = ?', [$data['email']])
                ->exists();

            if ($emailExists) {
                throw ValidationException::withMessages([
                    'email' => 'A resident with this email address already exists in your barangay.',
                ]);
            }
        }
    }
}
