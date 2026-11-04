<?php

use App\Models\User;
use CBOR\Decoder;
use CBOR\StringStream;
use Cose\Key\Ec2Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passkeys\Passkey;
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
 * Persist a passkey for the user without running a real ceremony (its
 * credential holds no usable key, so it cannot complete an assertion).
 */
function createPasskey(User $user, ?string $id = null): Passkey
{
    return $user->passkeys()->create([
        'name' => 'Test key',
        'credential_id' => $id ?? Str::random(32),
        'credential' => ['counter' => 0],
    ]);
}

/**
 * A row in the legacy laragear `webauthn_credentials` table (kept after the
 * move to laravel/passkeys), standing for the copy a passkey came from.
 */
function legacyWebAuthnRow(User $user, string $id): void
{
    DB::table('webauthn_credentials')->insert([
        'id' => $id,
        'authenticatable_type' => User::class,
        'authenticatable_id' => $user->id,
        'user_id' => (string) Str::uuid(),
        'rp_id' => 'localhost',
        'origin' => 'https://localhost',
        'public_key' => 'legacy-public-key',
        'created_at' => now(),
        'updated_at' => now(),
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
        'passkeys.relying_party_id' => WEBAUTHN_TEST_RP_ID,
        'passkeys.allowed_origins' => [WEBAUTHN_TEST_ORIGIN],
    ]);
}

/**
 * Run the full attestation ceremony (register options -> authenticator ->
 * webauthn.register) for the user and return the stored credential.
 */
function enrolPasskey(User $user, SoftwareAuthenticator $authenticator): Passkey
{
    $options = test()->actingAs($user)
        ->postJson(route('webauthn.register.options'))
        ->assertOk()
        ->json();

    test()->actingAs($user)
        ->postJson(route('webauthn.register'), $authenticator->attest($options, WEBAUTHN_TEST_ORIGIN))
        ->assertNoContent();

    return Passkey::where('credential_id', $authenticator->credentialId())->firstOrFail();
}

/**
 * The passkey's stored COSE public key as an SPKI PEM, to compare with
 * SoftwareAuthenticator::publicKeyPem().
 */
function passkeyPublicKeyPem(Passkey $passkey): string
{
    $cose = Decoder::create()
        ->decode(StringStream::create(SoftwareAuthenticator::b64urlDecode($passkey->credential['credentialPublicKey'])))
        ->normalize();

    return Ec2Key::create($cose)->asPEM();
}

/**
 * Start a streamed chat turn (POST /chat/stream) and, when it is accepted,
 * open the stream it returns (GET /chat/stream/{streamId}). A refused start
 * (validation, 403, 404, 429, 400) is returned as-is.
 */
function streamChat(Illuminate\Foundation\Testing\TestCase $test, array $payload): TestResponse
{
    $start = $test->postJson(route('chat.stream.start'), $payload);

    if ($start->status() !== 200) {
        return $start;
    }

    return $test->get(route('chat.stream', $start->json('stream_id')));
}
