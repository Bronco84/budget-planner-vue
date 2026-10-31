<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Services\DeviceFingerprintService;
use App\Services\DeviceTokenService;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request, DeviceFingerprintService $fingerprintService): Response
    {
        $user = $request->user();
        $currentFingerprint = $fingerprintService->generate($request);

        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => session('status'),
            'passkeys' => $user->webAuthnCredentials()
                ->orderBy('updated_at', 'desc')
                ->get()
                ->map(fn ($credential) => [
                    'id' => $credential->id,
                    'name' => $credential->alias ?? 'Passkey',
                    'created_at' => $credential->created_at,
                    'last_used' => $credential->updated_at,
                ]),
            'trustedDevices' => $user->trustedDevices()
                ->valid()
                ->orderBy('last_used_at', 'desc')
                ->get()
                ->map(fn ($device) => [
                    'id' => $device->id,
                    'device_name' => $device->device_name,
                    'browser' => $device->browser,
                    'platform' => $device->platform,
                    'ip_address' => $device->ip_address,
                    'auth_method' => $device->auth_method,
                    'last_used_at' => $device->last_used_at,
                    'created_at' => $device->created_at,
                    'is_current' => hash_equals($device->device_fingerprint, $currentFingerprint),
                ]),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        $emailChanged = $request->user()->isDirty('email');

        if ($emailChanged) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        if ($emailChanged) {
            $request->user()->sendEmailVerificationNotification();
        }

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        $request->validate([
            'email' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($user) {
                if (mb_strtolower(trim($value)) !== mb_strtolower($user->email)) {
                    $fail('The email does not match your account.');
                }
            }],
        ]);

        if (! $this->authenticatedRecently($request)) {
            throw ValidationException::withMessages([
                'email' => 'For your security, sign in again before deleting your account.',
            ]);
        }

        Auth::logout();

        // Trusted devices, magic-link tokens, budgets and preferences cascade via
        // foreign keys; WebAuthn credentials are polymorphic so are removed here.
        DB::transaction(function () use ($user) {
            $user->webAuthnCredentials()->delete();
            $user->delete();
        });

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // The trusted device rows are gone, so drop the now-useless device cookie too.
        return Redirect::to('/')->withCookie(app(DeviceTokenService::class)->forgetCookie());
    }

    /**
     * Whether this session signed in within the account-deletion window.
     */
    private function authenticatedRecently(Request $request): bool
    {
        $authenticatedAt = $request->session()->get('auth.authenticated_at');

        if (! is_int($authenticatedAt)) {
            return false;
        }

        $windowSeconds = (int) config('auth.delete_account_reauth_minutes', 15) * 60;

        return now()->getTimestamp() - $authenticatedAt <= $windowSeconds;
    }
}
