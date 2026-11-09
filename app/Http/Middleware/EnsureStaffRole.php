<?php

namespace App\Http\Middleware;

use App\Models\StaffAssignment;
use App\Support\OfflineDegraded;
use Closure;
use Illuminate\Database\ConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PDOException;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaffRole
{
    /**
     * Ensure the authenticated staff account is active and holds the exact
     * selected assignment for the route.
     */
    public function handle(Request $request, Closure $next, string ...$allowedRoles): Response
    {
        $user = $request->user();

        if (! $user) {
            return $this->unauthenticated($request);
        }

        // Automatic session invalidation if user account is inactive
        if ($user->status !== 'active') {
            Auth::logout();
            $request->session()->invalidate();

            return $this->unauthenticated($request);
        }

        // Assignment-scoped validation. Null identity columns cannot map to an
        // assignment, so fail closed before querying.
        $assignmentId = $request->session()->get('staff_assignment_id');
        if (! $assignmentId || $user->barangay_id === null || $user->resident_id === null) {
            Auth::logout();
            $request->session()->invalidate();

            return $this->unauthenticated($request);
        }

        try {
            $assignment = StaffAssignment::query()
                ->whereKey($assignmentId)
                ->where('is_active', true)
                ->where('user_id', $user->id)
                ->where('barangay_id', $user->barangay_id)
                ->where('resident_id', $user->resident_id)
                // Strict parity with User::hasActiveAssignment(): re-validate the
                // user row so a cached model cannot outlive a bulk deactivation,
                // and fail closed on missing barangay/resident/account relations.
                ->whereHas('user', fn ($query) => $query
                    ->where('status', 'active')
                    ->where('barangay_id', $user->barangay_id)
                    ->where('resident_id', $user->resident_id))
                ->whereHas('barangay', fn ($barangay) => $barangay->where('is_active', true))
                ->whereHas('resident', fn ($resident) => $resident
                    ->where('barangay_id', $user->barangay_id)
                    ->where('status', 'active')
                    ->whereHas('account', fn ($account) => $account->where('is_active', true)))
                ->select(['id', 'department'])
                ->first();
        } catch (QueryException|ConnectionException|PDOException $exception) {
            if (! OfflineDegraded::causedByDatabaseLoss($exception)) {
                throw $exception;
            }

            // The account database is unavailable, so this assignment cannot be verified.
            // Keep the session intact and degrade instead of falsely denying or logging out.
            return OfflineDegraded::respond($request, $exception);
        }

        if (! $assignment) {
            Auth::logout();
            $request->session()->invalidate();

            return $this->unauthenticated($request);
        }

        // Authorization based strictly on the selected assignment's department
        $department = $assignment->department;
        $authorized = in_array($department, $allowedRoles, true) ||
            ($department === 'senior' && in_array('senior_staff', $allowedRoles, true)) ||
            ($department === 'pwd' && in_array('pwd_staff', $allowedRoles, true));

        if (! $authorized) {
            return $this->accessDenied($request);
        }

        return $next($request);
    }

    private function unauthenticated(Request $request): Response
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => 'Unauthenticated.'], 401)
                ->header('Cache-Control', 'private, no-store, max-age=0');
        }

        return redirect()->route('login');
    }

    private function accessDenied(Request $request): Response
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => 'Access denied.'], 403)
                ->header('Cache-Control', 'private, no-store, max-age=0');
        }

        if (view()->exists('errors.403')) {
            return response()->view('errors.403', [], 403)
                ->header('Cache-Control', 'private, no-store, max-age=0');
        }

        abort(403, 'Access denied.');
    }
}
