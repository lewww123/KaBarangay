<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Barangay;
use App\Models\StaffAssignment;
use App\Support\AuditLogger;
use App\Support\LoginAttemptRecorder;
use App\Support\LoginIdentifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StaffLoginController extends Controller
{
    public function showLoginForm(): Response
    {
        return Inertia::render('Auth/StaffLogin', [
            'barangays' => Barangay::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'barangay_id' => ['required', Rule::exists('barangays', 'id')->where('is_active', true)],
            'department' => ['required', Rule::in(['lupon', 'clerk', 'admin', 'pwd', 'senior'])],
            'position' => ['required', 'string', 'max:100'],
            'full_name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        // Normalization helper: trim, collapse whitespace, lowercase
        $normalize = fn (string $value): string => mb_strtolower(preg_replace('/\s+/', ' ', trim($value)));
        $normalizedFullName = $normalize($validated['full_name']);
        // Preserve raw name for logging / error messages
        $fullName = $validated['full_name'];

        $assignment = StaffAssignment::query()
            ->with(['resident.account', 'user'])
            ->where('barangay_id', $validated['barangay_id'])
            ->where('department', $validated['department'])
            ->where('position', $validated['position'])
            ->where('is_active', true)
            ->whereHas('resident', fn ($query) => $query
                ->where('barangay_id', $validated['barangay_id'])
                ->where('status', 'active'))
            ->whereHas('user', fn ($query) => $query
                ->where('barangay_id', $validated['barangay_id'])
                ->whereColumn('users.resident_id', 'staff_assignments.resident_id')
                ->where('status', 'active'))
            ->get()
            ->first(function (StaffAssignment $assignment) use ($normalize, $normalizedFullName): bool {
                if ($assignment->resident_id !== $assignment->user?->resident_id) {
                    return false;
                }

                if ($normalize($assignment->resident?->full_name ?? '') !== $normalizedFullName) {
                    return false;
                }

                $userName = $normalize($assignment->user?->name ?? '');
                $legacyName = $normalize(trim(($assignment->resident?->first_name ?? '').' '.($assignment->resident?->last_name ?? '')));

                return $userName === $normalizedFullName || ($legacyName !== '' && $userName === $legacyName);
            });

        $resident = $assignment?->resident;
        if (
            $assignment === null ||
            $resident?->account === null ||
            ! $resident->account->is_active ||
            ! Hash::check($validated['password'], $resident->account->password)
        ) {
            LoginAttemptRecorder::record(
                LoginAttemptRecorder::ACTOR_STAFF,
                LoginAttemptRecorder::CHANNEL_STAFF,
                LoginAttemptRecorder::STATUS_FAILED,
                identifier: $fullName,
                barangayId: (int) $validated['barangay_id'],
                ipAddress: $request->ip(),
            );
            AuditLogger::record('login.failed', (int) $validated['barangay_id'], ipAddress: $request->ip());

            throw ValidationException::withMessages(['password' => 'Invalid resident account credentials.']);
        }

        Auth::login($assignment->user);
$request->session()->put('staff_assignment_id', $assignment->id);
        $request->session()->regenerate();
        RateLimiter::clear(LoginIdentifier::middlewareKey(
            'login-staff',
            LoginIdentifier::identifierKey('staff', $fullName),
        ));
        LoginAttemptRecorder::record(
            LoginAttemptRecorder::ACTOR_STAFF,
            LoginAttemptRecorder::CHANNEL_STAFF,
            LoginAttemptRecorder::STATUS_SUCCESS,
            identifier: $fullName,
            barangayId: $assignment->barangay_id,
            userId: $assignment->user_id,
            residentId: $assignment->resident_id,
            ipAddress: $request->ip(),
        );
        AuditLogger::record('login.success', $assignment->barangay_id, $assignment->user_id, ipAddress: $request->ip());

        return redirect()->route($assignment->department.'.dashboard');
    }
}
