<?php

namespace App\Http\Controllers\WebAuthn;

use App\Services\DeviceTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Passkeys;
use Laravel\Passkeys\Support\WebAuthn;
use Throwable;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredentialRequestOptions;

use function response;

class WebAuthnLoginController
{
    use ReadsPublicKeyCredential;

    private const SESSION_KEY = 'passkey.verification_options';

    protected $deviceTokenService;

    public function __construct(DeviceTokenService $deviceTokenService)
    {
        $this->deviceTokenService = $deviceTokenService;
    }

    /**
     * Returns the challenge to assertion.
     *
     * Login is usernameless: the challenge is the same for everyone and carries
     * no allowCredentials, so the authenticator offers its discoverable
     * passkeys. Any email in the request is ignored; looking it up would tell a
     * guest whether that address has an account.
     */
    public function options(Request $request): JsonResponse
    {
        $options = PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: Passkeys::relyingPartyId(),
            allowCredentials: [],
            // As under laragear: verify the user where the authenticator can.
            userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            timeout: Passkeys::timeout(),
        );

        $request->session()->put(self::SESSION_KEY, WebAuthn::toJson($options));

        return response()->json(WebAuthn::toBrowserArray($options));
    }

    /**
     * Log the user in.
     */
    public function login(Request $request, VerifyPasskey $verify): Response|JsonResponse
    {
        $credential = $this->publicKeyCredential($request, 'assertion');
        $options = $this->pullCeremonyOptions($request, self::SESSION_KEY, PublicKeyCredentialRequestOptions::class, 'assertion');

        try {
            $passkey = $verify($credential, $options);
        } catch (InvalidPasskeyException) {
            throw ValidationException::withMessages(['assertion' => 'Passkey not recognized. It may have been removed from your account.']);
        } catch (Throwable $e) {
            if (! $this->isCeremonyFailure($e)) {
                throw $e;
            }

            throw ValidationException::withMessages(['assertion' => 'The passkey could not be verified.']);
        }

        Auth::login($passkey->user);
        $request->session()->regenerate();

        // Create or update trusted device and set cookie
        $device = $this->deviceTokenService->createTrustedDevice($passkey->user, $request);
        Cookie::queue($this->deviceTokenService->createCookie($device));

        return response()->noContent(204);
    }
}
