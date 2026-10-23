<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StaffManagementController extends Controller
{
    /**
     * Staff roles an administrator may grant or edit.
     *
     * `super_admin` is deliberately absent: it is a dormant role that must never
     * be created or managed from the portal. The legacy `staff` role is also
     * excluded because it has no portal and no department login.
     */
    private const MANAGEABLE_ROLES = [
        'admin',
        'clerk',
        'senior',
        'senior_staff',
        'pwd',
        'pwd_staff',
        'lupon',
    ];

    private const STATUSES = ['active', 'inactive'];

    public function index(Request $request): Response
    {
        $admin = $request->user();

        $search = trim((string) $request->input('search', ''));
        $roleFilter = trim((string) $request->input('role', ''));
        $statusFilter = trim((string) $request->input('status', ''));

        $query = User::query()
            ->where('barangay_id', $admin->barangay_id)
            ->where('role', '!=', 'super_admin');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('staff_id', 'like', "%{$search}%")
                    ->orWhere('department', 'like', "%{$search}%")
                    ->orWhere('role', 'like', "%{$search}%");
            });
        }

        $roleFilterValid = $roleFilter !== '' && in_array($roleFilter, self::MANAGEABLE_ROLES, true);
        $statusFilterValid = $statusFilter !== '' && in_array($statusFilter, self::STATUSES, true);

        if ($roleFilterValid) {
            $query->where('role', $roleFilter);
        }

        if ($statusFilterValid) {
            $query->where('status', $statusFilter);
        }

        $roleFilter = $roleFilterValid ? $roleFilter : '';
        $statusFilter = $statusFilterValid ? $statusFilter : '';

        $staffPaginator = $query
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        $staffItems = $staffPaginator->getCollection()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'staff_id' => $user->staff_id,
                'department' => $user->department,
                'role' => $user->role,
                'status' => $user->status,
                'is_self' => $user->id === $admin->id,
            ])
            ->values();

        $staffPaginator->setCollection($staffItems);

        // Calculate stats for current filtered view? Or overall? Task says preserve existing behavior - original computed stats from full list.
        // But easier to compute from base (own barangay, non-super_admin) without filters for consistency with "active/total accounts" as before.
        $baseQuery = User::query()
            ->where('barangay_id', $admin->barangay_id)
            ->where('role', '!=', 'super_admin');
        $stats = [
            'active' => (clone $baseQuery)->where('status', 'active')->count(),
            'total' => $baseQuery->count(),
        ];

        return Inertia::render('Admin/Staff', [
            'staff' => $staffPaginator,
            'stats' => $stats,
            'roles' => self::MANAGEABLE_ROLES,
            'filters' => [
                'search' => $search,
                'role' => $roleFilter,
                'status' => $statusFilter,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $admin = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email'),
            ],
            'staff_id' => [
                'required',
                'string',
                'max:100',
                Rule::unique('users', 'staff_id'),
            ],
            'role' => ['required', 'string', Rule::in(self::MANAGEABLE_ROLES)],
            'department' => ['nullable', 'string', 'max:100'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        $staff = User::create([
            'barangay_id' => $admin->barangay_id,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'staff_id' => $validated['staff_id'],
            'role' => $validated['role'],
            'department' => $validated['department'] ?? Str::headline($validated['role']),
            'status' => 'active',
            // Plain value; the model's `hashed` cast stores the bcrypt hash.
            'password' => $validated['password'],
        ]);

        AuditLogger::record(
            'staff.created',
            $admin->barangay_id,
            $admin->id,
            'user',
            $staff->id,
            [
                'new_role' => $staff->role,
                'department' => $staff->department,
            ],
            $request->ip(),
        );

        return back()->with('success', 'Staff account created successfully.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $admin = $request->user();

        $this->authorizeManage($admin, $user);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'staff_id' => [
                'required',
                'string',
                'max:100',
                Rule::unique('users', 'staff_id')->ignore($user->id),
            ],
            'role' => ['required', 'string', Rule::in(self::MANAGEABLE_ROLES)],
            'department' => ['nullable', 'string', 'max:100'],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
        ]);

        if ($user->id === $admin->id && $validated['role'] !== $user->role) {
            abort(403, 'You cannot change your own role.');
        }

        if ($user->role === 'admin' && $validated['role'] !== 'admin') {
            $this->assertNotLastActiveAdmin($user, 'role');
        }

        $attributes = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'staff_id' => $validated['staff_id'],
            'role' => $validated['role'],
        ];

        if (array_key_exists('department', $validated) && $validated['department'] !== null) {
            $attributes['department'] = $validated['department'];
        }

        if (filled($validated['password'] ?? null)) {
            $attributes['password'] = $validated['password'];
        }

        $previousRole = $user->role;

        $user->update($attributes);

        if ($user->wasChanged('role')) {
            $roleContext = [
                'previous_role' => $previousRole,
                'new_role' => $user->role,
            ];

            if ($user->wasChanged('department')) {
                $roleContext['department'] = $user->department;
            }

            AuditLogger::record(
                'staff.role_changed',
                $admin->barangay_id,
                $admin->id,
                'user',
                $user->id,
                $roleContext,
                $request->ip(),
            );
        } elseif ($user->wasChanged(['name', 'email', 'staff_id', 'role', 'department', 'password'])) {
            $updatedContext = [];

            if ($user->wasChanged('department')) {
                $updatedContext['department'] = $user->department;
            }

            if ($user->wasChanged('password')) {
                $updatedContext['action'] = 'password_changed';
            }

            AuditLogger::record(
                'staff.updated',
                $admin->barangay_id,
                $admin->id,
                'user',
                $user->id,
                $updatedContext,
                $request->ip(),
            );
        }

        return back()->with('success', 'Staff account updated successfully.');
    }

    public function updateStatus(Request $request, User $user): RedirectResponse
    {
        $admin = $request->user();

        $this->authorizeManage($admin, $user);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(self::STATUSES)],
        ]);

        if ($user->id === $admin->id) {
            abort(403, 'You cannot change your own account status.');
        }

        if (
            $validated['status'] === 'inactive'
            && $user->role === 'admin'
        ) {
            $this->assertNotLastActiveAdmin($user, 'status');
        }

        $previousStatus = $user->status;

        $user->update(['status' => $validated['status']]);

        if ($user->wasChanged('status')) {
            AuditLogger::record(
                'staff.status_changed',
                $admin->barangay_id,
                $admin->id,
                'user',
                $user->id,
                [
                    'previous_status' => $previousStatus,
                    'new_status' => $user->status,
                ],
                $request->ip(),
            );
        }

        return back()->with('success', 'Staff account status updated successfully.');
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */

    private function authorizeManage(User $admin, User $user): void
    {
        abort_unless(
            (int) $user->barangay_id === (int) $admin->barangay_id,
            403,
            'Unauthorized action.'
        );

        abort_if($user->role === 'super_admin', 403, 'Super admin accounts cannot be managed here.');
    }

    /*
    |--------------------------------------------------------------------------
    | Business Rules
    |--------------------------------------------------------------------------
    */

    private function assertNotLastActiveAdmin(User $user, string $attribute): void
    {
        $remainingActiveAdmins = User::query()
            ->where('barangay_id', $user->barangay_id)
            ->where('id', '!=', $user->id)
            ->where('role', 'admin')
            ->where('status', 'active')
            ->count();

        if ($remainingActiveAdmins < 1) {
            throw ValidationException::withMessages([
                $attribute => 'A barangay must keep at least one active admin account.',
            ]);
        }
    }
}
