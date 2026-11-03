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

        $fullName = trim($validated['full_name']);
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
                ->where('name', $fullName)
                ->whereColumn('users.resident_id', 'staff_assignments.resident_id')
                ->where('status', 'active'))
            ->get()
            ->first(fn (StaffAssignment $assignment) => $assignment->resident_id === $assignment->user?->resident_id
                && $assignment->resident?->full_name === $fullName
                && $assignment->user?->name === $fullName);

        $resident = $assignment?->resident;
        if ($assignment === null || $resident?->account === null || ! $resident->account->is_active || ! Hash::check($validated['password'], $resident->account->password)) {
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
