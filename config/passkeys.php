<?php

/*
| laravel/passkeys. The relying party and origins read the WEBAUTHN_* variables
| laragear/webauthn used, with the same fallbacks, so passkeys registered under
| that package (copied over by 2026_11_04_000049) keep verifying: a passkey's
| RP ID is fixed when it is registered.
*/

$relyingPartyId = env('WEBAUTHN_ID') ?: parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST);

return [

    'relying_party_id' => $relyingPartyId,

    // Like laragear: the RP ID's https origin is allowed even when APP_URL
    // says http (local Herd), APP_URL itself only over https or on localhost,
    // plus any extra origins listed in WEBAUTHN_ORIGINS (comma separated).
    'allowed_origins' => array_values(array_unique(array_filter([
        'https://'.$relyingPartyId,
        (function (string $url): ?string {
            $parts = parse_url($url) ?: [];
            $secure = ($parts['scheme'] ?? null) === 'https' || ($parts['host'] ?? null) === 'localhost';

            return $secure ? rtrim($url, '/') : null;
        })((string) env('APP_URL', '')),
        ...array_map('trim', explode(',', (string) env('WEBAUTHN_ORIGINS', ''))),
    ]))),

    // Derives new passkeys' WebAuthn user handle. Passkeys moved from laragear
    // keep the handle they were registered with.
    'user_handle_secret' => env('PASSKEYS_USER_HANDLE_SECRET') ?: env('APP_KEY'),

    'timeout' => 60000,

    'guard' => 'web',

    // The package's own routes are not registered (Passkeys::ignoreRoutes() in
    // AppServiceProvider); the app's WebAuthn controllers drive its actions, so
    // the route settings below are unused.
    'middleware' => ['web'],

    'management_middleware' => [],

    'throttle' => null,

    'redirect' => '/budgets',

];
