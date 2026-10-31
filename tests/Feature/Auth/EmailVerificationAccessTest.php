<?php

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/*
 * AUTH-010: already-verified users are bounced to the dashboard, and the
 * verification link only works for the signed-in owner with a valid signature.
 */

function verificationUrlFor(User $user, ?DateTimeInterface $expires = null): string
{
    return URL::temporarySignedRoute(
        'verification.verify',
        $expires ?? now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)]
    );
}

test('a verified user opening the prompt or requesting a link is redirected to the dashboard', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('verification.notice'))
        ->assertRedirect(route('dashboard', absolute: false));

    $this->actingAs($user)
        ->post(route('verification.send'))
        ->assertRedirect(route('dashboard', absolute: false));

    Notification::assertNothingSent();
});

test('a guest cannot verify an address with a valid signed link', function () {
    Event::fake([Verified::class]);
    $user = User::factory()->unverified()->create();

    $this->get(verificationUrlFor($user))->assertRedirect(route('login'));

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
    Event::assertNotDispatched(Verified::class);
});

test('an unsigned or expired verification link is rejected', function () {
    Event::fake([Verified::class]);
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get(route('verification.verify', ['id' => $user->id, 'hash' => sha1($user->email)]))
        ->assertForbidden();

    $this->actingAs($user)
        ->get(verificationUrlFor($user, now()->subMinute()))
        ->assertForbidden();

    $tampered = verificationUrlFor($user).'x';
    $this->actingAs($user)->get($tampered)->assertForbidden();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
    Event::assertNotDispatched(Verified::class);
});

test('a signed link for another user does not verify either account', function () {
    $victim = User::factory()->unverified()->create();
    $attacker = User::factory()->unverified()->create();

    $this->actingAs($attacker)
        ->get(verificationUrlFor($victim))
        ->assertForbidden();

    expect($victim->fresh()->hasVerifiedEmail())->toBeFalse()
        ->and($attacker->fresh()->hasVerifiedEmail())->toBeFalse();
});
