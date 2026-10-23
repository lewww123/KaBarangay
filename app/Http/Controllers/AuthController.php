<?php

namespace App\Http\Controllers;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\LoginAttemptRecorder;
use App\Support\LoginIdentifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class AuthController extends Controller
{
    /**
     * Resident-specific limiter: 5 attempts per 600 seconds (Batch E5.3).
     */
    private const RESIDENT_MAX_ATTEMPTS = 5;

    private const RESIDENT_DECAY_SECONDS = 600;

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
            // Unresolved barangay: recorded with a null barangay_id so it
            // stays out of every ordinary barangay admin's view.
            LoginAttemptRecorder::record(
                LoginAttemptRecorder::ACTOR_STAFF,
                LoginAttemptRecorder::CHANNEL_DEPARTMENT,
                LoginAttemptRecorder::STATUS_FAILED,
                identifier: $validated['username'],
                ipAddress: $request->ip(),
            );

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
            $auditBarangayId = $this->sessionBarangayIdForAudit($request);

            LoginAttemptRecorder::record(
                LoginAttemptRecorder::ACTOR_STAFF,
                LoginAttemptRecorder::CHANNEL_DEPARTMENT,
                LoginAttemptRecorder::STATUS_FAILED,
                identifier: $validated['username'],
                barangayId: $auditBarangayId,
                ipAddress: $request->ip(),
            );

            AuditLogger::record(
                'login.failed',
                barangayId: $auditBarangayId,
                ipAddress: $request->ip(),
            );

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

        RateLimiter::clear(
            LoginIdentifier::middlewareKey(
                'login-department',
                LoginIdentifier::identifierKey('department', $validated['username'])
            )
        );

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
            $auditBarangayId = $this->sessionBarangayIdForAudit($request);

            LoginAttemptRecorder::record(
                LoginAttemptRecorder::ACTOR_STAFF,
                LoginAttemptRecorder::CHANNEL_STAFF,
                LoginAttemptRecorder::STATUS_FAILED,
                identifier: $validated['staff_id'],
                barangayId: $auditBarangayId,
                ipAddress: $request->ip(),
            );

            AuditLogger::record(
                'login.failed',
                barangayId: $auditBarangayId,
                ipAddress: $request->ip(),
            );

            return back()
                ->withErrors([
                    'login' => 'Invalid staff credentials.',
                ])
                ->withInput();
        }

        /* Authenticate Staff */
        Auth::login($user);

        LoginAttemptRecorder::record(
            LoginAttemptRecorder::ACTOR_STAFF,
            LoginAttemptRecorder::CHANNEL_STAFF,
            LoginAttemptRecorder::STATUS_SUCCESS,
            identifier: $validated['staff_id'],
            barangayId: $user->barangay_id,
            userId: $user->id,
            ipAddress: $request->ip(),
        );

        AuditLogger::record(
            'login.success',
            barangayId: $user->barangay_id,
            actorId: $user->id,
            ipAddress: $request->ip(),
        );

        RateLimiter::clear(
            LoginIdentifier::middlewareKey(
                'login-staff',
                LoginIdentifier::identifierKey('staff', $validated['staff_id'])
            )
        );

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
            'lupon' => redirect()->route('lupon.dashboard'),
            default => redirect()->route('barangay.select')
                ->with('info', 'No portal is available for your account role.'),
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
            LoginAttemptRecorder::record(
                LoginAttemptRecorder::ACTOR_RESIDENT,
                LoginAttemptRecorder::CHANNEL_RESIDENT,
                LoginAttemptRecorder::STATUS_FAILED,
                identifier: $validated['identifier'],
                barangayId: (int) $validated['barangay_id'],
                ipAddress: $request->ip(),
            );

            AuditLogger::record(
                'login.failed',
                barangayId: (int) $validated['barangay_id'],
                entityType: 'resident_login',
                ipAddress: $request->ip(),
            );

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
            // Resident-specific limiter (Batch E5.3), Option B: a resolved
            // resident is counted regardless of account state or password
            // correctness so the response never reveals whether the account
            // exists or is active. Unresolved residents skip the limiter and
            // rely on the E4 identifier/IP limits.
            if ($resident !== null) {
                $residentKey = LoginIdentifier::residentKey($resident->id);

                $residentLimited = false;

                try {
                    if (RateLimiter::tooManyAttempts($residentKey, self::RESIDENT_MAX_ATTEMPTS)) {
                        $residentLimited = true;
                    } else {
                        RateLimiter::hit($residentKey, self::RESIDENT_DECAY_SECONDS);
                    }
                } catch (Throwable $exception) {
                    report($exception);
                }

                if ($residentLimited) {
                    LoginAttemptRecorder::record(
                        LoginAttemptRecorder::ACTOR_RESIDENT,
                        LoginAttemptRecorder::CHANNEL_RESIDENT,
                        LoginAttemptRecorder::STATUS_RATE_LIMITED,
                        identifier: $validated['identifier'],
                        barangayId: $barangay->id,
                        residentId: $resident->id,
                        ipAddress: $request->ip(),
                    );

                    if ($request->expectsJson()) {
                        return response()->json([
                            'message' => 'Too many login attempts. Please wait before trying again.',
                        ], 429);
                    }

                    return back()
                        ->withErrors([
                            'login' => 'Too many login attempts. Please wait before trying again.',
                        ])
                        ->withInput([
                            'barangay_id' => $validated['barangay_id'],
                            'identifier' => $validated['identifier'],
                        ]);
                }
            }

            LoginAttemptRecorder::record(
                LoginAttemptRecorder::ACTOR_RESIDENT,
                LoginAttemptRecorder::CHANNEL_RESIDENT,
                LoginAttemptRecorder::STATUS_FAILED,
                identifier: $validated['identifier'],
                barangayId: $barangay->id,
                ipAddress: $request->ip(),
            );

            AuditLogger::record(
                'login.failed',
                barangayId: $barangay->id,
                entityType: 'resident_login',
                ipAddress: $request->ip(),
            );

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

        LoginAttemptRecorder::record(
            LoginAttemptRecorder::ACTOR_RESIDENT,
            LoginAttemptRecorder::CHANNEL_RESIDENT,
            LoginAttemptRecorder::STATUS_SUCCESS,
            identifier: $validated['identifier'],
            barangayId: $resident->barangay_id,
            residentId: $resident->id,
            ipAddress: $request->ip(),
        );

        AuditLogger::record(
            'login.success',
            barangayId: $resident->barangay_id,
            entityType: 'resident_login',
            ipAddress: $request->ip(),
        );

        RateLimiter::clear(
            LoginIdentifier::middlewareKey(
                'login-resident',
                LoginIdentifier::identifierKey('resident', $validated['identifier'])
            )
        );

        // Clear only the resident-specific counter for this resolved resident.
        // The raw controller-managed key is used directly (not middlewareKey),
        // and the E4 identifier/IP counters are left untouched.
        try {
            RateLimiter::clear(LoginIdentifier::residentKey($resident->id));
        } catch (Throwable $exception) {
            report($exception);
        }

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

    private function sessionBarangayIdForAudit(Request $request): ?int
    {
        $barangayId = $request->session()->get('selected_barangay_id');

        if (is_string($barangayId) && ctype_digit($barangayId)) {
            $barangayId = (int) $barangayId;
        }

        if (! is_int($barangayId)) {
            return null;
        }

        $isPermitted = Barangay::query()
            ->where('id', $barangayId)
            ->where('is_active', true)
            ->exists();

        return $isPermitted ? $barangayId : null;
    }
}
