<?php

use App\Models\MagicLinkToken;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Laragear\WebAuthn\Models\WebAuthnCredential;

/*
 * PROFILE-002. Accounts are passkey-only, so deletion is confirmed by typing the
 * account email, and only in a session that authenticated recently.
 */

function passkeyOnlyUser(): User
{
    $user = User::factory()->create(['password' => null]);
    createPasskey($user);

    return $user;
}

function freshlyAuthenticated(): array
{
    return ['auth' => ['authenticated_at' => now()->getTimestamp()]];
}

test('a passkey only user can delete their account by typing their email in a fresh session', function () {
    $user = passkeyOnlyUser();
    TrustedDevice::create([
        'user_id' => $user->id,
        'device_name' => 'Laptop',
        'device_fingerprint' => Str::random(40),
        'device_token' => Crypt::encryptString(Str::random(64)),
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest',
        'last_used_at' => now(),
        'expires_at' => now()->addDays(30),
        'auth_method' => 'passkey',
    ]);
    $user->magicLinkTokens()->create(['token' => hash('sha256', Str::random(64)), 'expires_at' => now()->addMinutes(15)]);

    $this->actingAs($user)
        ->withSession(freshlyAuthenticated())
        ->delete(route('profile.destroy'), ['email' => strtoupper($user->email)])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    $this->assertGuest();
    expect(User::find($user->id))->toBeNull()
        ->and(WebAuthnCredential::where('authenticatable_id', $user->id)->count())->toBe(0)
        ->and(TrustedDevice::where('user_id', $user->id)->count())->toBe(0)
        ->and(MagicLinkToken::where('user_id', $user->id)->count())->toBe(0);
});

test('deleting an account clears the trusted device cookie', function () {
    $user = passkeyOnlyUser();

    $this->actingAs($user)
        ->withSession(freshlyAuthenticated())
        ->withUnencryptedCookie('device_token', Str::random(64))
        ->delete(route('profile.destroy'), ['email' => $user->email])
        ->assertRedirect('/')
        ->assertCookieExpired('device_token');
});

test('account deletion is rejected when the typed email does not match', function () {
    $user = passkeyOnlyUser();

    $this->actingAs($user)
        ->withSession(freshlyAuthenticated())
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['email' => 'someone-else@example.com'])
        ->assertSessionHasErrors('email')
        ->assertRedirect(route('profile.edit'));

    $this->assertAuthenticatedAs($user);
    expect(User::find($user->id))->not->toBeNull()
        ->and($user->webAuthnCredentials()->count())->toBe(1);
});

test('account deletion is rejected when the session authenticated more than the reauth window ago', function () {
    $user = passkeyOnlyUser();
    $session = freshlyAuthenticated();

    $this->travel(16)->minutes();

    $this->actingAs($user)
        ->withSession($session)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['email' => $user->email])
        ->assertSessionHasErrors(['email' => 'For your security, sign in again before deleting your account.'])
        ->assertRedirect(route('profile.edit'));

    expect(User::find($user->id))->not->toBeNull();
});

test('account deletion is rejected when the session has no recorded sign in time', function () {
    $user = passkeyOnlyUser();

    $this->actingAs($user)
        ->delete(route('profile.destroy'), ['email' => $user->email])
        ->assertSessionHasErrors(['email' => 'For your security, sign in again before deleting your account.']);

    expect(User::find($user->id))->not->toBeNull();
});

test('a guest cannot delete an account', function () {
    $user = passkeyOnlyUser();

    $this->delete(route('profile.destroy'), ['email' => $user->email])
        ->assertRedirect(route('login'));

    expect(User::find($user->id))->not->toBeNull();
});

test('signing in with a magic link records the authentication time used by account deletion', function () {
    $user = passkeyOnlyUser();
    $raw = Str::random(64);
    $user->magicLinkTokens()->create(['token' => hash('sha256', $raw), 'expires_at' => now()->addMinutes(15)]);

    $this->get(route('magic-link.authenticate', ['token' => $raw, 'email' => $user->email]));

    $this->assertAuthenticatedAs($user);
    expect(session('auth.authenticated_at'))->toBe(now()->getTimestamp());

    $this->delete(route('profile.destroy'), ['email' => $user->email])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    expect(User::find($user->id))->toBeNull();
});

test('a trusted device auto login records the authentication time', function () {
    $user = passkeyOnlyUser();
    $headers = ['User-Agent' => 'Mozilla/5.0 (Macintosh) Chrome/120.0', 'Accept-Language' => 'en-US', 'Accept-Encoding' => 'gzip'];
    $token = Str::random(64);
    TrustedDevice::create([
        'user_id' => $user->id,
        'device_name' => 'Chrome on macOS',
        'device_fingerprint' => hash('sha256', implode('|', array_values($headers))),
        'device_token' => Crypt::encryptString($token),
        'ip_address' => '127.0.0.1',
        'user_agent' => $headers['User-Agent'],
        'last_used_at' => now(),
        'expires_at' => now()->addDays(30),
        'auth_method' => 'passkey',
    ]);

    $this->withHeaders($headers)
        ->withCookie('device_token', $token)
        ->get(route('login'));

    $this->assertAuthenticatedAs($user);
    expect(session('auth.authenticated_at'))->toBe(now()->getTimestamp());
});
