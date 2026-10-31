<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

// TXN-002: the quick-add modal picks a category from the active budget's
// categories, which arrive with the shared activeBudget prop.

test('the active budget shared prop lists its categories for the quick-add picker', function () {
    $user = User::factory()->create();
    $budget = Budget::factory()->create(['user_id' => $user->id]);
    $user->setActiveBudget($budget->id);
    $groceries = Category::create(['budget_id' => $budget->id, 'name' => 'Groceries', 'amount' => 0]);
    Category::create(['budget_id' => Budget::factory()->create()->id, 'name' => 'Not mine', 'amount' => 0]);

    $this->actingAs($user)
        ->get(route('budget.transaction.index', $budget))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('activeBudget.categories', 1)
            ->where('activeBudget.categories.0.id', $groceries->id)
            ->where('activeBudget.categories.0.name', 'Groceries'));
});

test('a quick-add transaction with a picked category is stored linked to it', function () {
    $user = User::factory()->create();
    $budget = Budget::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->create(['budget_id' => $budget->id]);
    $groceries = Category::create(['budget_id' => $budget->id, 'name' => 'Groceries', 'amount' => 0]);

    $this->actingAs($user)
        ->post(route('budget.transaction.store', $budget), [
            'account_id' => $account->id,
            'description' => 'Market',
            'category_id' => $groceries->id,
            'category' => 'Groceries',
            'amount' => '-12.34',
            'date' => now()->toDateString(),
        ])
        ->assertRedirect();

    $transaction = Transaction::where('description', 'Market')->sole();
    expect($transaction->category_id)->toBe($groceries->id)
        ->and($transaction->category)->toBe('Groceries')
        ->and($transaction->amount_in_cents)->toBe(-1234);
});
