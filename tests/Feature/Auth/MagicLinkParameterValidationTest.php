<?php

use App\Models\User;
use Illuminate\Support\Str;

/*
 * AUTH-004: a magic link request missing its token or email fails validation and
 * signs nobody in; a valid token stays unused.
 */

test('a magic link without a token or email fails validation without authenticating', function (string $missing) {
    $user = User::factory()->create();
    $raw = Str::random(64);
    $token = $user->magicLinkTokens()->create([
        'token' => hash('sha256', $raw),
        'expires_at' => now()->addMinutes(15),
    ]);

    $query = array_filter([
        'token' => $missing === 'token' ? null : $raw,
        'email' => $missing === 'email' ? null : $user->email,
    ]);

    $this->get(route('magic-link.authenticate', $query))
        ->assertRedirect()
        ->assertSessionHasErrors($missing);

    $this->assertGuest();
    expect($token->fresh()->used_at)->toBeNull();
})->with(['token', 'email']);

test('a magic link with a malformed email fails validation without authenticating', function () {
    $user = User::factory()->create();
    $raw = Str::random(64);
    $token = $user->magicLinkTokens()->create([
        'token' => hash('sha256', $raw),
        'expires_at' => now()->addMinutes(15),
    ]);

    $this->get(route('magic-link.authenticate', ['token' => $raw, 'email' => 'not-an-email']))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect($token->fresh()->used_at)->toBeNull();
});
