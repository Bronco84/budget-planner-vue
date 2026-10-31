<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;

/*
 * CATEGORY-002 / CATEGORY-003: a category in use cannot be deleted, and malformed
 * reorder payloads are rejected without changing any order.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->category = Category::create([
        'budget_id' => $this->budget->id,
        'name' => 'Groceries',
        'amount' => 400,
        'order' => 3,
    ]);
});

test('deleting a category that transactions use is blocked with an error flash', function () {
    $account = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
    Transaction::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $account->id,
        'category' => 'Groceries',
    ]);

    $this->actingAs($this->user)
        ->from(route('budgets.categories.index', $this->budget))
        ->delete(route('budgets.categories.destroy', [$this->budget, $this->category]))
        ->assertRedirect(route('budgets.categories.index', $this->budget))
        ->assertSessionHas('error');

    expect(Category::find($this->category->id))->not->toBeNull();
});

test('reordering categories with a malformed payload is rejected', function (array $payload, string $field) {
    $payload = json_decode(str_replace('__ID__', (string) $this->category->id, json_encode($payload)), true);

    $this->actingAs($this->user)
        ->post(route('budgets.categories.reorder', $this->budget), $payload)
        ->assertSessionHasErrors($field);

    expect($this->category->fresh()->order)->toBe(3);
})->with([
    'missing categories' => [[], 'categories'],
    'categories not an array' => [['categories' => 'nope'], 'categories'],
    'negative order' => [['categories' => [['id' => '__ID__', 'order' => -1]]], 'categories.0.order'],
    'missing order' => [['categories' => [['id' => '__ID__']]], 'categories.0.order'],
    'unknown id' => [['categories' => [['id' => 999999, 'order' => 0]]], 'categories.0.id'],
]);
