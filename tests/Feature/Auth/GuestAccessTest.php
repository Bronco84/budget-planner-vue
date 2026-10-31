<?php

use App\Models\Budget;
use App\Models\User;
use Illuminate\Support\Str;

/*
 * AUTH-008 / PROFILE-001: guests are sent to login (HTML) or get 401 (JSON),
 * and the originally requested URL is restored after a magic-link sign-in.
 */

test('guests requesting the dashboard or profile are redirected to login', function (string $method, string $route) {
    $this->call($method, route($route))->assertRedirect(route('login'));

    $this->assertGuest();
})->with([
    'dashboard' => ['GET', 'dashboard'],
    'profile page' => ['GET', 'profile.edit'],
]);

test('a guest json request to an authenticated route gets 401 instead of a redirect', function (string $method, string $route) {
    $this->json($method, route($route))
        ->assertUnauthorized()
        ->assertHeaderMissing('Location');
})->with([
    'active budget' => ['GET', 'preferences.active-budget.get'],
    'account type order' => ['POST', 'preferences.account-type-order.update'],
    'dashboard' => ['GET', 'dashboard'],
]);

test('after a magic link sign in the user is sent to the originally requested url', function () {
    $user = User::factory()->create();
    createPasskey($user);
    $budget = Budget::factory()->create(['user_id' => $user->id]);

    $this->get(route('budgets.show', $budget))->assertRedirect(route('login'));

    $raw = Str::random(64);
    $user->magicLinkTokens()->create([
        'token' => hash('sha256', $raw),
        'expires_at' => now()->addMinutes(15),
    ]);

    $this->get(route('magic-link.authenticate', ['token' => $raw, 'email' => $user->email]))
        ->assertRedirect(route('budgets.show', $budget));

    $this->assertAuthenticatedAs($user);
});

test('guests cannot view or update the profile', function () {
    $user = User::factory()->create(['name' => 'Original Name', 'email' => 'original@example.com']);

    $this->get(route('profile.edit'))->assertRedirect(route('login'));
    $this->patch(route('profile.update'), [
        'name' => 'Hijacked',
        'email' => 'hijacked@example.com',
    ])->assertRedirect(route('login'));

    $fresh = $user->fresh();
    expect($fresh->name)->toBe('Original Name')
        ->and($fresh->email)->toBe('original@example.com');
});
