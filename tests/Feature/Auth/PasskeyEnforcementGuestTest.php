<?php

use App\Http\Middleware\EnsureUserHasPasskey;

/*
 * AUTH-007. Guests pass through passkey enforcement untouched; the auth and
 * guest guards decide what happens to them.
 */

beforeEach(function () {
    $this->withMiddleware(EnsureUserHasPasskey::class);
});

test('guests are not redirected to passkey registration by the enforcement middleware', function () {
    $this->get(route('login'))->assertOk();
    $this->get(route('register'))->assertOk();

    $this->get(route('budgets.index'))->assertRedirect(route('login'));
    $this->getJson('/api/preferences/active-budget')->assertUnauthorized();
});
