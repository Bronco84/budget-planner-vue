<?php

namespace App\Http\Controllers\WebAuthn;

use App\Services\DeviceTokenService;
use App\Services\PasskeyRegistrationOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Support\WebAuthn;
use Throwable;
use Webauthn\PublicKeyCredentialCreationOptions;

use function response;

class WebAuthnRegisterController
{
    use ReadsPublicKeyCredential;

    private const SESSION_KEY = 'passkey.registration_options';

    protected $deviceTokenService;

    public function __construct(DeviceTokenService $deviceTokenService)
    {
        $this->deviceTokenService = $deviceTokenService;
    }

    /**
     * Returns a challenge to be verified by the user device.
     *
     * Login is usernameless (no email lookup), so every passkey must be a
     * discoverable credential the authenticator can offer on its own.
     */
    public function options(Request $request, PasskeyRegistrationOptions $generate): JsonResponse
    {
        // Ensure user is authenticated
        if (! Auth::check()) {
            abort(401, 'You must be logged in to register a passkey.');
        }

        $options = $generate($request->user());

        $request->session()->put(self::SESSION_KEY, WebAuthn::toJson($options));

        return response()->json(WebAuthn::toBrowserArray($options));
    }

    /**
     * Registers a device for further WebAuthn authentication.
     */
    public function register(Request $request, StorePasskey $store): Response|JsonResponse
    {
        // Ensure user is authenticated
        if (! Auth::check()) {
            \Log::error('Passkey registration attempted without authentication');
            abort(401, 'You must be logged in to register a passkey.');
        }

        // The optional name typed on the registration page. Blank leaves the
        // name to PasskeyObserver, which names it from the device.
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $credential = $this->publicKeyCredential($request, 'attestation');
        $options = $this->pullCeremonyOptions($request, self::SESSION_KEY, PublicKeyCredentialCreationOptions::class, 'attestation');
        $user = Auth::user();

        try {
            $passkey = $store($user, $validated['name'] ?? '', $credential, $options);
        } catch (InvalidPasskeyException) {
            throw ValidationException::withMessages(['attestation' => 'Unable to register this passkey.']);
        } catch (Throwable $e) {
            if ($this->isCeremonyFailure($e)) {
                throw ValidationException::withMessages(['attestation' => 'The passkey could not be verified.']);
            }

            \Log::error('Passkey registration failed', [
                'user_id' => $user->id,
                'exception' => $e,
            ]);

            return response()->json([
                'message' => 'Failed to register passkey. Please try again.',
            ], 500);
        }

        // Create trusted device token so user stays logged in
        $device = $this->deviceTokenService->createTrustedDevice($user, $request, 'passkey');
        Cookie::queue($this->deviceTokenService->createCookie($device));

        \Log::info('Passkey registered and trusted device created', [
            'user_id' => $user->id,
            'passkey_id' => $passkey->id,
            'device_id' => $device->id,
        ]);

        return response()->noContent();
    }
}
