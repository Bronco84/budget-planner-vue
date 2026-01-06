<?php

namespace App\Http\Controllers\WebAuthn;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Laragear\WebAuthn\Http\Requests\AttestationRequest;
use Laragear\WebAuthn\Http\Requests\AttestedRequest;

use function response;

class WebAuthnRegisterController
{
    /**
     * Returns a challenge to be verified by the user device.
     */
    public function options(AttestationRequest $request): Responsable
    {
        // Ensure user is authenticated
        if (!Auth::check()) {
            abort(401, 'You must be logged in to register a passkey.');
        }

        return $request
            ->fastRegistration()
            ->toCreate();
    }

    /**
     * Registers a device for further WebAuthn authentication.
     */
    public function register(AttestedRequest $request): Response
    {
        // Ensure user is authenticated
        if (!Auth::check()) {
            abort(401, 'You must be logged in to register a passkey.');
        }

        $request->save();

        // Refresh the user to ensure the relationship is loaded
        $user = Auth::user();
        $user->refresh();
        
        // Clear any cached relationship data
        $user->unsetRelation('webAuthnCredentials');

        return response()->noContent();
    }
}
