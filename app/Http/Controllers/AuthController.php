<?php

namespace App\Http\Controllers;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Barangay Selection
    |--------------------------------------------------------------------------
    */

    public function selectBarangay(): Response
    {
        $barangays = Barangay::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'code',
            ]);

        return Inertia::render('Auth/SelectBarangay', [
            'barangays' => $barangays,
        ]);
    }

    public function storeBarangay(Request $request)
    {
        $validated = $request->validate([
            'barangay_id' => [
                'required',
                'integer',
                'exists:barangays,id',
            ],
        ]);

        $barangay = Barangay::query()
            ->where('id', $validated['barangay_id'])
            ->where('is_active', true)
            ->firstOrFail();

        $request->session()->put('selected_barangay_id', $barangay->id);

        return redirect()->route('department.login');
    }

    /*
    |--------------------------------------------------------------------------
    | Department Login
    |--------------------------------------------------------------------------
    */

    public function departmentLogin(Request $request): Response|RedirectResponse
    {
        $barangayId = $request->session()->get('selected_barangay_id');

        if (! $barangayId) {
            return redirect()->route('barangay.select');
        }

        $barangay = Barangay::findOrFail($barangayId);

        return Inertia::render('Auth/DepartmentLogin', [
            'barangay' => $barangay,
        ]);
    }

    public function processDepartmentLogin(Request $request)
    {
        $validated = $request->validate([
            'department' => [
                'required',
                'string',
                'in:admin,lupon,senior,pwd,clerk,senior_staff,pwd_staff',
            ],
            'username' => [
                'required',
                'string',
                'max:255',
            ],
            'password' => [
                'required',
                'string',
            ],
        ]);

        $barangayId = $request->session()->get('selected_barangay_id');

        if (! $barangayId) {
            return redirect()
                ->route('barangay.select')
                ->withErrors([
                    'login' => 'Please select your barangay first.',
                ]);
        }

        $user = User::query()
            ->where('barangay_id', $barangayId)
            ->where('status', 'active')
            ->where(function ($query) use ($validated) {
                $query->where('role', $validated['department']);
                if ($validated['department'] === 'senior') {
                    $query->orWhere('role', 'senior_staff');
                }
                if ($validated['department'] === 'pwd') {
                    $query->orWhere('role', 'pwd_staff');
                }
            })
            ->where('email', $validated['username'])
            ->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return back()
                ->withErrors([
                    'login' => 'Invalid department credentials.',
                ])
                ->withInput();
        }

        $request->session()->put([
            'login_department' => $validated['department'],
            'department_username' => $validated['username'],
        ]);

        return redirect()->route('staff.login');
    }

    /*
    |--------------------------------------------------------------------------
    | Staff Login
    |--------------------------------------------------------------------------
    */

    public function staffLogin(Request $request): Response|RedirectResponse
    {
        $barangayId = $request->session()->get('selected_barangay_id');
        $department = $request->session()->get('login_department');

        if (! $barangayId) {
            return redirect()->route('barangay.select');
        }

        if (! $department) {
            return redirect()->route('department.login');
        }

        $barangay = Barangay::findOrFail($barangayId);

        return Inertia::render('Auth/StaffLogin', [
            'barangay' => $barangay,
            'department' => $department,
        ]);
    }

    public function processStaffLogin(Request $request)
    {
        $validated = $request->validate([
            'staff_id' => [
                'required',
                'string',
                'max:100',
            ],
            'full_name' => [
                'required',
                'string',
                'max:255',
            ],
            'password' => [
                'required',
                'string',
            ],
        ]);

        $barangayId = $request->session()->get('selected_barangay_id');
        $department = $request->session()->get('login_department');

        if (! $barangayId || ! $department) {
            return redirect()->route('barangay.select');
        }

        $user = User::query()
            ->where('barangay_id', $barangayId)
            ->where(function ($query) use ($department) {
                $query->where('role', $department);
                if ($department === 'senior') {
                    $query->orWhere('role', 'senior_staff');
                }
                if ($department === 'pwd') {
                    $query->orWhere('role', 'pwd_staff');
                }
            })
            ->where('staff_id', $validated['staff_id'])
            ->where('name', $validated['full_name'])
            ->where('status', 'active')
            ->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return back()
                ->withErrors([
                    'login' => 'Invalid staff credentials.',
                ])
                ->withInput();
        }

        /* Authenticate Staff */
        Auth::login($user);

        /* Prevent session fixation after successful login */
        $request->session()->regenerate();

        /* Clear Temporary Login Data */
        $request->session()->forget([
            'login_department',
            'department_username',
        ]);

        /* Role-Based Dashboard Navigation */
        return match ($user->role) {
            'admin' => redirect()->route('admin.dashboard'),
            'clerk' => redirect()->route('clerk.dashboard'),
            'senior', 'senior_staff' => redirect()->route('senior.dashboard'),
            'pwd', 'pwd_staff' => redirect()->route('pwd.dashboard'),
            default => redirect()->route('admin.dashboard'),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Resident Login
    |--------------------------------------------------------------------------
    */

    public function processResidentLogin(Request $request)
    {
        $validated = $request->validate([
            'barangay_id' => [
                'required',
                'integer',
                'exists:barangays,id',
            ],
            'identifier' => [
                'required',
                'string',
                'max:255',
            ],
            'password' => [
                'required',
                'string',
            ],
            'remember' => [
                'nullable',
                'boolean',
            ],
        ]);

        $barangay = Barangay::query()
            ->where('id', $validated['barangay_id'])
            ->where('is_active', true)
            ->first();

        if (! $barangay) {
            return back()
                ->withErrors([
                    'barangay_id' => 'The selected barangay is not available.',
                ])
                ->withInput([
                    'identifier' => $validated['identifier'],
                ]);
        }

        $identifier = trim($validated['identifier']);

        $resident = Resident::query()
            ->with('account')
            ->where('barangay_id', $barangay->id)
            ->where('status', 'active')
            ->where(function ($query) use ($identifier) {
                $query
                    ->where('resident_number', strtoupper($identifier))
                    ->orWhere('mobile', $identifier)
                    ->orWhere('email', strtolower($identifier));
            })
            ->first();

        if (
            ! $resident ||
            ! $resident->account ||
            ! $resident->account->is_active ||
            ! Hash::check($validated['password'], $resident->account->password)
        ) {
            return back()
                ->withErrors([
                    'login' => 'Invalid resident credentials.',
                ])
                ->withInput([
                    'barangay_id' => $validated['barangay_id'],
                    'identifier' => $validated['identifier'],
                ]);
        }

        $request->session()->regenerate();

        $request->session()->put([
            'resident_id' => $resident->id,
            'resident_barangay_id' => $resident->barangay_id,
            'resident_logged_in' => true,
            'resident_remember' => (bool) ($validated['remember'] ?? false),
        ]);

        $resident->account->update([
            'last_login_at' => now(),
        ]);

        return redirect()->route('resident.dashboard');
    }

    /*
    |--------------------------------------------------------------------------
    | Resident Login Barangays
    |--------------------------------------------------------------------------
    |
    | Supplies the active barangay list for the Resident Login dropdown.
    |
    */

    public function residentLoginBarangays()
    {
        $barangays = Barangay::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'code',
                'city_municipality',
                'province',
            ]);

        return response()->json([
            'barangays' => $barangays,
        ]);
    }
}