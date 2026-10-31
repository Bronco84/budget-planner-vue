<?php

use App\Mail\MagicLinkEmail;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/*
 * AUTH-003. Magic link request form, throttling, delivery and guest-only access.
 */

function sentMagicLinkUrl(User $user): string
{
    Mail::fake();
    test()->post(route('magic-link.store'), ['email' => $user->email]);

    $url = null;
    Mail::assertSent(MagicLinkEmail::class, function (MagicLinkEmail $mail) use (&$url) {
        $url = $mail->url;

        return true;
    });

    return $url;
}

test('the magic link request page renders for a guest', function () {
    $this->get(route('magic-link.request'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Auth/MagicLinkRequest'));
});

test('magic link requests beyond six per minute are throttled', function () {
    Mail::fake();
    $user = User::factory()->create();

    foreach (range(1, 6) as $i) {
        $this->post(route('magic-link.store'), ['email' => $user->email])->assertRedirect();
    }

    $this->post(route('magic-link.store'), ['email' => $user->email])->assertStatus(429);

    Mail::assertSentCount(6);
});

test('the emailed magic link points at the authenticate route with the raw token and email', function () {
    $user = User::factory()->create();

    $url = sentMagicLinkUrl($user);

    $parts = parse_url($url);
    parse_str($parts['query'], $query);

    expect(Str::before($url, '?'))->toBe(route('magic-link.authenticate'))
        ->and($query['email'])->toBe($user->email)
        ->and(strlen($query['token']))->toBe(64)
        ->and($user->magicLinkTokens()->sole()->token)->toBe(hash('sha256', $query['token']));

    $rendered = (new MagicLinkEmail($user, $url))->render();
    expect($rendered)->toContain(e($url));
});

test('signing in by magic link creates no trusted device and sets no device cookie', function () {
    $user = User::factory()->create();
    createPasskey($user);
    $url = sentMagicLinkUrl($user);

    $this->get($url)
        ->assertRedirect(route('budgets.index'))
        ->assertCookieMissing('device_token');

    $this->assertAuthenticatedAs($user);
    expect(TrustedDevice::count())->toBe(0)
        ->and(session('auth_method'))->toBe('magic_link');
});

test('magic link routes redirect an authenticated user to the dashboard', function () {
    Mail::fake();
    $user = User::factory()->create();
    createPasskey($user);

    $this->actingAs($user)->get(route('magic-link.request'))->assertRedirect(route('dashboard'));
    $this->actingAs($user)->post(route('magic-link.store'), ['email' => $user->email])->assertRedirect(route('dashboard'));
    $this->actingAs($user)
        ->get(route('magic-link.authenticate', ['token' => Str::random(64), 'email' => $user->email]))
        ->assertRedirect(route('dashboard'));

    Mail::assertNothingSent();
    expect($user->magicLinkTokens()->count())->toBe(0);
});
