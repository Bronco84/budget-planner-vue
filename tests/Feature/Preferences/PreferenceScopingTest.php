<?php

use App\Models\Budget;
use App\Models\User;
use App\Models\UserPreference;

/*
 * PREF-001: the saved account type order is read back (or the defaults), and
 * preferences are always scoped to the authenticated user.
 * PREF-002: the active budget is null without budgets and falls back when the
 * stored budget is gone or no longer accessible.
 */

test('the account type order defaults to the standard types and returns the saved order', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson(route('preferences.account-type-order.get'))
        ->assertOk()
        ->assertExactJson(['order' => User::ACCOUNT_TYPES]);

    $order = array_reverse(User::ACCOUNT_TYPES);

    $this->actingAs($user)
        ->postJson(route('preferences.account-type-order.update'), ['order' => $order])
        ->assertOk();

    $this->actingAs($user)
        ->getJson(route('preferences.account-type-order.get'))
        ->assertOk()
        ->assertExactJson(['order' => $order]);
});

test('preferences are read and written only for the authenticated user', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $aliceOrder = array_reverse(User::ACCOUNT_TYPES);

    $this->actingAs($alice)
        ->postJson(route('preferences.account-type-order.update'), ['order' => $aliceOrder])
        ->assertOk();

    // Bob never sees Alice's order, and his own write leaves hers untouched.
    $this->actingAs($bob)
        ->getJson(route('preferences.account-type-order.get'))
        ->assertExactJson(['order' => User::ACCOUNT_TYPES]);

    $this->actingAs($bob)
        ->getJson(route('preferences.show', 'account_type_order'))
        ->assertJsonPath('value', null);

    $bobOrder = User::ACCOUNT_TYPES;
    [$bobOrder[0], $bobOrder[1]] = [$bobOrder[1], $bobOrder[0]];

    $this->actingAs($bob)
        ->postJson(route('preferences.update', 'account_type_order'), ['value' => $bobOrder])
        ->assertOk();

    expect($alice->fresh()->getAccountTypeOrder())->toBe($aliceOrder)
        ->and($bob->fresh()->getAccountTypeOrder())->toBe($bobOrder);
});

test('guests cannot read or write preferences', function () {
    $this->getJson(route('preferences.account-type-order.get'))->assertUnauthorized();
    $this->postJson(route('preferences.account-type-order.update'), ['order' => User::ACCOUNT_TYPES])
        ->assertUnauthorized();
    $this->getJson(route('preferences.show', 'account_type_order'))->assertUnauthorized();
    $this->postJson(route('preferences.update', 'account_type_order'), ['value' => User::ACCOUNT_TYPES])
        ->assertUnauthorized();

    expect(UserPreference::count())->toBe(0);
});

test('the active budget is null when the user has no budgets', function () {
    Budget::factory()->create(); // someone else's budget

    $this->actingAs(User::factory()->create())
        ->getJson(route('preferences.active-budget.get'))
        ->assertOk()
        ->assertJson(['active_budget' => null]);
});

test('a deleted active budget is cleared and the first accessible budget is used', function () {
    $user = User::factory()->create();
    $first = Budget::factory()->create(['user_id' => $user->id]);
    $stale = Budget::factory()->create(['user_id' => $user->id]);
    $user->setActiveBudget($stale->id);

    $stale->delete();

    $this->actingAs($user)
        ->getJson(route('preferences.active-budget.get'))
        ->assertOk()
        ->assertJsonPath('active_budget.id', $first->id);

    expect(UserPreference::getActiveBudgetId($user->id))->toBeNull();
});

test('an active budget whose share was revoked is cleared and the first accessible budget is used', function () {
    $user = User::factory()->create();
    $own = Budget::factory()->create(['user_id' => $user->id]);
    $shared = Budget::factory()->create();
    $user->linked_budgets()->attach($shared);
    $user->setActiveBudget($shared->id);
    expect(UserPreference::getActiveBudgetId($user->id))->toBe($shared->id);

    $user->linked_budgets()->detach($shared);

    $this->actingAs($user->fresh())
        ->getJson(route('preferences.active-budget.get'))
        ->assertOk()
        ->assertJsonPath('active_budget.id', $own->id);

    expect(UserPreference::getActiveBudgetId($user->id))->toBeNull();
});
