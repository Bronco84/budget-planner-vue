<?php

use App\Models\User;
use Laravel\Passkeys\Actions\StorePasskey;
use Tests\Support\SoftwareAuthenticator;

/*
 * AUTH-002: a failure while saving a passkey must not leak the raw exception.
 */

test('a passkey registration failure returns a generic error without the raw exception', function () {
    useWebAuthnTestRelyingParty();

    // Fail inside the save step, after the ceremony payload was read.
    app()->bind(StorePasskey::class, fn () => new class extends StorePasskey
    {
        public function __invoke($user, $name, $credential, $options): never
        {
            throw new RuntimeException('SQLSTATE[23000] passkeys secret internals');
        }
    });

    $user = User::factory()->create();
    $options = $this->actingAs($user)->postJson(route('webauthn.register.options'))->assertOk()->json();

    $response = $this->actingAs($user)
        ->postJson(route('webauthn.register'), (new SoftwareAuthenticator)->attest($options, WEBAUTHN_TEST_ORIGIN))
        ->assertStatus(500)
        ->assertExactJson(['message' => 'Failed to register passkey. Please try again.']);

    expect($response->getContent())->not->toContain('SQLSTATE')
        ->and($user->passkeys()->count())->toBe(0);
});
