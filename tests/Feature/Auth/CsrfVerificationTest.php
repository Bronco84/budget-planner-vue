<?php

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

/*
 * AUTH-009: Laravel skips CSRF verification while running unit tests
 * (ValidateCsrfToken::runningUnitTests), so these tests swap in a subclass that
 * always verifies.
 */

beforeEach(function () {
    $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
    {
        protected function runningUnitTests()
        {
            return false;
        }
    });

    $this->user = User::factory()->create();
    $this->user->setAccountTypeOrder(['savings', 'checking']);
});

test('a write with a stale csrf token is rejected with 419', function () {
    $this->actingAs($this->user)
        ->withSession(['_token' => 'live-session-token'])
        ->withHeader('X-CSRF-TOKEN', 'stale-token-from-before-rotation')
        ->postJson(route('preferences.account-type-order.update'), ['order' => ['checking', 'savings']])
        ->assertStatus(419);

    // The live token is still the session's token; the stale one never matched it.
    $this->getJson('/csrf-token')->assertJson(['csrf_token' => 'live-session-token']);

    expect($this->user->fresh()->getAccountTypeOrder())->toBe(['savings', 'checking']);
});

test('a write with the live csrf token passes verification', function () {
    $this->actingAs($this->user)->withSession(['_token' => 'live-session-token']);

    // The client's recovery path: fetch the live token, then replay the write with it.
    $token = $this->getJson('/csrf-token')->assertOk()->json('csrf_token');
    expect($token)->toBe('live-session-token');

    $this->withHeader('X-CSRF-TOKEN', $token)
        ->postJson(route('preferences.account-type-order.update'), ['order' => ['checking', 'savings']])
        ->assertOk();

    expect($this->user->fresh()->getAccountTypeOrder())->toBe(['checking', 'savings']);
});
