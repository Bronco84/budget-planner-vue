<?php

use App\Mail\NewDeviceLogin;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Laragear\WebAuthn\Models\WebAuthnCredential;
use Tests\Support\SoftwareAuthenticator;

/*
 * AUTH-001 / PROFILE-002. The WebAuthn assertion ceremony end to end against a
 * real software authenticator: Laragear's assertion pipeline and the
 * eloquent-webauthn user provider run unmodified.
 */

beforeEach(fn () => useWebAuthnTestRelyingParty());

/**
 * Enrol a passkey through the real attestation ceremony, then return to a guest
 * session with no trusted devices so the login under test starts clean.
 */
function enrolledPasskeyUser(SoftwareAuthenticator $authenticator): User
{
    $user = User::factory()->create(['password' => null]);
    enrolPasskey($user, $authenticator);

    TrustedDevice::query()->delete();
    // The cookie jar outlives a request in tests; drop the enrolment's queued device cookie.
    Cookie::flushQueuedCookies();
    Auth::forgetGuards();
    session()->flush();

    return $user;
}

/**
 * Guest requests login options, then the authenticator answers them.
 */
function passkeyAssertion(SoftwareAuthenticator $authenticator, array $optionsBody = []): array
{
    $options = test()->postJson(route('webauthn.login.options'), $optionsBody)->assertOk()->json();

    return $authenticator->assert($options, WEBAUTHN_TEST_ORIGIN);
}

test('a valid passkey assertion signs the user in and issues a trusted device cookie', function (bool $withEmail) {
    $authenticator = new SoftwareAuthenticator;
    $user = enrolledPasskeyUser($authenticator);
    $this->assertGuest();

    $response = $this->postJson(
        route('webauthn.login'),
        passkeyAssertion($authenticator, $withEmail ? ['email' => $user->email] : []),
    )->assertNoContent();

    $this->assertAuthenticatedAs($user);

    $device = TrustedDevice::sole();
    expect($device->user_id)->toBe($user->id)
        ->and($device->auth_method)->toBe('passkey')
        // The regenerated (post-login) session, so revoking the device ends it.
        ->and($device->session_id)->not->toBeNull()->toBe(session()->getId());
    $response->assertCookie('device_token', Crypt::decryptString($device->device_token));

    // The authenticator's signature counter is persisted for clone detection.
    expect(WebAuthnCredential::sole()->counter)->toBe(1);
})->with([
    'with an email, which is ignored' => [true],
    'usernameless (discoverable credential)' => [false],
]);

test('signing in with a passkey records the authentication time used by account deletion', function () {
    $this->freezeTime();
    $authenticator = new SoftwareAuthenticator;
    $user = enrolledPasskeyUser($authenticator);
    expect(session('auth.authenticated_at'))->toBeNull();

    $this->postJson(route('webauthn.login'), passkeyAssertion($authenticator))->assertNoContent();

    $this->assertAuthenticatedAs($user);
    expect(session('auth.authenticated_at'))->toBe(now()->getTimestamp());

    $this->delete(route('profile.destroy'), ['email' => $user->email])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    expect(User::find($user->id))->toBeNull();
});

test('a tampered or mismatched passkey assertion is rejected with 422 and no session', function (Closure $tamper) {
    $authenticator = new SoftwareAuthenticator;
    $user = enrolledPasskeyUser($authenticator);

    $options = $this->postJson(route('webauthn.login.options'))->assertOk()->json();
    $payload = $tamper($authenticator, $options);

    $this->postJson(route('webauthn.login'), $payload)
        ->assertStatus(422)
        ->assertCookieMissing('device_token');

    $this->assertGuest();
    expect(TrustedDevice::count())->toBe(0)
        ->and(session('auth.authenticated_at'))->toBeNull()
        ->and(WebAuthnCredential::sole()->counter)->toBe(0);
})->with([
    'tampered signature' => [function (SoftwareAuthenticator $authenticator, array $options): array {
        $payload = $authenticator->assert($options, WEBAUTHN_TEST_ORIGIN);
        $signature = SoftwareAuthenticator::b64urlDecode($payload['response']['signature']);
        $signature[strlen($signature) - 1] = chr(ord($signature[strlen($signature) - 1]) ^ 0x01);
        $payload['response']['signature'] = SoftwareAuthenticator::b64url($signature);

        return $payload;
    }],
    'signature from a different key' => [function (SoftwareAuthenticator $authenticator, array $options): array {
        $impostor = new SoftwareAuthenticator(SoftwareAuthenticator::b64urlDecode($authenticator->credentialId()));

        return $impostor->assert($options, WEBAUTHN_TEST_ORIGIN);
    }],
    'wrong challenge' => [function (SoftwareAuthenticator $authenticator, array $options): array {
        $options['challenge'] = SoftwareAuthenticator::b64url(random_bytes(16));

        return $authenticator->assert($options, WEBAUTHN_TEST_ORIGIN);
    }],
    'foreign origin' => [function (SoftwareAuthenticator $authenticator, array $options): array {
        return $authenticator->assert($options, 'https://evil.example');
    }],
    'different relying party' => [function (SoftwareAuthenticator $authenticator, array $options): array {
        return $authenticator->assert([...$options, 'rpId' => 'evil.example'], WEBAUTHN_TEST_ORIGIN);
    }],
]);

