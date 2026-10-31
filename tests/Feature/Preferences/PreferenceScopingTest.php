<?php

use App\Models\Budget;
use App\Models\User;
use App\Models\UserPreference;

/*
 * PREF-001: the saved account type order reaches the dashboard (or the defaults), and
 * preferences are always scoped to the authenticated user.
 * PREF-002: the active budget is null without budgets and falls back when the
 * stored budget is gone or no longer accessible.
 */

test('the account type order defaults to the standard types and the dashboard receives the saved order', function () {
    $user = User::factory()->create();
    $budget = Budget::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->get(route('budgets.show', $budget))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('userAccountTypeOrder', User::ACCOUNT_TYPES));

    $order = array_reverse(User::ACCOUNT_TYPES);

    $this->actingAs($user)
        ->postJson(route('preferences.account-type-order.update'), ['order' => $order])
        ->assertOk();

    $this->actingAs($user)
        ->get(route('budgets.show', $budget))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('userAccountTypeOrder', $order));
});

test('preferences are read and written only for the authenticated user', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $aliceOrder = array_reverse(User::ACCOUNT_TYPES);

    $this->actingAs($alice)
        ->postJson(route('preferences.account-type-order.update'), ['order' => $aliceOrder])
        ->assertOk();

    // Bob never sees Alice's order, and his own write leaves hers untouched.
    expect($bob->fresh()->getAccountTypeOrder())->toBe(User::ACCOUNT_TYPES);

    $bobOrder = User::ACCOUNT_TYPES;
    [$bobOrder[0], $bobOrder[1]] = [$bobOrder[1], $bobOrder[0]];

    $this->actingAs($bob)
        ->postJson(route('preferences.update', 'account_type_order'), ['value' => $bobOrder])
        ->assertOk();

    expect($alice->fresh()->getAccountTypeOrder())->toBe($aliceOrder)
        ->and($bob->fresh()->getAccountTypeOrder())->toBe($bobOrder);
});

test('guests cannot read or write preferences', function () {
    $this->postJson(route('preferences.account-type-order.update'), ['order' => User::ACCOUNT_TYPES])
        ->assertUnauthorized();
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
