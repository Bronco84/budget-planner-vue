<?php

use App\Http\Middleware\EnsureUserHasPasskey;
use App\Models\User;

// TestCase disables passkey enforcement globally; these tests opt back in.
beforeEach(function () {
    $this->withMiddleware(EnsureUserHasPasskey::class);
});

test('a signed-in user without a passkey is redirected to passkey registration', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('budgets.index'))
        ->assertRedirect(route('passkey.register'))
        ->assertSessionHas('error');
});

test('the passkey registration page stays reachable without a passkey', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('passkey.register'))
        ->assertOk();
});

test('passkey registration options stay reachable without a passkey', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('webauthn.register.options'))
        ->assertOk()
        ->assertJsonStructure(['challenge']);
});

test('a user without a passkey can still sign out', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('logout'))
        ->assertRedirect('/login');

    $this->assertGuest();
});

test('a user with a passkey is not redirected', function () {
    $user = User::factory()->create();
    createPasskey($user);

    $response = $this->actingAs($user)->get(route('budgets.index'));

    expect($response->headers->get('Location'))->not->toBe(route('passkey.register'));
});
