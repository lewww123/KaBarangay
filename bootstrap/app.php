<?php

use App\Http\Middleware\EnsureStaffRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResidentAuth;
use App\Http\Middleware\SecurityHeaders;
use App\Support\OfflineDegraded;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {

        $middleware->redirectUsersTo(function (Request $request): string {
            $user = $request->user();
            $destinations = [
                'admin' => 'admin.dashboard',
                'clerk' => 'clerk.dashboard',
                'lupon' => 'lupon.dashboard',
                'pwd' => 'pwd.dashboard',
                'senior' => 'senior.dashboard',
            ];
            $selectedId = $request->session()->get('staff_assignment_id');
            if ($selectedId) {
                $assignment = $user?->staffAssignments()->where('is_active', true)->find($selectedId);
                if ($assignment && isset($destinations[$assignment->department])) {
                    return route($destinations[$assignment->department]);
                }
            }
            $assignment = $user?->staffAssignments()->where('is_active', true)
                ->where('barangay_id', $user->barangay_id)
                ->whereIn('department', array_keys($destinations))
                ->first();
            if ($assignment && isset($destinations[$assignment->department])) {
                $request->session()->put('staff_assignment_id', $assignment->id);

                return route($destinations[$assignment->department]);
            }
            // Keep the valid session, but never redirect an unauthorized account in a loop.
            abort(403, 'No active authorized staff assignment is available for this account.');
        });

        // Trust Cloudflare Tunnel / reverse proxy.
        // Needed so Laravel recognizes the original HTTPS request.
        $middleware->trustProxies(at: '*');

        // Register custom middleware aliases.
        $middleware->alias([
            'resident.auth' => ResidentAuth::class,
            'role' => EnsureStaffRole::class,
        ]);

        $middleware->web(
            append: [
                SecurityHeaders::class,
                HandleInertiaRequests::class,
                AddLinkHeadersForPreloadedAssets::class,
            ],
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! OfflineDegraded::causedByDatabaseLoss($exception)) {
                return null;
            }

            return OfflineDegraded::respond($request, $exception);
        });
    })
    ->create();
