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