test('an assertion without a prior challenge is rejected', function () {
    $authenticator = new SoftwareAuthenticator;
    enrolledPasskeyUser($authenticator);

    $options = $this->postJson(route('webauthn.login.options'))->json();
    session()->forget(config('webauthn.challenge.key'));

    $this->postJson(route('webauthn.login'), $authenticator->assert($options, WEBAUTHN_TEST_ORIGIN))
        ->assertStatus(422);

    $this->assertGuest();
});

test('a replayed assertion is rejected because the challenge is single use', function () {
    $authenticator = new SoftwareAuthenticator;
    $user = enrolledPasskeyUser($authenticator);

    $payload = passkeyAssertion($authenticator);
    $this->postJson(route('webauthn.login'), $payload)->assertNoContent();
    $this->assertAuthenticatedAs($user);

    Auth::forgetGuards();
    session()->flush();

    $this->postJson(route('webauthn.login'), $payload)->assertStatus(422);
    $this->assertGuest();
});

test('a cloned authenticator whose counter does not advance is rejected and the credential disabled', function () {
    $authenticator = new SoftwareAuthenticator;
    $user = enrolledPasskeyUser($authenticator);
    $clone = clone $authenticator;

    $this->postJson(route('webauthn.login'), passkeyAssertion($authenticator))->assertNoContent();
    $this->assertAuthenticatedAs($user);

    Auth::forgetGuards();
    session()->flush();

    // The clone signs with the same key but its counter (1) is not above the stored one (1).
    $this->postJson(route('webauthn.login'), passkeyAssertion($clone))->assertStatus(422);
    $this->assertGuest();

    $credential = WebAuthnCredential::sole();
    expect($credential->isDisabled())->toBeTrue();

    // The disabled credential can no longer sign in, even from the genuine authenticator.
    $this->postJson(route('webauthn.login'), passkeyAssertion($authenticator))->assertStatus(422);
    $this->assertGuest();
});

test('authenticators that do not implement a signature counter can sign in repeatedly', function () {
    // Synced passkeys (e.g. iCloud Keychain) always report a zero counter.
    $authenticator = new SoftwareAuthenticator(counts: false);
    $user = enrolledPasskeyUser($authenticator);

    foreach ([1, 2] as $attempt) {
        $this->postJson(route('webauthn.login'), passkeyAssertion($authenticator))->assertNoContent();
        $this->assertAuthenticatedAs($user);

        Auth::forgetGuards();
        session()->flush();
    }

    expect(WebAuthnCredential::sole()->counter)->toBe(0);
});

test('signing in on a new device sends the alert immediately unless the queue switch is on', function (bool $queue) {
    config(['auth.magic_link_queue' => $queue]);
    Mail::fake();
    $authenticator = new SoftwareAuthenticator;
    $user = enrolledPasskeyUser($authenticator);

    $this->postJson(route('webauthn.login'), passkeyAssertion($authenticator))->assertNoContent();

    if ($queue) {
        Mail::assertQueued(NewDeviceLogin::class, fn ($mail) => $mail->hasTo($user->email));
        Mail::assertNotSent(NewDeviceLogin::class);
    } else {
        Mail::assertSent(NewDeviceLogin::class, fn ($mail) => $mail->hasTo($user->email));
        Mail::assertNotQueued(NewDeviceLogin::class);
    }
})->with([
    'no queue worker (default)' => [false],
    'queue worker running' => [true],
]);

test('a new device alert that fails to send does not fail the passkey sign-in', function () {
    $authenticator = new SoftwareAuthenticator;
    $user = enrolledPasskeyUser($authenticator);
    $assertion = passkeyAssertion($authenticator);

    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP down'));
    $this->mock(ExceptionHandler::class, function ($mock) {
        $mock->shouldReceive('report')->once()->with(Mockery::on(fn ($e) => $e->getMessage() === 'SMTP down'));
    });

    $response = $this->postJson(route('webauthn.login'), $assertion)->assertNoContent();

    $this->assertAuthenticatedAs($user);
    $device = TrustedDevice::sole();
    $response->assertCookie('device_token', Crypt::decryptString($device->device_token));
});
