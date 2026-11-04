<?php

namespace App\Services;

use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Webauthn\AuthenticatorSelectionCriteria;

/**
 * laravel/passkeys' registration options, with user verification "preferred"
 * rather than "required" (as under laragear/webauthn), so authenticators that
 * cannot verify the user still enrol. Login is usernameless, so the passkey
 * must stay a discoverable credential (resident key required).
 */
class PasskeyRegistrationOptions extends GenerateRegistrationOptions
{
    public function authenticatorSelection(): AuthenticatorSelectionCriteria
    {
        return AuthenticatorSelectionCriteria::create(
            authenticatorAttachment: AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENT_NO_PREFERENCE,
            userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
        );
    }
}
