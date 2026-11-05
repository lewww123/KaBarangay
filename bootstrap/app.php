<?php

use App\Http\Middleware\EnsureStaffRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResidentAuth;
use App\Http\Middleware\SecurityHeaders;
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
            $departments = $user?->staffAssignments()->where('is_active', true)
                ->orderBy('id')->pluck('department')->unique() ?? [];
            foreach ($departments as $department) {
                if (isset($destinations[$department]) && $user->hasActiveAssignment([$department])) {
                    return route($destinations[$department]);
                }
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
    })
    ->create();
