<?php

namespace App\Http\Controllers\WebAuthn;

use App\Services\DeviceTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cookie;
use Laragear\WebAuthn\Http\Requests\AssertedRequest;
use Laragear\WebAuthn\Http\Requests\AssertionRequest;

use function response;

class WebAuthnLoginController
{
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
    public function options(AssertionRequest $request): JsonResponse
    {
        $response = $request->toVerify(null)->toResponse($request);

        return response()->json(json_decode($response->getContent(), true));
    }

    /**
     * Log the user in.
     */
    public function login(AssertedRequest $request): Response
    {
        $success = $request->login();

        if ($success) {
            // Create or update trusted device and set cookie
            $user = $request->user();
            $device = $this->deviceTokenService->createTrustedDevice($user, $request);
            Cookie::queue($this->deviceTokenService->createCookie($device));

            return response()->noContent(204);
        }

        return response()->noContent(422);
    }
}
