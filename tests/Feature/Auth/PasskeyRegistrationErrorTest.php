<?php

use App\Models\User;
use Laragear\WebAuthn\Http\Requests\AttestedRequest;

/*
 * AUTH-002: a failure while saving a passkey must not leak the raw exception.
 */

test('a passkey registration failure returns a generic error without the raw exception', function () {
    // Skip attestation validation and fail inside the controller's save step.
    app()->bind(AttestedRequest::class, fn () => new class extends AttestedRequest
    {
        public function validateResolved(): void {}

        public function save(array|callable $saving = []): string
        {
            throw new RuntimeException('SQLSTATE[23000] webauthn_credentials secret internals');
        }
    });

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson(route('webauthn.register'))
        ->assertStatus(500)
        ->assertExactJson(['message' => 'Failed to register passkey. Please try again.']);

    expect($response->getContent())->not->toContain('SQLSTATE')
        ->and($user->webAuthnCredentials()->count())->toBe(0);
});
