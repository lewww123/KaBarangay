<?php

namespace App\Http\Middleware;

use App\Support\CertificateTemplates;
use App\Support\OfflineIdentity;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

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
                    'staffAssignments' => Inertia::optional(fn () => $user?->staffAssignments()
                        ->where('is_active', true)
                        ->with('barangay:id,name')
                        ->select(['id', 'user_id', 'barangay_id', 'department', 'position'])
                        ->get()
                        ->map(fn ($assignment) => [
                            'department' => $assignment->department,
                            'position' => $assignment->position,
                            'barangay' => $assignment->barangay?->name,
                        ])
                        ->values() ?? []),
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
            'certificateTypes' => fn () => CertificateTemplates::options(),
        ];
    }
}
