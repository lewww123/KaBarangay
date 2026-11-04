<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Resident;
use App\Models\StaffAssignment;
use App\Support\StaffDepartments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StaffManagementController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->input('search', ''));
        $barangayId = $request->user()->barangay_id;

        $assignments = StaffAssignment::query()
            ->with(['resident:id,first_name,middle_name,last_name,suffix,resident_number', 'user:id,name,email'])
            ->where('barangay_id', $barangayId)
            ->when($search !== '', function ($query) use ($search) {
                foreach (preg_split('/\s+/', mb_strtolower($search), -1, PREG_SPLIT_NO_EMPTY) as $word) {
                    $departments = array_keys(array_filter(StaffDepartments::LABELS, fn ($label) => str_contains(mb_strtolower($label), $word)));
                    $query->where(function ($query) use ($word, $departments) {
                        $query->whereRaw('LOWER(department) LIKE ?', ["%{$word}%"])
                            ->orWhereRaw('LOWER(position) LIKE ?', ["%{$word}%"])
                            ->orWhereIn('department', $departments)
                            ->orWhereHas('resident', function ($query) use ($word) {
                                $query->whereRaw('LOWER(first_name) LIKE ?', ["%{$word}%"])
                                    ->orWhereRaw('LOWER(middle_name) LIKE ?', ["%{$word}%"])
                                    ->orWhereRaw('LOWER(last_name) LIKE ?', ["%{$word}%"])
                                    ->orWhereRaw('LOWER(resident_number) LIKE ?', ["%{$word}%"]);
                            })
                            ->orWhereHas('user', fn ($query) => $query->whereRaw('LOWER(email) LIKE ?', ["%{$word}%"]));
                    });
                }
            })
            ->latest()
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (StaffAssignment $assignment) => [
                'id' => $assignment->id,
                'resident' => [
                    'id' => $assignment->resident->id,
                    'name' => $assignment->resident->full_name,
                    'resident_number' => $assignment->resident->resident_number,
                ],
                'user_email' => $assignment->user->email,
                'department' => $assignment->department,
                'position' => $assignment->position,
                'department_label' => StaffDepartments::LABELS[$assignment->department] ?? $assignment->department,
                'is_active' => $assignment->is_active,
            ]);

        return Inertia::render('Admin/Staff', [
            'assignments' => $assignments,
            'filters' => ['search' => $search],
            'departments' => collect(StaffDepartments::LABELS)->map(fn ($label, $value) => [
                'value' => $value, 'label' => $label, 'position' => StaffDepartments::POSITIONS[$value],
            ])->values(),
        ]);
    }

    public function searchResidents(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('search', ''));

        $residentId = $request->validate(['resident_id' => ['nullable', 'integer', 'min:1']])['resident_id'] ?? null;
        if ($search === '' && $residentId === null) {
            return response()->json(['residents' => []]);
        }

        $residents = Resident::query()
            ->where('barangay_id', $request->user()->barangay_id)
            ->where('status', 'active')
            ->whereHas('account', fn ($query) => $query->where('is_active', true))
            ->whereHas('user', fn ($query) => $query->where('barangay_id', $request->user()->barangay_id)->where('status', 'active'))
            ->when($residentId !== null, fn ($query) => $query->whereKey($residentId))
            ->where(function ($query) use ($search) {
                foreach (preg_split('/\s+/', mb_strtolower($search), -1, PREG_SPLIT_NO_EMPTY) as $word) {
                    $query->where(function ($query) use ($word) {
                        $query->whereRaw('LOWER(first_name) LIKE ?', ["%{$word}%"])
                            ->orWhereRaw('LOWER(middle_name) LIKE ?', ["%{$word}%"])
                            ->orWhereRaw('LOWER(last_name) LIKE ?', ["%{$word}%"])
                            ->orWhereRaw('LOWER(resident_number) LIKE ?', ["%{$word}%"]);
                    });
                }
            })
            ->orderBy('last_name')
            ->limit(10)
            ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'resident_number'])
            ->map(fn (Resident $resident) => [
                'id' => $resident->id,
                'name' => $resident->full_name,
                'resident_number' => $resident->resident_number,
            ]);

        return response()->json(['residents' => $residents]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'resident_id' => ['required', 'integer'],
            'department' => ['required', 'string', 'max:50'],
            'position' => ['required', 'string', 'max:100'],
        ]);

        DB::transaction(function () use ($request, $validated): void {
            $resident = $this->eligibleResident($request, $validated['resident_id']);
            $this->ensureRoleAvailable($resident->id, (int) $request->user()->barangay_id, $validated['department']);
            StaffAssignment::create([
                'user_id' => $resident->user->id,
                'resident_id' => $resident->id,
                'barangay_id' => $request->user()->barangay_id,
                'department' => trim($validated['department']),
                'position' => trim($validated['position']),
                'is_active' => true,
            ]);
        });

        return back()->with('success', 'Staff assignment created.');
    }

    public function update(Request $request, StaffAssignment $staff_assignment): RedirectResponse
    {
        $this->authorizeAssignment($request, $staff_assignment);

        $validated = $request->validate([
            'department' => ['required', 'string', 'max:50'],
            'position' => ['required', 'string', 'max:100'],
        ]);

        DB::transaction(function () use ($staff_assignment, $validated): void {
            Resident::query()->whereKey($staff_assignment->resident_id)->lockForUpdate()->firstOrFail();
            $this->ensureRoleAvailable($staff_assignment->resident_id, $staff_assignment->barangay_id, $validated['department'], $staff_assignment->id);
            $staff_assignment->update([
                'department' => trim($validated['department']),
                'position' => trim($validated['position']),
            ]);
        });

        return back()->with('success', 'Staff assignment updated.');
    }

    public function deactivate(Request $request, StaffAssignment $staff_assignment): RedirectResponse
    {
        $this->authorizeAssignment($request, $staff_assignment);
        $staff_assignment->update(['is_active' => false]);

        return back()->with('success', 'Staff assignment deactivated.');
    }

    private function eligibleResident(Request $request, int $residentId): Resident
    {
        $resident = Resident::query()
            ->with(['account', 'user'])
            ->where('id', $residentId)
            ->where('barangay_id', $request->user()->barangay_id)
            ->where('status', 'active')
            ->lockForUpdate()
            ->first();

        if (! $resident || ! $resident->account?->is_active || ! $resident->user || $resident->user->status !== 'active' || (int) $resident->user->barangay_id !== (int) $request->user()->barangay_id) {
            throw ValidationException::withMessages([
                'resident_id' => 'The resident must have an account and linked user before receiving a staff assignment.',
            ]);
        }

        return $resident;
    }

    private function ensureRoleAvailable(int $residentId, int $barangayId, string $department, ?int $exceptId = null): void
    {
        // Department is the login role. Renaming the position must not allow
        // a second assignment to that same role, including inactive records.
        $exists = StaffAssignment::query()->where('resident_id', $residentId)
            ->where('barangay_id', $barangayId)
            ->whereRaw('LOWER(TRIM(department)) = ?', [mb_strtolower(trim($department))])
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages([
                'department' => 'This resident is already assigned to this role. Edit the existing staff assignment instead.',
            ]);
        }
    }

    private function authorizeAssignment(Request $request, StaffAssignment $assignment): void
    {
        abort_unless(
            (int) $assignment->barangay_id === (int) $request->user()->barangay_id,
            403,
            'Unauthorized action.'
        );
    }
}
