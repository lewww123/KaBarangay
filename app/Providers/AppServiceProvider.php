<?php

namespace App\Providers;

use App\Support\LoginAttemptRecorder;
use App\Support\LoginIdentifier;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiters();
    }

    /**
     * Register the named limiters for the three login POST routes.
     *
     * Each limiter applies an identifier limit (5 per 10 minutes) and a
     * shared per-IP limit (20 per 10 minutes). The first limit that is
     * exceeded drives the rejection, so its response callback records the
     * rate-limited attempt before the controller runs.
     */
    protected function configureRateLimiters(): void
    {
        RateLimiter::for('login-department', fn (Request $request) => $this->loginLimits(
            $request,
            'department',
            LoginAttemptRecorder::ACTOR_STAFF,
            LoginAttemptRecorder::CHANNEL_DEPARTMENT,
            fn (Request $r) => $r->input('username'),
            fn (Request $r) => LoginIdentifier::barangayIdFromSession($r),
        ));

        RateLimiter::for('login-staff', fn (Request $request) => $this->loginLimits(
            $request,
            'staff',
            LoginAttemptRecorder::ACTOR_STAFF,
            LoginAttemptRecorder::CHANNEL_STAFF,
            fn (Request $r) => $r->input('staff_id'),
            fn (Request $r) => LoginIdentifier::barangayIdFromSession($r),
        ));

        RateLimiter::for('login-resident', fn (Request $request) => $this->loginLimits(
            $request,
            'resident',
            LoginAttemptRecorder::ACTOR_RESIDENT,
            LoginAttemptRecorder::CHANNEL_RESIDENT,
            fn (Request $r) => $r->input('identifier'),
            fn (Request $r) => null,
        ));
    }

    /**
     * Build the identifier and per-IP limits for one login channel.
     *
     * Empty/whitespace identifiers skip the identifier limit entirely so a
     * shared empty bucket is never created; the per-IP limit remains the
     * backstop, and the recorder still stores a null identifier_hash.
     *
     * @param  callable(Request): ?string  $identifier
     * @param  callable(Request): ?int  $barangayId
     * @return array<int, Limit>
     */
    protected function loginLimits(
        Request $request,
        string $channel,
        string $actorType,
        string $channelName,
        callable $identifier,
        callable $barangayId,
    ): array {
        $record = function (Request $request, array $headers) use ($actorType, $channelName, $identifier, $barangayId): RedirectResponse|JsonResponse {
            LoginAttemptRecorder::record(
                $actorType,
                $channelName,
                LoginAttemptRecorder::STATUS_RATE_LIMITED,
                identifier: $identifier($request),
                barangayId: $barangayId($request),
                ipAddress: LoginIdentifier::clientIp($request),
            );

            $message = 'Too many login attempts. Please wait before trying again.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 429);
            }

            return redirect()
                ->back()
                ->withErrors(['login' => $message])
                ->withInput();
        };

        $identifierAfter = $identifier($request);
        $limits = [];

        if (LoginIdentifier::fingerprint($identifierAfter) !== null) {
            $limits[] = Limit::perMinutes(10, 5)
                ->by(LoginIdentifier::identifierKey($channel, $identifierAfter))
                ->response($record);
        }

        $limits[] = Limit::perMinutes(10, 20)
            ->by(LoginIdentifier::ipKey($channel, LoginIdentifier::clientIp($request)))
            ->response($record);

        return $limits;
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
