<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Barangay;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        $barangayId = $request->session()->get('selected_barangay_id');

        if (!$barangayId) {
            return redirect()->route('barangay.select');
        }

        $barangay = Barangay::query()
            ->where('id', $barangayId)
            ->where('is_active', true)
            ->first();

        if (!$barangay) {
            $request->session()->forget('selected_barangay_id');

            return redirect()->route('barangay.select');
        }

        return Inertia::render('Auth/Login', [
            'barangay' => [
                'id' => $barangay->id,
                'name' => $barangay->name,
                'code' => $barangay->code,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
            'remember' => ['boolean'],
        ]);

        $barangayId = $request->session()->get('selected_barangay_id');

        if (!$barangayId) {
            return redirect()->route('barangay.select');
        }

        $credentials = [
            'password' => $validated['password'],
            'barangay_id' => $barangayId,
            'status' => 'active',
        ];

        if (filter_var($validated['login'], FILTER_VALIDATE_EMAIL)) {
            $credentials['email'] = $validated['login'];
        } else {
            $credentials['staff_id'] = $validated['login'];
        }

        if (!Auth::attempt(
            $credentials,
            $validated['remember'] ?? false
        )) {
            throw ValidationException::withMessages([
                'login' => 'The credentials you entered are incorrect.',
            ]);
        }

        $request->session()->regenerate();

        $request->session()->forget('selected_barangay_id');

        return redirect()->intended(route('admin.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('barangay.select');
    }
}