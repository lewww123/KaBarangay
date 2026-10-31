<?php

namespace App\Http\Controllers\Resident;

use App\Http\Controllers\Controller;
use App\Support\RegistrationEmailOtp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RegistrationOtpController extends Controller
{
    public function send(Request $request, RegistrationEmailOtp $otp): JsonResponse
    {
        $this->normalizeEmail($request);
        $input = $request->validate(['email' => ['required', 'string', 'email:rfc', 'max:255']]);

        return response()->json([
            'message' => 'OTP sent. Check your inbox or spam folder. The code expires in 10 minutes.',
            ...$otp->send($request, $input['email']),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function verify(Request $request, RegistrationEmailOtp $otp): JsonResponse
    {
        $this->normalizeEmail($request);
        $input = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/D'],
        ]);

        return response()->json([
            'message' => 'Email verified successfully. You can continue with registration.',
            ...$otp->verify($request, $input['email'], $input['code']),
        ])->header('Cache-Control', 'private, no-store');
    }

    private function normalizeEmail(Request $request): void
    {
        $email = $request->input('email');
        if (is_string($email)) {
            $request->merge(['email' => Str::lower(trim($email))]);
        }
    }
}
