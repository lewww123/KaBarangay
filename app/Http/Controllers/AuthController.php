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

        $request->session()->put(
            'selected_barangay_id',
            $barangay->id
        );

        return redirect()->route('department.login');
    }

    /*
    |--------------------------------------------------------------------------
    | Department Login
    |--------------------------------------------------------------------------
    */

    public function departmentLogin(
        Request $request
    ): Response|RedirectResponse {
        $barangayId = $request
            ->session()
            ->get('selected_barangay_id');

        if (!$barangayId) {
            return redirect()->route(
                'barangay.select'
            );
        }

        $barangay = Barangay::findOrFail(
            $barangayId
        );

        return Inertia::render(
            'Auth/DepartmentLogin',
            [
                'barangay' => $barangay,
            ]
        );
    }

    public function processDepartmentLogin(
        Request $request
    ) {
        $validated = $request->validate([
            'department' => [
                'required',
                'string',
                'in:admin,lupon,senior,pwd,clerk',
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

        $barangayId = $request
            ->session()
            ->get('selected_barangay_id');

        if (!$barangayId) {
            return redirect()
                ->route('barangay.select')
                ->withErrors([
                    'login' =>
                        'Please select your barangay first.',
                ]);
        }

        $user = User::query()
            ->where(
                'barangay_id',
                $barangayId
            )
            ->where(
                'status',
                'active'
            )
            ->where(
                'role',
                $validated['department']
            )
            ->where(
                'email',
                $validated['username']
            )
            ->first();

        if (
            !$user ||
            !Hash::check(
                $validated['password'],
                $user->password
            )
        ) {
            return back()
                ->withErrors([
                    'login' =>
                        'Invalid department credentials.',
                ])
                ->withInput();
        }

        $request->session()->put([
            'login_department' =>
                $validated['department'],

            'department_username' =>
                $validated['username'],
        ]);

        return redirect()->route(
            'staff.login'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Staff Login
    |--------------------------------------------------------------------------
    */

    public function staffLogin(
        Request $request
    ): Response|RedirectResponse {
        $barangayId = $request
            ->session()
            ->get('selected_barangay_id');

        $department = $request
            ->session()
            ->get('login_department');

        if (!$barangayId) {
            return redirect()->route(
                'barangay.select'
            );
        }

        if (!$department) {
            return redirect()->route(
                'department.login'
            );
        }

        $barangay = Barangay::findOrFail(
            $barangayId
        );

        return Inertia::render(
            'Auth/StaffLogin',
            [
                'barangay' =>
                    $barangay,

                'department' =>
                    $department,
            ]
        );
    }

    public function processStaffLogin(
        Request $request
    ) {
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

        $barangayId = $request
            ->session()
            ->get('selected_barangay_id');

        $department = $request
            ->session()
            ->get('login_department');

        if (
            !$barangayId ||
            !$department
        ) {
            return redirect()->route(
                'barangay.select'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Find Staff Account
        |--------------------------------------------------------------------------
        |
        | The department selected during the first login is stored in
        | the user's "role" field.
        |
        | Example:
        | role       = admin
        | department = Administration
        |
        */

        $user = User::query()
            ->where(
                'barangay_id',
                $barangayId
            )
            ->where(
                'role',
                $department
            )
            ->where(
                'staff_id',
                $validated['staff_id']
            )
            ->where(
                'name',
                $validated['full_name']
            )
            ->where(
                'status',
                'active'
            )
            ->first();

        if (
            !$user ||
            !Hash::check(
                $validated['password'],
                $user->password
            )
        ) {
            return back()
                ->withErrors([
                    'login' =>
                        'Invalid staff credentials.',
                ])
                ->withInput();
        }

        /*
        |--------------------------------------------------------------------------
        | Authenticate Staff
        |--------------------------------------------------------------------------
        */

        Auth::login($user);

        /*
         * Prevent session fixation after successful login.
         */
        $request->session()->regenerate();

        /*
        |--------------------------------------------------------------------------
        | Clear Temporary Login Data
        |--------------------------------------------------------------------------
        */

        $request->session()->forget([
            'login_department',
            'department_username',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Redirect Based on Role / Department
        |--------------------------------------------------------------------------
        */

        if ($user->role === 'clerk') {
            return redirect()->route('clerk.dashboard');
        }

        // Default fallback for Admin
        return redirect()->route('admin.dashboard');
    }

    /*
    |--------------------------------------------------------------------------
    | Resident Login
    |--------------------------------------------------------------------------
    */

    public function processResidentLogin(
        Request $request
    ) {
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

        /*
        |--------------------------------------------------------------------------
        | Verify Barangay
        |--------------------------------------------------------------------------
        */

        $barangay = Barangay::query()
            ->where(
                'id',
                $validated['barangay_id']
            )
            ->where(
                'is_active',
                true
            )
            ->first();

        if (!$barangay) {
            return back()
                ->withErrors([
                    'barangay_id' =>
                        'The selected barangay is not available.',
                ])
                ->withInput([
                    'identifier' =>
                        $validated['identifier'],
                ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Find Active Resident
        |--------------------------------------------------------------------------
        */

        $identifier = trim(
            $validated['identifier']
        );

        $resident = Resident::query()
            ->with('account')
            ->where(
                'barangay_id',
                $barangay->id
            )
            ->where(
                'status',
                'active'
            )
            ->where(function ($query) use ($identifier) {
                $query
                    ->where(
                        'resident_number',
                        strtoupper($identifier)
                    )
                    ->orWhere(
                        'mobile',
                        $identifier
                    )
                    ->orWhere(
                        'email',
                        strtolower($identifier)
                    );
            })
            ->first();

        /*
        |--------------------------------------------------------------------------
        | Verify Resident Account + Password
        |--------------------------------------------------------------------------
        */

        if (
            !$resident ||
            !$resident->account ||
            !$resident->account->is_active ||
            !Hash::check(
                $validated['password'],
                $resident->account->password
            )
        ) {
            return back()
                ->withErrors([
                    'login' =>
                        'Invalid resident credentials.',
                ])
                ->withInput([
                    'barangay_id' =>
                        $validated['barangay_id'],

                    'identifier' =>
                        $validated['identifier'],
                ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Authenticate Resident Session
        |--------------------------------------------------------------------------
        */

        $request->session()->regenerate();

        $request->session()->put([
            'resident_id' =>
                $resident->id,

            'resident_barangay_id' =>
                $resident->barangay_id,

            'resident_logged_in' =>
                true,

            'resident_remember' =>
                (bool) ($validated['remember'] ?? false),
        ]);

        /*
        |--------------------------------------------------------------------------
        | Update Last Login
        |--------------------------------------------------------------------------
        */

        $resident->account->update([
            'last_login_at' => now(),
        ]);

        /*
        |--------------------------------------------------------------------------
        | Redirect
        |--------------------------------------------------------------------------
        */

        return redirect()->route(
            'resident.dashboard'
        );
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