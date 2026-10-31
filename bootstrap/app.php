<?php

use App\Http\Middleware\EnsureStaffRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResidentAuth;
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
            $department = $request->user()?->staffAssignments()
                ->where('is_active', true)
                ->orderBy('id')
                ->value('department');

            return match ($department) {
                'admin' => route('admin.dashboard'),
                'clerk' => route('clerk.dashboard'),
                'lupon' => route('lupon.dashboard'),
                'pwd' => route('pwd.dashboard'),
                'senior' => route('senior.dashboard'),
                default => route('login'),
            };
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
