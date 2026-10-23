<?php

namespace App\Http\Controllers\Resident;

use App\Http\Controllers\Controller;
use App\Mail\ResidentPasswordResetMail;
use App\Models\Resident;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PasswordResetController extends Controller
{
    /**
     * How long a reset link stays valid.
     */
    private const TOKEN_TTL_MINUTES = 60;

    public function showForgotForm(): Response
    {
        return Inertia::render('Resident/ForgotPassword');
    }

    public function sendResetLink(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $email = strtolower(trim($validated['email']));

        // Email addresses are unique per barangay, not globally, so more than
        // one active account can share an address. Issue a distinct, single-use
        // token to each and email every match rather than guessing one.
        $residents = Resident::query()
            ->with('account')
            ->where('email', $email)
            ->where('status', 'active')
            ->whereHas('account', fn ($query) => $query->where('is_active', true))
            ->get();

        foreach ($residents as $resident) {
            if (! $resident->account) {
                continue;
            }

            $token = Str::random(64);
            $hashedToken = Hash::make($token);

            DB::table('resident_password_reset_tokens')
                ->where('resident_id', $resident->id)
                ->delete();

            DB::table('resident_password_reset_tokens')->insert([
                'resident_id' => $resident->id,
                'email' => $email,
                'token' => $hashedToken,
                'created_at' => now(),
            ]);

            try {
                Mail::to($email)->send(new ResidentPasswordResetMail(
                    residentName: $resident->full_name,
                    resetUrl: route('resident.password.reset', [
                        'token' => $token,
                        'email' => $email,
                    ]),
                ));
            } catch (Throwable $exception) {
                // The link never left the application, so remove the token
                // rather than leaving a usable-looking reset behind. Only this
                // exact token is removed so a concurrent request is untouched.
                DB::table('resident_password_reset_tokens')
                    ->where('resident_id', $resident->id)
                    ->where('token', $hashedToken)
                    ->delete();

                // Log only the exception class, never its message: transport
                // failures can echo the message body, which would write the
                // plaintext reset URL/token into the application log.
                Log::warning('Failed to send resident password reset email.', [
                    'resident_id' => $resident->id,
                    'exception' => $exception::class,
                ]);
            }
        }

        // Always return the same response so the endpoint cannot be used to
        // discover which email addresses belong to active resident accounts.
        return redirect()
            ->route('resident.forgot-password')
            ->with(
                'success',
                'If an active account uses that email address, a password reset link has been sent.'
            );
    }

    public function showResetForm(Request $request, string $token): Response
    {
        return Inertia::render('Resident/ResetPassword', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $email = strtolower(trim($validated['email']));

        $invalid = fn () => back()->withErrors([
            'email' => 'This password reset link is invalid or has expired.',
        ]);

        $result = DB::transaction(function () use ($validated, $email) {
            $records = DB::table('resident_password_reset_tokens')
                ->where('email', $email)
                ->lockForUpdate()
                ->get();

            // Match against the hashed token so the correct account is selected
            // even when several accounts share the same email address.
            $record = null;

            foreach ($records as $candidate) {
                if (Hash::check($validated['token'], $candidate->token)) {
                    $record = $candidate;
                    break;
                }
            }

            if (! $record) {
                return 'invalid';
            }

            if (Carbon::parse($record->created_at)->diffInMinutes(now()) > self::TOKEN_TTL_MINUTES) {
                DB::table('resident_password_reset_tokens')->where('id', $record->id)->delete();

                return 'invalid';
            }

            $resident = Resident::query()
                ->with('account')
                ->where('id', $record->resident_id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if (! $resident || ! $resident->account) {
                DB::table('resident_password_reset_tokens')->where('id', $record->id)->delete();

                return 'invalid';
            }

            // Consume the token atomically: only the request whose delete
            // affects the row is allowed to change the password, so a token can
            // never be used twice even under concurrent requests.
            $consumed = DB::table('resident_password_reset_tokens')
                ->where('id', $record->id)
                ->delete();

            if ($consumed !== 1) {
                return 'invalid';
            }

            $resident->account->forceFill([
                'password' => Hash::make($validated['password']),
                'password_changed_at' => now(),
                'remember_token' => null,
            ])->save();

            return 'ok';
        });

        if ($result !== 'ok') {
            return $invalid();
        }

        return redirect()
            ->route('resident.login')
            ->with('success', 'Your password has been reset. You can now log in.');
    }
}
