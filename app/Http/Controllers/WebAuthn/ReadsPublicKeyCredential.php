<?php

namespace App\Http\Controllers\WebAuthn;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Passkeys\Support\WebAuthn;
use Throwable;
use Webauthn\Exception\WebauthnException;
use Webauthn\PublicKeyCredential;

/**
 * Reads the PublicKeyCredential that PasskeyLogin.vue / PasskeyRegister.vue
 * post (id, rawId, type, response at the top level, base64url fields) and the
 * ceremony options stored in the session when the challenge was issued.
 */
trait ReadsPublicKeyCredential
{
    /**
     * @throws ValidationException
     */
    protected function publicKeyCredential(Request $request, string $errorKey): PublicKeyCredential
    {
        $request->validate([
            'id' => ['required', 'string'],
            'rawId' => ['required', 'string'],
            'type' => ['required', 'string', 'in:public-key'],
            'response' => ['required', 'array'],
        ]);

        try {
            return WebAuthn::fromJson(
                json_encode($request->only(['id', 'rawId', 'type', 'response', 'authenticatorAttachment', 'clientExtensionResults']), JSON_THROW_ON_ERROR),
                PublicKeyCredential::class,
            );
        } catch (Throwable) {
            throw ValidationException::withMessages([$errorKey => 'Invalid credential format.']);
        }
    }

    /**
     * The options issued with the challenge, pulled so the challenge is single use.
     *
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     *
     * @throws ValidationException
     */
    protected function pullCeremonyOptions(Request $request, string $sessionKey, string $class, string $errorKey): object
    {
        $serialized = $request->session()->pull($sessionKey);

        if (! is_string($serialized) || $serialized === '') {
            throw ValidationException::withMessages([$errorKey => 'The passkey challenge expired. Please try again.']);
        }

        return WebAuthn::fromJson($serialized, $class);
    }

    /**
     * True for a ceremony the authenticator response failed (bad signature,
     * origin, challenge, counter ...), as opposed to an application error.
     */
    protected function isCeremonyFailure(Throwable $e): bool
    {
        return $e instanceof WebauthnException || $e instanceof InvalidArgumentException;
    }
}
