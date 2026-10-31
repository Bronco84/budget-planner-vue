<?php

use App\Http\Middleware\EnsureUserHasPasskey;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

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

test('the passkey registration page receives the enforcement error so it can be shown', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->followingRedirects()
        ->get(route('budgets.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Auth/PasskeyRegister')
            ->where('flash.error', 'You must register a passkey to continue.'));
});

test('the passkey registration page receives the account created status so it can be shown', function () {
    $this->followingRedirects()
        ->post(route('register'), ['name' => 'Owner', 'email' => 'bamccoley@gmail.com'])
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Auth/PasskeyRegister')
            ->where('flash.status', 'Account created! Now register your passkey.'));
});

test('a json request from a user without a passkey gets a 409 with the registration url', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson(route('budgets.index'))
        ->assertStatus(409)
        ->assertExactJson([
            'message' => 'You must register a passkey to continue.',
            'redirect' => route('passkey.register'),
        ]);
});

test('an inertia request from a user without a passkey is still redirected', function () {
    $user = User::factory()->create();

    $version = app(HandleInertiaRequests::class)->version(request());

    $this->actingAs($user)
        ->get(route('budgets.index'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => 'text/html, application/xhtml+xml',
        ])
        ->assertRedirect(route('passkey.register'));
});

test('a user without a passkey can still reach the email verification routes', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get(route('verification.notice'))
        ->assertOk();

    $this->actingAs($user)
        ->post(route('verification.send'))
        ->assertRedirect()
        ->assertSessionHas('status', 'verification-link-sent');

    Notification::assertSentTo($user, VerifyEmail::class);
});

test('adding a passkey from the profile returns to the profile afterwards', function () {
    $user = User::factory()->create();
    createPasskey($user);

    $this->actingAs($user)
        ->get(route('passkey.register', ['return' => 'profile']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Auth/PasskeyRegister')
            ->where('redirectTo', route('profile.edit')));
});

test('first passkey enrolment and unknown return values go to the budgets page afterwards', function (bool $hasPasskey, ?string $return) {
    $user = User::factory()->create();
    if ($hasPasskey) {
        createPasskey($user);
    }

    $this->actingAs($user)
        ->get(route('passkey.register', array_filter(['return' => $return])))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('redirectTo', route('budgets.index')));
})->with([
    'first passkey without return' => [false, null],
    'first passkey from profile link' => [false, 'profile'],
    'external return value' => [true, 'https://evil.example'],
]);
