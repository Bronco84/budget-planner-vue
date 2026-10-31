<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia;

test('inertia responses share the live csrf token', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('csrf_token', session()->token())
    );
});

test('the shared token follows a session token rotation', function () {
    // Regression: <head> — and therefore the meta[name=csrf-token] tag every
    // fetch() caller reads — is only rendered on a full page load. When the
    // session token rotates (passkey login, magic link, logout) and the app
    // keeps navigating client-side, the page held the dead token and the next
    // write request 419'd. Inertia must hand back the rotated token so the
    // client can re-sync.
    $user = User::factory()->create();

    $this->actingAs($user);
    $tokenBefore = session()->token();

    $this->post(route('logout'))->assertRedirect();

    expect(session()->token())->not->toBe($tokenBefore);

    $this->get(route('login'))->assertInertia(
        fn (AssertableInertia $page) => $page->where('csrf_token', session()->token())
    );
});

test('the csrf token endpoint returns the session token', function () {
    $this->get(route('login'));

    $response = $this->getJson('/csrf-token');

    $response->assertOk();
    $response->assertJson(['csrf_token' => session()->token()]);
});
