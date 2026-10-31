<?php

namespace App\Http\Controllers\WebAuthn;

use App\Services\DeviceTokenService;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Laragear\WebAuthn\Http\Requests\AttestationRequest;
use Laragear\WebAuthn\Http\Requests\AttestedRequest;

use function response;

class WebAuthnRegisterController
{
    protected $deviceTokenService;

    public function __construct(DeviceTokenService $deviceTokenService)
    {
        $this->deviceTokenService = $deviceTokenService;
    }

    /**
     * Returns a challenge to be verified by the user device.
     */
    public function options(AttestationRequest $request): Responsable
    {
        // Ensure user is authenticated
        if (! Auth::check()) {
            abort(401, 'You must be logged in to register a passkey.');
        }

        return $request
            ->fastRegistration()
            ->toCreate();
    }

    /**
     * Registers a device for further WebAuthn authentication.
     */
    public function register(AttestedRequest $request): Response|JsonResponse
    {
        // The optional name typed on the registration page. Blank leaves the
        // alias to WebAuthnCredentialObserver, which names it from the device.
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            // Ensure user is authenticated
            if (! Auth::check()) {
                \Log::error('Passkey registration attempted without authentication');
                abort(401, 'You must be logged in to register a passkey.');
            }

            $user = Auth::user();
            \Log::info('Starting passkey registration', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);

            // Save the WebAuthn credential
            // forceFill: alias is not fillable on Laragear's model.
            $credential = $request->save(array_filter(['alias' => $validated['name'] ?? null]));

            \Log::info('Passkey credential saved', [
                'user_id' => $user->id,
                'credential_id' => $credential?->id ?? 'unknown',
            ]);

            // Refresh the user to ensure the relationship is loaded
            $user->refresh();

            // Clear any cached relationship data
            $user->unsetRelation('webAuthnCredentials');

            // Verify the passkey was actually saved
            $passkeyCount = $user->webAuthnCredentials()->count();
            \Log::info('User now has passkeys', [
                'user_id' => $user->id,
                'passkey_count' => $passkeyCount,
            ]);

            // Create trusted device token so user stays logged in
            $device = $this->deviceTokenService->createTrustedDevice($user, $request, 'passkey');
            Cookie::queue($this->deviceTokenService->createCookie($device));

            \Log::info('Passkey registered and trusted device created successfully', [
                'user_id' => $user->id,
                'device_id' => $device->id,
                'passkey_count' => $passkeyCount,
            ]);

            return response()->noContent();
        } catch (\Exception $e) {
            \Log::error('Passkey registration failed', [
                'user_id' => Auth::id(),
                'exception' => $e,
            ]);

            return response()->json([
                'message' => 'Failed to register passkey. Please try again.',
            ], 500);
        }
    }
}
