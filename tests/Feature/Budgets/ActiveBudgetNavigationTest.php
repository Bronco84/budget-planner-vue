<?php

use App\Models\Budget;
use App\Models\User;
use App\Models\UserPreference;

/*
 * BUDGET-003: entry points resolve the active budget; the switcher sets it.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('budgets index redirects to the active budget dashboard', function () {
    Budget::factory()->create(['user_id' => $this->user->id]);
    $active = Budget::factory()->create(['user_id' => $this->user->id]);
    UserPreference::setActiveBudgetId($this->user->id, $active->id);

    $this->actingAs($this->user)
        ->get(route('budgets.index'))
        ->assertRedirect(route('budgets.show', $active));
});

test('transactions shortcut redirects to the active budget transaction list', function () {
    Budget::factory()->create(['user_id' => $this->user->id]);
    $active = Budget::factory()->create(['user_id' => $this->user->id]);
    UserPreference::setActiveBudgetId($this->user->id, $active->id);

    $this->actingAs($this->user)
        ->get(route('transactions.index'))
        ->assertRedirect(route('budget.transaction.index', $active->id));
});

test('a user with no budgets is redirected from budgets and transactions to the create form', function () {
    $this->actingAs($this->user)
        ->get(route('budgets.index'))
        ->assertRedirect(route('budgets.create'));

    $this->actingAs($this->user)
        ->get(route('transactions.index'))
        ->assertRedirect(route('budgets.create'));
});

test('a stale active budget id is cleared and the first remaining budget is used', function () {
    $remaining = Budget::factory()->create(['user_id' => $this->user->id]);
    $deleted = Budget::factory()->create(['user_id' => $this->user->id]);
    UserPreference::setActiveBudgetId($this->user->id, $deleted->id);
    $deleted->delete();

    $this->actingAs($this->user)
        ->get(route('budgets.index'))
        ->assertRedirect(route('budgets.show', $remaining));

    expect(UserPreference::getActiveBudgetId($this->user->id))->toBeNull();
});

test('posting a new active budget updates the preference and returns it', function () {
    $first = Budget::factory()->create(['user_id' => $this->user->id]);
    $second = Budget::factory()->create(['user_id' => $this->user->id, 'name' => 'Second budget']);
    UserPreference::setActiveBudgetId($this->user->id, $first->id);

    $this->actingAs($this->user)
        ->postJson(route('preferences.active-budget.set'), ['budget_id' => $second->id])
        ->assertOk()
        ->assertJsonPath('active_budget.id', $second->id)
        ->assertJsonPath('active_budget.name', 'Second budget');

    expect(UserPreference::getActiveBudgetId($this->user->id))->toBe($second->id);
});

test('setting another users budget as active fails validation and leaves the preference unchanged', function () {
    $own = Budget::factory()->create(['user_id' => $this->user->id]);
    UserPreference::setActiveBudgetId($this->user->id, $own->id);
    $foreign = Budget::factory()->create(['user_id' => User::factory()->create()->id]);

    $this->actingAs($this->user)
        ->postJson(route('preferences.active-budget.set'), ['budget_id' => $foreign->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['budget_id' => 'You do not have access to this budget.']);

    expect(UserPreference::getActiveBudgetId($this->user->id))->toBe($own->id);
});
