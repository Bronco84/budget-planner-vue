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
