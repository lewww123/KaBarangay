<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreResidentRequest;
use App\Models\Barangay;
use App\Models\Household;
use App\Models\Resident;
use App\Models\ResidentNotification;
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

            $household = null;
            if (($validated['household_mode'] ?? 'none') === 'new') {
                $household = Household::query()->create([
                    'barangay_id' => $barangay->id,
                    'purok_id' => $validated['purok_id'],
                    'household_code' => 'HH-'.Str::upper(Str::random(8)),
                ]);
            } elseif (($validated['household_mode'] ?? 'none') === 'existing') {
                $household = Household::query()
                    ->where('barangay_id', $barangay->id)
                    ->findOrFail($validated['household_id']);
            }

            $resident = Resident::query()->create([
                'public_id' => (string) Str::uuid(),
                'resident_registration_id' => null,
                'barangay_id' => $barangay->id,
                'purok_id' => $validated['purok_id'],
                'household_id' => $household?->id,
                'household_relationship' => $household
                    ? (($validated['household_mode'] ?? null) === 'new' ? 'Head' : $validated['household_relationship'])
                    : null,
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
                'status' => 'inactive',
                'approved_at' => null,
            ]);

            if ($household && ($validated['household_mode'] ?? null) === 'new') {
                $household->update(['head_resident_id' => $resident->id]);
            }

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
            $notification?->status === ResidentNotification::STATUS_SENT => 'Resident created. The registration invitation asks them to prepare their Resident ID and QR reference.',
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

    public function searchHouseholds(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < 2) {
            return response()->json(['data' => []]);
        }

        $like = '%'.$query.'%';
        $households = Household::query()
            ->where('barangay_id', $request->user()->barangay_id)
            ->with(['head:id,first_name,middle_name,last_name,suffix', 'purok:id,name'])
            ->withCount('members')
            ->where(function ($builder) use ($like) {
                $builder->where('household_code', 'like', $like)
                    ->orWhereHas('head', function ($head) use ($like) {
                        $head->where('first_name', 'like', $like)
                            ->orWhere('middle_name', 'like', $like)
                            ->orWhere('last_name', 'like', $like);
                    });
            })
            ->orderBy('household_code')
            ->limit(15)
            ->get()
            ->map(fn (Household $household) => [
                'id' => $household->id,
                'code' => $household->household_code,
                'head' => $household->head?->full_name ?? 'No head assigned',
                'purok' => $household->purok?->name,
                'members_count' => $household->members_count,
            ]);

        return response()->json(['data' => $households]);
    }

    public function updateHousehold(Request $request, Resident $resident): RedirectResponse
    {
        abort_unless(
            (int) $resident->barangay_id === (int) $request->user()->barangay_id,
            404
        );

        $validated = $request->validate([
            'household_mode' => ['required', 'in:none,new,existing'],
            'household_id' => ['required_if:household_mode,existing', 'nullable', 'integer'],
            'household_relationship' => ['required_unless:household_mode,none', 'nullable', 'string', 'max:100'],
        ]);

        DB::transaction(function () use ($request, $resident, $validated) {
            $resident = Resident::query()->lockForUpdate()->findOrFail($resident->id);
            $household = null;

            if ($validated['household_mode'] === 'new') {
                $household = Household::query()->create([
                    'barangay_id' => $resident->barangay_id,
                    'purok_id' => $resident->purok_id,
                    'household_code' => 'HH-'.Str::upper(Str::random(8)),
                    'head_resident_id' => $resident->id,
                ]);
            }

            if ($validated['household_mode'] === 'existing') {
                $household = Household::query()
                    ->where('barangay_id', $request->user()->barangay_id)
                    ->find($validated['household_id']);

                if (! $household) {
                    throw ValidationException::withMessages([
                        'household_id' => 'Choose a household in your barangay.',
                    ]);
                }
            }

            $resident->update([
                'household_id' => $household?->id,
                'household_relationship' => $household
                    ? ($validated['household_mode'] === 'new' ? 'Head' : $validated['household_relationship'])
                    : null,
            ]);
        });

        AuditLogger::record(
            'resident.household_updated',
            $resident->barangay_id,
            $request->user()->id,
            'resident',
            $resident->id,
            [],
            $request->ip(),
        );

        return back()->with('success', 'Resident household assignment updated.');
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
