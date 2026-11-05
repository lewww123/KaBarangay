<?php

namespace App\Http\Controllers;

use App\Models\Barangay;
use App\Models\Resident;
use App\Support\AuditLogger;
use App\Support\LoginAttemptRecorder;
use App\Support\LoginIdentifier;
use App\Support\ResidentContext;
use Inertia\Inertia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
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
    | Resident Login
    |--------------------------------------------------------------------------
    */

    public function showResidentLogin()
    {
        return ResidentContext::find()
            ? redirect()->route('resident.dashboard')
            : Inertia::render('Resident/Login');
    }

    public function processResidentLogin(Request $request)
    {
        if (ResidentContext::find()) {
            return redirect()->route('resident.dashboard');
        }

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
            ->where(function ($query) {
                $query->whereHas('registration', fn ($registration) => $registration
                    ->where('status', 'approved')
                    ->whereColumn('resident_registrations.barangay_id', 'residents.barangay_id'))
                    ->orWhereHas('matchedRegistrations', fn ($registration) => $registration
                        ->where('status', 'approved')
                        ->whereColumn('resident_registrations.barangay_id', 'residents.barangay_id'));
            })
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
        $barangays = $this->rememberReference('auth:resident-barangay-options', 300, fn () => Barangay::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'code',
                'city_municipality',
                'province',
            ])->toArray());

        return response()->json([
            'barangays' => $barangays,
        ]);
    }
}
