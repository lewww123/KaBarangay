<?php

namespace App\Jobs;

use App\Mail\ResidentPasswordResetMail;
use App\Models\Resident;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class SendResidentPasswordReset implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $residentId, public string $email) {}

    public function handle(): void
    {
        $resident = Resident::query()
            ->with('account')
            ->whereKey($this->residentId)
            ->where('email', $this->email)
            ->where('status', 'active')
            ->whereHas('account', fn ($query) => $query->where('is_active', true))
            ->first();

        if (! $resident) {
            return;
        }

        $email = $this->email;
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
}
