<?php

use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Laragear\WebAuthn\Models\WebAuthnCredential;
use Ramsey\Uuid\Uuid;
use Tests\Support\SoftwareAuthenticator;

/*
 * AUTH-002 / AUTH-011. The WebAuthn attestation ceremony end to end against a
 * real software authenticator: Laragear's attestation pipeline is not mocked.
 */

beforeEach(fn () => useWebAuthnTestRelyingParty());

test('a new account enrols its first passkey through a real attestation ceremony', function () {
    $this->post(route('register'), ['name' => 'Owner', 'email' => 'bamccoley@gmail.com'])
        ->assertRedirect(route('passkey.register', ['new_user' => true]));
    $user = User::where('email', 'bamccoley@gmail.com')->sole();

    $this->get(route('passkey.register', ['new_user' => true]))->assertOk();

    $options = $this->postJson(route('webauthn.register.options'), ['name' => 'My Passkey'])
        ->assertOk()
        ->assertJsonPath('rp.id', WEBAUTHN_TEST_RP_ID)
        ->json();

    $authenticator = new SoftwareAuthenticator;

    $response = $this->postJson(route('webauthn.register'), $authenticator->attest($options, WEBAUTHN_TEST_ORIGIN))
        ->assertNoContent();

    $credential = WebAuthnCredential::sole();
    expect($credential->getKey())->toBe($authenticator->credentialId())
        ->and($credential->authenticatable->is($user))->toBeTrue()
        ->and($credential->public_key)->toBe($authenticator->publicKeyPem())
        ->and($credential->rp_id)->toBe(WEBAUTHN_TEST_RP_ID)
        ->and($credential->origin)->toBe(WEBAUTHN_TEST_ORIGIN)
        ->and($credential->attestation_format)->toBe('none')
        ->and($credential->counter)->toBe(0)
        ->and(Uuid::fromString($credential->user_id)->getHex()->toString())->toBe($options['user']['id']);

    $device = TrustedDevice::sole();
    expect($device->user_id)->toBe($user->id)
        ->and($device->auth_method)->toBe('passkey');
    $response->assertCookie('device_token', Crypt::decryptString($device->device_token));

    $this->assertAuthenticatedAs($user);
});

test('a user who already has a passkey can add a second one', function () {
    $user = User::factory()->create();
    $first = enrolPasskey($user, new SoftwareAuthenticator);

    $options = $this->actingAs($user)->postJson(route('webauthn.register.options'))->assertOk()->json();

    // The existing credential is excluded so the same authenticator can't enrol twice.
    expect(array_column($options['excludeCredentials'], 'id'))->toBe([$first->getKey()]);

    $second = new SoftwareAuthenticator;
    $this->actingAs($user)
        ->postJson(route('webauthn.register'), $second->attest($options, WEBAUTHN_TEST_ORIGIN))
        ->assertNoContent();

    $credentials = $user->webAuthnCredentials()->get();
    expect($credentials)->toHaveCount(2)
        ->and($credentials->modelKeys())->toEqualCanonicalizing([$first->getKey(), $second->credentialId()])
        ->and($credentials->firstWhere('id', $second->credentialId())->public_key)->toBe($second->publicKeyPem())
        // Both credentials share the user's WebAuthn user handle.
        ->and($credentials->pluck('user_id')->unique())->toHaveCount(1);
});

test('replaying an attestation for an already registered credential is rejected', function () {
    $user = User::factory()->create();
    $authenticator = new SoftwareAuthenticator;
    enrolPasskey($user, $authenticator);

    // Skip excludeCredentials (a misbehaving client) and resubmit the same credential id.
    $options = $this->actingAs($user)->postJson(route('webauthn.register.options'))->json();
    unset($options['excludeCredentials']);

    $this->actingAs($user)
        ->postJson(route('webauthn.register'), $authenticator->attest($options, WEBAUTHN_TEST_ORIGIN))
        ->assertStatus(422)
        ->assertJsonValidationErrors('attestation');

    expect($user->webAuthnCredentials()->count())->toBe(1);
});

test('an attestation for a different origin or challenge is rejected and stores nothing', function (string $origin, bool $swapChallenge) {
    $user = User::factory()->create();

    $options = $this->actingAs($user)->postJson(route('webauthn.register.options'))->json();
    if ($swapChallenge) {
        $options['challenge'] = SoftwareAuthenticator::b64url(random_bytes(16));
    }

    $this->actingAs($user)
        ->postJson(route('webauthn.register'), (new SoftwareAuthenticator)->attest($options, $origin))
        ->assertStatus(422)
        ->assertJsonValidationErrors('attestation')
        ->assertCookieMissing('device_token');

    expect(WebAuthnCredential::count())->toBe(0)
        ->and(TrustedDevice::count())->toBe(0);
})->with([
    'foreign origin' => ['https://evil.example', false],
    'insecure origin' => ['http://budget-planner-vue.test', false],
    'wrong challenge' => [WEBAUTHN_TEST_ORIGIN, true],
]);
