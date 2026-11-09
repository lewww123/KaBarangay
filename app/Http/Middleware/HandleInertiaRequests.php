<?php

namespace App\Http\Middleware;

use App\Models\StaffAssignment;
use App\Support\CertificateTemplates;
use App\Support\OfflineDegraded;
use App\Support\OfflineIdentity;
use Illuminate\Database\ConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;
use PDOException;
use Symfony\Component\HttpFoundation\Response;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function onVersionChange(Request $request, Response $response): Response
    {
        if ($response->headers->get(OfflineDegraded::HEADER) === OfflineDegraded::UNAVAILABLE) {
            return $response;
        }

        return parent::onVersionChange($request, $response);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => fn () => config('app.name'),
            'offlineScope' => fn () => OfflineIdentity::scope($request),
            'chatbotStorageKey' => fn () => $request->session()->has('resident_id') ? 'kabarangay_assistant_state:'.hash_hmac('sha256', 'resident:'.$request->session()->get('resident_id'), (string) config('app.key')) : null,
            'auth' => function () use ($request): array {
                $user = $request->user();

                return [
                    'user' => $user,
                    // Display-only: resolved from the session-selected staff assignment
                    // using the same ownership/active constraints as EnsureStaffRole.
                    // Never an authorization source; never a client-supplied barangay.
                    'activeBarangay' => fn () => $this->activeBarangay($request, $user),
                    'staffAssignments' => Inertia::optional(function () use ($user) {
                        try {
                            return $user?->staffAssignments()
                                ->where('is_active', true)
                                ->with('barangay:id,name')
                                ->select(['id', 'user_id', 'barangay_id', 'department', 'position'])
                                ->get()
                                ->map(fn ($assignment) => [
                                    'department' => $assignment->department,
                                    'position' => $assignment->position,
                                    'barangay' => $assignment->barangay?->name,
                                ])
                                ->values() ?? [];
                        } catch (QueryException|ConnectionException|PDOException $exception) {
                            if (! OfflineDegraded::causedByDatabaseLoss($exception)) {
                                throw $exception;
                            }

                            return [];
                        }
                    }),
                ];
            },
            'flash' => fn () => [
                'success' => $request->session()->get('success'),
                'warning' => $request->session()->get('warning'),
                'error' => $request->session()->get('error'),
                'info' => $request->session()->get('info'),
                'attendance_outcome' => $request->session()->get('attendance_outcome'),
            ],
            // Certificate types and whether an approved template is available.
            // Drives the "Coming Soon" state in the clerk certificate form.
            'certificateTypes' => function () use ($request) {
                if (! $request->is('clerk/certificates')) {
                    return [];
                }

                try {
                    return CertificateTemplates::options($request->user()?->barangay_id);
                } catch (QueryException|ConnectionException|PDOException $exception) {
                    if (! OfflineDegraded::causedByDatabaseLoss($exception)) {
                        throw $exception;
                    }

                    return [];
                }
            },
        ];
    }

    /**
     * Display-only barangay for the session-selected staff assignment.
     * Uses the same ownership and active-state constraints as EnsureStaffRole.
     * Returns null when no valid selected assignment can be resolved safely.
     *
     * @return array{id: int, name: string}|null
     */
    private function activeBarangay(Request $request, mixed $user): ?array
    {
        if (! $request->hasSession() || ! $user || ($user->status ?? null) !== 'active') {
            return null;
        }

        $assignmentId = $request->session()->get('staff_assignment_id');
        if (! $assignmentId || $user->barangay_id === null || $user->resident_id === null) {
            return null;
        }

        try {
            $assignment = StaffAssignment::query()
                ->whereKey($assignmentId)
                ->where('is_active', true)
                ->where('user_id', $user->id)
                ->where('barangay_id', $user->barangay_id)
                ->where('resident_id', $user->resident_id)
                ->whereHas('user', fn ($query) => $query
                    ->where('status', 'active')
                    ->where('barangay_id', $user->barangay_id)
                    ->where('resident_id', $user->resident_id))
                ->whereHas('barangay', fn ($barangay) => $barangay->where('is_active', true))
                ->whereHas('resident', fn ($resident) => $resident
                    ->where('barangay_id', $user->barangay_id)
                    ->where('status', 'active')
                    ->whereHas('account', fn ($account) => $account->where('is_active', true)))
                ->with('barangay:id,name')
                ->select(['id', 'user_id', 'barangay_id', 'resident_id'])
                ->first();

            $barangay = $assignment?->barangay;
            if (! $barangay || ! is_string($barangay->name) || $barangay->name === '') {
                return null;
            }

            return ['id' => (int) $barangay->id, 'name' => $barangay->name];
        } catch (QueryException|ConnectionException|PDOException $exception) {
            if (! OfflineDegraded::causedByDatabaseLoss($exception)) {
                throw $exception;
            }

            return null;
        }
    }
}
