<?php

use App\Models\TrustedDevice;
use App\Models\User;

/*
 * AUTH-001. Non-ceremony behaviour of the passkey login endpoints. Real
 * assertion ceremonies are in PasskeyLoginCeremonyTest.
 */

test('passkey login option requests beyond ten per minute are throttled', function () {
    foreach (range(1, 10) as $i) {
        $this->postJson(route('webauthn.login.options'))->assertOk();
    }

    $this->postJson(route('webauthn.login.options'))->assertStatus(429);
});

test('passkey login options are available again after the throttle window', function () {
    $this->freezeTime();

    foreach (range(1, 10) as $i) {
        $this->postJson(route('webauthn.login.options'))->assertOk();
    }
    $this->postJson(route('webauthn.login.options'))->assertStatus(429);

    $this->travel(61)->seconds();

    $this->postJson(route('webauthn.login.options'))->assertOk();
});

test('an invalid passkey assertion is rejected with 422 and no session or device is created', function () {
    $user = User::factory()->create();
    createPasskey($user, 'known-credential-id');

    $this->postJson(route('webauthn.login.options'))->assertOk();

    $this->postJson(route('webauthn.login'), [
        'id' => 'unknown-credential-id',
        'rawId' => 'unknown-credential-id',
        'type' => 'public-key',
        'response' => [
            'clientDataJSON' => 'bm90LXJlYWw',
            'authenticatorData' => 'bm90LXJlYWw',
            'signature' => 'bm90LXJlYWw',
            'userHandle' => null,
        ],
    ])->assertStatus(422);

    $this->assertGuest();
    expect(TrustedDevice::count())->toBe(0);
});

test('a malformed passkey assertion payload is rejected with 422', function () {
    $this->postJson(route('webauthn.login'), ['id' => ''])->assertStatus(422);

    $this->assertGuest();
    expect(TrustedDevice::count())->toBe(0);
});
