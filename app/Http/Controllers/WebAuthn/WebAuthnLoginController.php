<?php

namespace App\Http\Controllers\WebAuthn;

use App\Services\DeviceTokenService;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
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
    public function options(AssertionRequest $request): JsonResponse
    {
        $validated = $request->validate(['email' => 'sometimes|email|string']);
        $responsable = $request->toVerify($validated);
        
        // Convert the Responsable to a Response to inspect/modify the data
        $response = $responsable->toResponse($request);
        $data = json_decode($response->getContent(), true);
        
        // Filter out any credentials with null or empty IDs
        if (isset($data['allowCredentials']) && is_array($data['allowCredentials'])) {
            $originalCount = count($data['allowCredentials']);
            $data['allowCredentials'] = array_values(array_filter($data['allowCredentials'], function ($cred) {
                $isValid = isset($cred['id']) && is_string($cred['id']) && trim($cred['id']) !== '';
                if (!$isValid) {
                    Log::warning('Filtered out invalid WebAuthn credential', ['credential' => $cred]);
                }
                return $isValid;
            }));
            
            $filteredCount = count($data['allowCredentials']);
            if ($originalCount !== $filteredCount) {
                Log::warning("Filtered out invalid credentials", [
                    'original_count' => $originalCount,
                    'filtered_count' => $filteredCount,
                    'email' => $validated['email'] ?? 'not provided'
                ]);
            }
        }
        
        return response()->json($data);
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
