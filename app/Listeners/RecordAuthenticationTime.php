<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Login;

/**
 * Stamps the session with the time the user last authenticated, for every login
 * path that goes through the session guard: passkey assertion
 * (WebAuthnLoginController), magic link (MagicLinkController), trusted-device
 * auto-login (RememberDevice) and registration. Sensitive actions such as
 * account deletion require this to be recent.
 */
class RecordAuthenticationTime
{
    public function handle(Login $event): void
    {
        if (! request()->hasSession()) {
            return;
        }

        request()->session()->put('auth.authenticated_at', now()->getTimestamp());
    }
}
