<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Relying Party
    |--------------------------------------------------------------------------
    |
    | We will use your application information to inform the device who is the
    | relying party. While only the name is enough, you can further set the
    | a custom domain as ID and even an icon image data encoded as BASE64.
    |
    */

    'relying_party' => [
        'name' => env('WEBAUTHN_NAME', config('app.name')),
        'id' => env('WEBAUTHN_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Origins
    |--------------------------------------------------------------------------
    |
    | By default, only your application domain is used as a valid origin for
    | all ceremonies. If you are using your app as a backend for an app or
    | UI you may set additional origins to check against the ceremonies.
    |
    | For multiple origins, separate them using comma, like `foo,bar`.
    */

    'origins' => env('WEBAUTHN_ORIGINS'),

    /*
    |--------------------------------------------------------------------------
    | Challenge configuration
    |--------------------------------------------------------------------------
    |
    | When making challenges your application needs to push at least 16 bytes
    | of randomness. Since we need to later check them, we'll also store the
    | bytes for a small amount of time inside this current request session.
    |
    | @see https://www.w3.org/TR/webauthn-2/#sctn-cryptographic-challenges
    |
    */

    'challenge' => [
        'bytes' => 16,
        'timeout' => 60,
        'key' => '_webauthn',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authenticator Attachment
    |--------------------------------------------------------------------------
    |
    | This controls which types of authenticators are allowed:
    | - 'platform': Only platform authenticators (Face ID, Touch ID, Windows Hello)
    | - 'cross-platform': Only roaming authenticators (security keys, 1Password)
    | - null: Allow both (default)
    |
    */

    'authenticator_attachment' => env('WEBAUTHN_ATTACHMENT', null),

    /*
    |--------------------------------------------------------------------------
    | User Verification
    |--------------------------------------------------------------------------
    |
    | This controls whether user verification is required:
    | - 'required': Always require user verification
    | - 'preferred': Prefer user verification if available (default)
    | - 'discouraged': Don't require user verification
    |
    */

    'user_verification' => env('WEBAUTHN_USER_VERIFICATION', 'preferred'),

    /*
    |--------------------------------------------------------------------------
    | Resident Key
    |--------------------------------------------------------------------------
    |
    | This controls whether to create a resident key (discoverable credential):
    | - 'required': Always create resident key
    | - 'preferred': Create if supported
    | - 'discouraged': Don't create resident key (default)
    |
    */

    'resident_key' => env('WEBAUTHN_RESIDENT_KEY', 'preferred'),
];
