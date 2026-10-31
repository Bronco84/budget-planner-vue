<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laragear\WebAuthn\Models\WebAuthnCredential;
use Tests\Support\SoftwareAuthenticator;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

uses(TestCase::class, RefreshDatabase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Persist a WebAuthn credential for the user without running a real ceremony.
 */
function createPasskey(User $user, ?string $id = null): WebAuthnCredential
{
    return $user->webAuthnCredentials()->forceCreate([
        'id' => $id ?? Str::random(32),
        'user_id' => (string) Str::uuid(),
        'alias' => 'Test key',
        'counter' => 0,
        'rp_id' => 'localhost',
        'origin' => 'http://localhost',
        'public_key' => 'test-public-key',
        'attestation_format' => 'none',
    ]);
}

/*
| WebAuthn ceremonies against a real software authenticator (tests/Support).
| The relying party is pinned here so the ceremonies don't depend on the local
| .env (WEBAUTHN_ID / APP_URL).
*/
const WEBAUTHN_TEST_RP_ID = 'budget-planner-vue.test';
const WEBAUTHN_TEST_ORIGIN = 'https://budget-planner-vue.test';

function useWebAuthnTestRelyingParty(): void
{
    config([
        'webauthn.relying_party.id' => WEBAUTHN_TEST_RP_ID,
        'webauthn.origins' => null,
    ]);
}

/**
 * Run the full attestation ceremony (register options -> authenticator ->
 * webauthn.register) for the user and return the stored credential.
 */
function enrolPasskey(User $user, SoftwareAuthenticator $authenticator): WebAuthnCredential
{
    $options = test()->actingAs($user)
        ->postJson(route('webauthn.register.options'))
        ->assertOk()
        ->json();

    test()->actingAs($user)
        ->postJson(route('webauthn.register'), $authenticator->attest($options, WEBAUTHN_TEST_ORIGIN))
        ->assertNoContent();

    return WebAuthnCredential::findOrFail($authenticator->credentialId());
}
