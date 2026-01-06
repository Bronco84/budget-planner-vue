<?php

namespace App\Http\Controllers\WebAuthn;

use App\Services\DeviceTokenService;
use Illuminate\Contracts\Support\Responsable;
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
     */
    public function options(AssertionRequest $request): Responsable
    {
        return $request->toVerify($request->validate(['email' => 'sometimes|email|string']));
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
