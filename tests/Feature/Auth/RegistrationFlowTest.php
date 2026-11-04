<?php

use App\Models\TrustedDevice;
use App\Models\User;

/*
 * AUTH-002. Account creation and the guards around passkey enrolment. The
 * attestation ceremony itself is in PasskeyRegistrationCeremonyTest.
 */

const REGISTRATION_OWNER_EMAIL = 'bamccoley@gmail.com';

test('registering the allowlisted email creates a passwordless user and sends them to passkey registration', function () {
    $this->post(route('register'), ['name' => 'Owner', 'email' => REGISTRATION_OWNER_EMAIL])
        ->assertRedirect(route('passkey.register', ['new_user' => true]))
        ->assertSessionHas('status', 'Account created! Now register your passkey.');

    $user = User::where('email', REGISTRATION_OWNER_EMAIL)->sole();
    expect($user->name)->toBe('Owner')
        ->and($user->password)->toBeNull();

    $this->assertAuthenticatedAs($user);
});

test('registering any other email is rejected and no user is created', function () {
    $this->from(route('register'))
        ->post(route('register'), ['name' => 'Stranger', 'email' => 'stranger@example.com'])
        ->assertRedirect(route('register'))
        ->assertSessionHasErrors('email');

    expect(User::count())->toBe(0);
    $this->assertGuest();
});

test('registration requires a name and a valid unused email', function (array $payload, string $errorKey) {
    User::factory()->create(['email' => REGISTRATION_OWNER_EMAIL]);

    $this->from(route('register'))
        ->post(route('register'), $payload)
        ->assertSessionHasErrors($errorKey);

    expect(User::count())->toBe(1);
    $this->assertGuest();
})->with([
    'missing name' => [['email' => 'someone@example.com'], 'name'],
    'malformed email' => [['name' => 'Owner', 'email' => 'not-an-email'], 'email'],
    'already registered email' => [['name' => 'Owner', 'email' => REGISTRATION_OWNER_EMAIL], 'email'],
]);

test('registering an existing account email without an invitation gets the same answer as an unknown email', function () {
    User::factory()->create(['email' => 'member@example.com']);

    $existing = $this->from(route('register'))
        ->post(route('register'), ['name' => 'Probe', 'email' => 'member@example.com'])
        ->assertRedirect(route('register'))
        ->assertSessionHasErrors('email');
    $existingError = session('errors')->first('email');

    $this->flushSession();

    $this->from(route('register'))
        ->post(route('register'), ['name' => 'Probe', 'email' => 'nobody@example.com'])
        ->assertSessionHasErrors('email');

    expect($existingError)->toBe(session('errors')->first('email'))
        ->and($existingError)->toBe('Registration is currently restricted. Please contact the administrator.')
        ->and(User::count())->toBe(1);
});

test('registration submissions beyond six per minute are throttled', function () {
    foreach (range(1, 6) as $i) {
        $this->post(route('register'), ['name' => 'Stranger', 'email' => "stranger{$i}@example.com"])
            ->assertStatus(302);
    }

    $this->post(route('register'), ['name' => 'Stranger', 'email' => 'stranger7@example.com'])
        ->assertStatus(429);
});

test('a guest cannot request passkey registration options or register a passkey', function () {
    $this->postJson(route('webauthn.register.options'))->assertUnauthorized();
    $this->postJson(route('webauthn.register'))->assertUnauthorized();
});

test('an authenticated user visiting the registration form is redirected away', function () {
    $user = User::factory()->create();
    createPasskey($user);

    $this->actingAs($user)->get(route('register'))->assertRedirect(route('dashboard'));
    $this->actingAs($user)
        ->post(route('register'), ['name' => 'Owner', 'email' => REGISTRATION_OWNER_EMAIL])
        ->assertRedirect(route('dashboard'));

    expect(User::count())->toBe(1);
});

test('a failed passkey attestation returns an error and stores no credential or trusted device', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('webauthn.register.options'))->assertOk();

    $response = $this->actingAs($user)->postJson(route('webauthn.register'), [
        'id' => 'bogus-credential',
        'rawId' => 'bogus-credential',
        'type' => 'public-key',
        'response' => [
            'clientDataJSON' => 'bm90LXJlYWw',
            'attestationObject' => 'bm90LXJlYWw',
        ],
    ]);

    expect($response->status())->toBeGreaterThanOrEqual(400)
        ->and($user->passkeys()->count())->toBe(0)
        ->and(TrustedDevice::count())->toBe(0);
    $response->assertCookieMissing('device_token');
});
