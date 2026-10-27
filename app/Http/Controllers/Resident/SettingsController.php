<?php

namespace App\Http\Controllers\Resident;

use App\Http\Controllers\Controller;
use App\Models\Resident;
use App\Models\ResidentAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    public function updatePassword(Request $request): RedirectResponse
    {
        $resident = Resident::query()
            ->with('account')
            ->where('id', session('resident_id'))
            ->where('status', 'active')
            ->firstOrFail();

        $account = $resident->account;

        if (! $account instanceof ResidentAccount || ! $account->is_active) {
            abort(403);
        }

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! Hash::check($validated['current_password'], $account->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'The current password is incorrect.',
            ]);
        }

        $account->forceFill([
            'password' => Hash::make($validated['password']),
            'password_changed_at' => now(),
            'remember_token' => null,
        ])->save();

        DB::table('resident_password_reset_tokens')
            ->where('resident_id', $resident->id)
            ->delete();

        return back()->with('success', 'Your password has been updated.');
    }
}
