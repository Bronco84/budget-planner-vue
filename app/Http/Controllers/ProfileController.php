<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => session('status'),
            'passkeys' => $user->webAuthnCredentials()
                ->orderBy('updated_at', 'desc')
                ->get()
                ->map(fn($credential) => [
                    'id' => $credential->id,
                    'name' => $credential->alias ?? 'Passkey',
                    'created_at' => $credential->created_at,
                    'last_used' => $credential->updated_at,
                ]),
            'trustedDevices' => $user->trustedDevices()
                ->orderBy('last_used_at', 'desc')
                ->get()
                ->map(fn($device) => [
                    'id' => $device->id,
                    'device_name' => $device->device_name,
                    'browser' => $device->browser,
                    'platform' => $device->platform,
                    'ip_address' => $device->ip_address,
                    'auth_method' => $device->auth_method,
                    'last_used_at' => $device->last_used_at,
                    'created_at' => $device->created_at,
                ]),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
