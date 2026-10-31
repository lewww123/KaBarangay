<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Resident;
use App\Models\StaffAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            ->with(['resident:id,first_name,middle_name,last_name,suffix,resident_number', 'user:id,email'])
            ->where('barangay_id', $barangayId)
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('department', 'like', "%{$search}%")
                        ->orWhere('position', 'like', "%{$search}%")
                        ->orWhereHas('resident', function ($query) use ($search) {
                            $query->where('first_name', 'like', "%{$search}%")
                                ->orWhere('middle_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('resident_number', 'like', "%{$search}%");
                        })
                        ->orWhereHas('user', fn ($query) => $query->where('email', 'like', "%{$search}%"));
                });
            })
            ->latest()
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
                'is_active' => $assignment->is_active,
            ]);

        return Inertia::render('Admin/Staff', [
            'assignments' => $assignments,
            'filters' => ['search' => $search],
        ]);
    }

    public function searchResidents(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('search', ''));

        if ($search === '') {
            return response()->json(['residents' => []]);
        }

        $residents = Resident::query()
            ->where('barangay_id', $request->user()->barangay_id)
            ->where('status', 'active')
            ->whereHas('account')
            ->whereHas('user', fn ($query) => $query->where('barangay_id', $request->user()->barangay_id))
            ->where(function ($query) use ($search) {
                $query->where('first_name', 'like', "%{$search}%")
                    ->orWhere('middle_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('resident_number', 'like', "%{$search}%");
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

        $resident = $this->eligibleResident($request, $validated['resident_id']);

        StaffAssignment::create([
            'user_id' => $resident->user->id,
            'resident_id' => $resident->id,
            'barangay_id' => $request->user()->barangay_id,
            'department' => $validated['department'],
            'position' => $validated['position'],
            'is_active' => true,
        ]);

        return back()->with('success', 'Staff assignment created.');
    }

    public function update(Request $request, StaffAssignment $staff_assignment): RedirectResponse
    {
        $this->authorizeAssignment($request, $staff_assignment);

        $validated = $request->validate([
            'department' => ['required', 'string', 'max:50'],
            'position' => ['required', 'string', 'max:100'],
        ]);

        $staff_assignment->update($validated);

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
            ->first();

        if (! $resident || ! $resident->account || ! $resident->user || (int) $resident->user->barangay_id !== (int) $request->user()->barangay_id) {
            throw ValidationException::withMessages([
                'resident_id' => 'The resident must have an account and linked user before receiving a staff assignment.',
            ]);
        }

        return $resident;
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
