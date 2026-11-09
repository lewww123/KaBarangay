<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AddHouseholdMemberRequest;
use App\Http\Requests\Admin\StoreHouseholdRequest;
use App\Http\Requests\Admin\TransferHouseholdMemberRequest;
use App\Http\Requests\Admin\UpdateHouseholdRequest;
use App\Models\Household;
use App\Models\Resident;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HouseholdController extends Controller
{
    public function store(StoreHouseholdRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $user = $request->user();
        $barangayId = (int) $user->barangay_id;

        $household = DB::transaction(function () use ($validated, $user, $barangayId) {
            $code = ! empty($validated['household_code'])
                ? $validated['household_code']
                : $this->generateHouseholdCode($barangayId);

            $household = Household::query()->create([
                'barangay_id' => $barangayId,
                'purok_id' => $validated['purok_id'],
                'household_code' => $code,
                'head_resident_id' => null,
            ]);

            $members = $validated['members'] ?? [];
            foreach ($members as $memberData) {
                $resident = Resident::query()
                    ->lockForUpdate()
                    ->where('barangay_id', $barangayId)
                    ->findOrFail($memberData['resident_id']);

                if ($resident->household_id && $resident->household_id !== $household->id) {
                    Household::query()
                        ->where('id', $resident->household_id)
                        ->where('head_resident_id', $resident->id)
                        ->update(['head_resident_id' => null]);
                }

                $resident->update([
                    'household_id' => $household->id,
                    'household_relationship' => $memberData['household_relationship'],
                ]);
            }

            if (! empty($validated['head_resident_id'])) {
                $household->update([
                    'head_resident_id' => $validated['head_resident_id'],
                ]);
            }

            return $household;
        });

        AuditLogger::record(
            'household.created',
            $barangayId,
            $user->id,
            'household',
            $household->id,
            ['household_code' => $household->household_code],
            $request->ip(),
        );

        return back()->with('success', "Household {$household->household_code} created successfully.");
    }

    public function show(Request $request, Household $household): JsonResponse
    {
        abort_unless(
            (int) $household->barangay_id === (int) $request->user()->barangay_id,
            404
        );

        $household->load([
            'purok:id,name',
            'head:id,first_name,middle_name,last_name,suffix,mobile,email',
            'members' => function ($query) use ($household) {
                $query->select([
                    'id',
                    'barangay_id',
                    'purok_id',
                    'household_id',
                    'first_name',
                    'middle_name',
                    'last_name',
                    'suffix',
                    'birthdate',
                    'sex',
                    'mobile',
                    'email',
                    'household_relationship',
                ])->orderByRaw("CASE WHEN id = ? THEN 0 ELSE 1 END", [$this->getHeadId($household)])
                  ->orderBy('last_name')
                  ->orderBy('first_name');
            },
        ]);

        return response()->json([
            'data' => [
                'id' => $household->id,
                'code' => $household->household_code,
                'purok_id' => $household->purok_id,
                'purok_name' => $household->purok?->name,
                'head_resident_id' => $household->head_resident_id,
                'head' => $household->head ? [
                    'id' => $household->head->id,
                    'name' => $household->head->full_name,
                    'mobile' => $household->head->mobile,
                    'email' => $household->head->email,
                ] : null,
                'members_count' => $household->members->count(),
                'members' => $household->members->map(fn (Resident $m) => [
                    'id' => $m->id,
                    'name' => $m->full_name,
                    'first_name' => $m->first_name,
                    'last_name' => $m->last_name,
                    'relationship' => $m->household_relationship,
                    'birthdate' => $m->birthdate?->format('Y-m-d'),
                    'sex' => $m->sex,
                    'mobile' => $m->mobile,
                    'email' => $m->email,
                    'is_head' => (int) $m->id === (int) $household->head_resident_id,
                ]),
                'created_at' => $household->created_at?->format('Y-m-d H:i'),
            ],
        ]);
    }

    public function update(UpdateHouseholdRequest $request, Household $household): RedirectResponse
    {
        abort_unless(
            (int) $household->barangay_id === (int) $request->user()->barangay_id,
            404
        );

        $validated = $request->validated();
        $user = $request->user();

        DB::transaction(function () use ($validated, $household) {
            $household = Household::query()->lockForUpdate()->findOrFail($household->id);

            // Household codes are immutable after creation; only location and
            // head assignment may change.
            $household->update([
                'purok_id' => $validated['purok_id'],
                'head_resident_id' => $validated['head_resident_id'] ?? null,
            ]);
        });

        AuditLogger::record(
            'household.updated',
            $household->barangay_id,
            $user->id,
            'household',
            $household->id,
            ['household_code' => $household->household_code],
            $request->ip(),
        );

        return back()->with('success', "Household {$household->household_code} updated successfully.");
    }

    public function addMember(AddHouseholdMemberRequest $request, Household $household): RedirectResponse
    {
        abort_unless(
            (int) $household->barangay_id === (int) $request->user()->barangay_id,
            404
        );

        $validated = $request->validated();
        $user = $request->user();

        DB::transaction(function () use ($validated, $household) {
            $household = Household::query()->lockForUpdate()->findOrFail($household->id);
            $resident = Resident::query()
                ->lockForUpdate()
                ->where('barangay_id', $household->barangay_id)
                ->findOrFail($validated['resident_id']);

            if ($resident->household_id && $resident->household_id !== $household->id) {
                Household::query()
                    ->where('id', $resident->household_id)
                    ->where('head_resident_id', $resident->id)
                    ->update(['head_resident_id' => null]);
            }

            $resident->update([
                'household_id' => $household->id,
                'household_relationship' => $validated['household_relationship'],
            ]);

            if (! empty($validated['is_head'])) {
                $household->update([
                    'head_resident_id' => $resident->id,
                ]);
            }
        });

        AuditLogger::record(
            'household.member_added',
            $household->barangay_id,
            $user->id,
            'household',
            $household->id,
            ['resident_id' => $validated['resident_id']],
            $request->ip(),
        );

        return back()->with('success', 'Resident added to household successfully.');
    }

    public function removeMember(Request $request, Household $household, Resident $resident): RedirectResponse
    {
        abort_unless(
            (int) $household->barangay_id === (int) $request->user()->barangay_id,
            404
        );
        abort_unless(
            (int) $resident->barangay_id === (int) $request->user()->barangay_id,
            404
        );
        abort_unless(
            (int) $resident->household_id === (int) $household->id,
            404
        );

        $user = $request->user();

        DB::transaction(function () use ($household, $resident) {
            $household = Household::query()->lockForUpdate()->findOrFail($household->id);
            $resident = Resident::query()->lockForUpdate()->findOrFail($resident->id);

            if ((int) $household->head_resident_id === (int) $resident->id) {
                $household->update(['head_resident_id' => null]);
            }

            $resident->update([
                'household_id' => null,
                'household_relationship' => null,
            ]);
        });

        AuditLogger::record(
            'household.member_removed',
            $household->barangay_id,
            $user->id,
            'household',
            $household->id,
            ['resident_id' => $resident->id],
            $request->ip(),
        );

        return back()->with('success', 'Resident removed from household successfully.');
    }

    public function transferMember(TransferHouseholdMemberRequest $request, Household $household, Resident $resident): RedirectResponse
    {
        abort_unless(
            (int) $household->barangay_id === (int) $request->user()->barangay_id,
            404
        );
        abort_unless(
            (int) $resident->barangay_id === (int) $request->user()->barangay_id,
            404
        );
        abort_unless(
            (int) $resident->household_id === (int) $household->id,
            404
        );

        $validated = $request->validated();
        $user = $request->user();

        DB::transaction(function () use ($validated, $household, $resident) {
            $sourceHousehold = Household::query()->lockForUpdate()->findOrFail($household->id);
            $targetHousehold = Household::query()
                ->lockForUpdate()
                ->where('barangay_id', $sourceHousehold->barangay_id)
                ->findOrFail($validated['target_household_id']);
            $resident = Resident::query()->lockForUpdate()->findOrFail($resident->id);

            if ((int) $sourceHousehold->head_resident_id === (int) $resident->id) {
                $sourceHousehold->update(['head_resident_id' => null]);
            }

            $resident->update([
                'household_id' => $targetHousehold->id,
                'household_relationship' => $validated['household_relationship'],
            ]);

            if (! empty($validated['is_head'])) {
                $targetHousehold->update([
                    'head_resident_id' => $resident->id,
                ]);
            }
        });

        AuditLogger::record(
            'household.member_transferred',
            $household->barangay_id,
            $user->id,
            'household',
            $household->id,
            [
                'resident_id' => $resident->id,
                'target_household_id' => $validated['target_household_id'],
            ],
            $request->ip(),
        );

        return back()->with('success', 'Resident transferred to target household successfully.');
    }

    private function generateHouseholdCode(int $barangayId): string
    {
        for ($i = 0; $i < 10; $i++) {
            $code = 'HH-'.Str::upper(Str::random(8));
            $exists = Household::query()
                ->where('barangay_id', $barangayId)
                ->where('household_code', $code)
                ->exists();

            if (! $exists) {
                return $code;
            }
        }

        return 'HH-'.Str::upper(Str::random(10));
    }

    private function getHeadId(Household $household): int
    {
        return (int) ($household->head_resident_id ?? 0);
    }
}
