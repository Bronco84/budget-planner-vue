<?php

use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Laravel\Passkeys\Passkey;
use Tests\Support\SoftwareAuthenticator;

/*
 * AUTH-002 / AUTH-011. The WebAuthn attestation ceremony end to end against a
 * real software authenticator: laravel/passkeys' StorePasskey and
 * web-auth/webauthn-lib's attestation validation are not mocked.
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

    $passkey = Passkey::sole();
    expect($passkey->credential_id)->toBe($authenticator->credentialId())
        ->and($passkey->user->is($user))->toBeTrue()
        ->and(passkeyPublicKeyPem($passkey))->toBe($authenticator->publicKeyPem())
        ->and($passkey->credential['attestationType'])->toBe('none')
        ->and($passkey->credential['counter'])->toBe(0)
        ->and($passkey->credential['userHandle'])->toBe($options['user']['id'])
        ->and($options['authenticatorSelection']['residentKey'])->toBe('required');

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
    expect(array_column($options['excludeCredentials'], 'id'))->toBe([$first->credential_id]);

    $second = new SoftwareAuthenticator;
    $this->actingAs($user)
        ->postJson(route('webauthn.register'), $second->attest($options, WEBAUTHN_TEST_ORIGIN))
        ->assertNoContent();

    $passkeys = $user->passkeys()->get();
    expect($passkeys)->toHaveCount(2)
        ->and($passkeys->pluck('credential_id')->all())->toEqualCanonicalizing([$first->credential_id, $second->credentialId()])
        ->and(passkeyPublicKeyPem($passkeys->firstWhere('credential_id', $second->credentialId())))->toBe($second->publicKeyPem())
        // Both passkeys share the user's WebAuthn user handle.
        ->and($passkeys->pluck('credential.userHandle')->unique())->toHaveCount(1);
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

    expect($user->passkeys()->count())->toBe(1);
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

    expect(Passkey::count())->toBe(0)
        ->and(TrustedDevice::count())->toBe(0);
})->with([
    'foreign origin' => ['https://evil.example', false],
    'insecure origin' => ['http://budget-planner-vue.test', false],
    'wrong challenge' => [WEBAUTHN_TEST_ORIGIN, true],
]);

test('the passkey name typed at registration is saved as its name', function () {
    $user = User::factory()->create();
    $options = $this->actingAs($user)->postJson(route('webauthn.register.options'))->assertOk()->json();
    $authenticator = new SoftwareAuthenticator;

    $this->actingAs($user)
        ->postJson(route('webauthn.register'), [
            ...$authenticator->attest($options, WEBAUTHN_TEST_ORIGIN),
            'name' => '  Work laptop  ',
        ])
        ->assertNoContent();

    expect(Passkey::where('credential_id', $authenticator->credentialId())->sole()->name)->toBe('Work laptop');
});

test('a blank passkey name falls back to the device name', function () {
    $user = User::factory()->create();
    $options = $this->actingAs($user)->postJson(route('webauthn.register.options'))->assertOk()->json();
    $authenticator = new SoftwareAuthenticator;

    $this->actingAs($user)
        ->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36')
        ->postJson(route('webauthn.register'), [
            ...$authenticator->attest($options, WEBAUTHN_TEST_ORIGIN),
            'name' => '   ',
        ])
        ->assertNoContent();

    expect(Passkey::where('credential_id', $authenticator->credentialId())->sole()->name)->toBe('Computer - macOS - Chrome');
});

test('an over-long passkey name is rejected and nothing is saved', function () {
    $user = User::factory()->create();
    $options = $this->actingAs($user)->postJson(route('webauthn.register.options'))->assertOk()->json();
    $authenticator = new SoftwareAuthenticator;

    $this->actingAs($user)
        ->postJson(route('webauthn.register'), [
            ...$authenticator->attest($options, WEBAUTHN_TEST_ORIGIN),
            'name' => str_repeat('a', 256),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');

    expect(Passkey::count())->toBe(0);
});
