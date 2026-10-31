<?php

use App\Models\Budget;
use App\Models\User;
use App\Models\UserPreference;

/*
 * PREF-001 / PREF-002: invalid preference writes are rejected with 422 and nothing
 * is stored; guests get 401.
 */

test('saving an account type order with an unknown type or a non-array is rejected', function (mixed $order, string $field) {
    $user = User::factory()->create();
    $user->setAccountTypeOrder(['savings', 'checking']);

    $this->actingAs($user)
        ->postJson(route('preferences.account-type-order.update'), ['order' => $order])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect($user->fresh()->getAccountTypeOrder())->toBe(['savings', 'checking']);
})->with([
    'unknown type' => [['checking', 'crypto wallet'], 'order.1'],
    'non-array' => ['checking', 'order'],
    'missing' => [null, 'order'],
]);

test('setting the active budget with a missing, non-integer or unknown id is rejected', function (array $payload) {
    $user = User::factory()->create();
    $budget = Budget::factory()->create(['user_id' => $user->id]);
    $user->setActiveBudget($budget->id);

    $this->actingAs($user)
        ->postJson(route('preferences.active-budget.set'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('budget_id');

    expect(UserPreference::getActiveBudgetId($user->id))->toBe($budget->id);
})->with([
    'missing' => [[]],
    'non-integer' => [['budget_id' => 'abc']],
    'non-existent' => [['budget_id' => 999999]],
]);

test('guests cannot read or set the active budget', function () {
    $budget = Budget::factory()->create();

    $this->getJson(route('preferences.active-budget.get'))->assertUnauthorized();
    $this->postJson(route('preferences.active-budget.set'), ['budget_id' => $budget->id])
        ->assertUnauthorized();

    expect(UserPreference::count())->toBe(0);
});

test('the generic preference endpoint rejects keys outside the whitelist', function (string $key) {
    $user = User::factory()->create();
    $budget = Budget::factory()->create(['user_id' => $user->id]);
    $key = str_replace('{budget}', (string) $budget->id, $key);

    $this->actingAs($user)
        ->postJson(route('preferences.update', $key), ['value' => 9999])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('key');

    expect($user->fresh()->getPreference($key))->toBeNull();
})->with([
    'active budget bypass' => ['active_budget_id'],
    'projection months bypass' => ['budget_{budget}_projection_months'],
    'arbitrary key' => ['anything_at_all'],
]);

test('the generic preference endpoint validates whitelisted values', function () {
    $user = User::factory()->create();
    $user->setAccountTypeOrder(['savings', 'checking']);

    $this->actingAs($user)
        ->postJson(route('preferences.update', 'account_type_order'), ['value' => ['checking', 'crypto wallet']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('value.1');

    expect($user->fresh()->getAccountTypeOrder())->toBe(['savings', 'checking']);
});

test('a whitelisted preference written via the generic endpoint is returned by it', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('preferences.update', 'account_type_order'), ['value' => ['checking', 'savings']])
        ->assertOk();

    $this->actingAs($user)
        ->getJson(route('preferences.show', 'account_type_order'))
        ->assertOk()
        ->assertJson(['key' => 'account_type_order', 'value' => ['checking', 'savings']]);
});
