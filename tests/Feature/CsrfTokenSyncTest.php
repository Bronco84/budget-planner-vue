<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Budget;
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

test('a partial reload still shares the live csrf token', function () {
    // Regression: router.reload({ only: [...] }) sends only the requested props.
    // If csrf_token is left out, Inertia keeps the previous page's (possibly
    // stale) value and app.js writes it back over a freshly refreshed token.
    $user = User::factory()->create();
    $budget = Budget::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->get(route('budgets.show', $budget))->assertOk();

    $version = app(HandleInertiaRequests::class)->version(request());

    $response = $this->withHeaders(array_filter([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $version,
        'X-Inertia-Partial-Component' => 'Budgets/Show',
        'X-Inertia-Partial-Data' => 'environment',
    ]))->get(route('budgets.show', $budget));

    $response->assertOk();
    $props = $response->json('props');
    expect($props)->toHaveKey('environment')
        ->and($props)->not->toHaveKey('activeBudget')
        ->and($props['csrf_token'] ?? null)->toBe(session()->token());
});
