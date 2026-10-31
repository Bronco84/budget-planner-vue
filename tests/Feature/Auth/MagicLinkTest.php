<?php

use App\Mail\MagicLinkEmail;
use App\Models\MagicLinkToken;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

function issueMagicLink(User $user, array $overrides = []): string
{
    $raw = Str::random(64);
    $user->magicLinkTokens()->create(array_merge([
        'token' => hash('sha256', $raw),
        'expires_at' => now()->addMinutes(15),
    ], $overrides));

    return $raw;
}

test('requesting a magic link emails a single-use link and stores only a hash', function () {
    Mail::fake();
    $user = User::factory()->create();

    $this->post(route('magic-link.store'), ['email' => $user->email])
        ->assertRedirect()
        ->assertSessionHas('status');

    $stored = MagicLinkToken::where('user_id', $user->id)->sole();
    expect(strlen($stored->token))->toBe(64)
        ->and($stored->expires_at->isFuture())->toBeTrue()
        ->and($stored->used_at)->toBeNull();

    Mail::assertSent(MagicLinkEmail::class, fn ($mail) => $mail->hasTo($user->email));
});

test('magic link requests require a valid email address', function () {
    Mail::fake();

    $this->post(route('magic-link.store'), ['email' => 'not-an-email'])
        ->assertSessionHasErrors('email');

    Mail::assertNothingSent();
});

test('a valid magic link signs in a user without a passkey and sends them to passkey registration', function () {
    $user = User::factory()->create();
    $token = issueMagicLink($user);

    $this->get(route('magic-link.authenticate', ['token' => $token, 'email' => $user->email]))
        ->assertRedirect(route('passkey.register'));

    $this->assertAuthenticatedAs($user);
    expect(MagicLinkToken::where('user_id', $user->id)->sole()->used_at)->not->toBeNull();
});

test('a valid magic link signs in a user with a passkey and sends them to their budgets', function () {
    $user = User::factory()->create();
    createPasskey($user);
    $token = issueMagicLink($user);

    $this->get(route('magic-link.authenticate', ['token' => $token, 'email' => $user->email]))
        ->assertRedirect(route('budgets.index'));

    $this->assertAuthenticatedAs($user);
});

test('an expired magic link is rejected', function () {
    $user = User::factory()->create();
    $token = issueMagicLink($user, ['expires_at' => now()->subMinute()]);

    $this->get(route('magic-link.authenticate', ['token' => $token, 'email' => $user->email]))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('magic_link');

    $this->assertGuest();
});

test('a magic link cannot be used twice', function () {
    $user = User::factory()->create();
    $token = issueMagicLink($user);
    $url = route('magic-link.authenticate', ['token' => $token, 'email' => $user->email]);

    $this->get($url);
    $this->assertAuthenticatedAs($user);

    auth()->logout();

    $this->get($url)
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('magic_link');
    $this->assertGuest();
});

test('a tampered magic link token is rejected', function () {
    $user = User::factory()->create();
    $token = issueMagicLink($user);

    $this->get(route('magic-link.authenticate', ['token' => strrev($token), 'email' => $user->email]))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('magic_link');

    $this->assertGuest();
});

test('a magic link presented with a different email address is rejected', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $token = issueMagicLink($user);

    $this->get(route('magic-link.authenticate', ['token' => $token, 'email' => $other->email]))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('magic_link');

    $this->assertGuest();
});

test('an unknown email gets the same response as a known one and no mail is sent', function () {
    Mail::fake();
    $user = User::factory()->create();

    $known = $this->from(route('magic-link.request'))
        ->post(route('magic-link.store'), ['email' => $user->email]);
    $knownStatus = session('status');

    $this->flushSession();

    $unknown = $this->from(route('magic-link.request'))
        ->post(route('magic-link.store'), ['email' => 'nobody-'.Str::random(8).'@example.com']);

    $unknown->assertRedirect(route('magic-link.request'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', $knownStatus);
    expect($unknown->getStatusCode())->toBe($known->getStatusCode())
        ->and($unknown->headers->get('Location'))->toBe($known->headers->get('Location'))
        ->and(MagicLinkToken::count())->toBe(1);

    Mail::assertSent(MagicLinkEmail::class, 1);
    Mail::assertSent(MagicLinkEmail::class, fn ($mail) => $mail->hasTo($user->email));
});

test('the login page receives the invalid magic link error so it can be shown', function () {
    $user = User::factory()->create();

    $this->followingRedirects()
        ->get(route('magic-link.authenticate', ['token' => 'not-a-real-token', 'email' => $user->email]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Auth/PasskeyLogin')
            ->where('errors.magic_link', 'Invalid or expired magic link.'));

    $this->assertGuest();
});
