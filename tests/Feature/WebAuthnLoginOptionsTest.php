<?php

use App\Models\User;

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
