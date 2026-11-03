<?php

use App\Models\User;
use Tests\Support\SoftwareAuthenticator;

test('guest can fetch webauthn login options as json', function () {
    $response = $this->postJson('/webauthn/login/options');

    $response->assertOk();
    $response->assertJsonStructure(['challenge']);
});

test('an authenticated request to login options returns json, not an html redirect', function () {
    // Regression: a `guest` guard here 302-redirected authenticated requests
    // (e.g. trusted-device auto-login) to an HTML page, which the passkey UI
    // surfaced as "Server configuration error." The endpoint must always
    // return the JSON challenge.
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/webauthn/login/options');

    $response->assertOk();
    $response->assertHeader('content-type', 'application/json');
    $response->assertJsonStructure(['challenge']);
});

test('login options are the same for a registered email and an unknown one', function () {
    useWebAuthnTestRelyingParty();
    $user = User::factory()->create(['email' => 'member@example.com']);
    enrolPasskey($user, new SoftwareAuthenticator);
    auth()->logout();

    $known = $this->postJson('/webauthn/login/options', ['email' => 'member@example.com'])->assertOk()->json();
    $unknown = $this->postJson('/webauthn/login/options', ['email' => 'nobody@example.com'])->assertOk()->json();

    expect($known['allowCredentials'] ?? [])->toBe([])
        ->and($unknown['allowCredentials'] ?? [])->toBe([])
        ->and(array_keys($known))->toBe(array_keys($unknown));
});

test('passkey registration asks for a discoverable credential', function () {
    $user = User::factory()->create();

    $options = $this->actingAs($user)->postJson(route('webauthn.register.options'))->assertOk()->json();

    expect($options['authenticatorSelection']['residentKey'] ?? null)->toBe('required');
});
