<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\RecurringTransactionTemplate;
use App\Models\Scenario;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;

/*
 * Regression tests for nested {budget}/{account} routes that previously resolved
 * models via route-model binding without verifying the authenticated user owns
 * them (IDOR). Each route must reject a second user with 403 (or 404 when a nested
 * model does not belong to the budget in the route).
 */

beforeEach(function () {
    $this->user1 = User::factory()->create();
    $this->user2 = User::factory()->create();

    $this->budget1 = Budget::factory()->create(['user_id' => $this->user1->id]);
    $this->budget2 = Budget::factory()->create(['user_id' => $this->user2->id]);

    $this->account1 = Account::factory()->create(['budget_id' => $this->budget1->id]);
    $this->account2 = Account::factory()->create(['budget_id' => $this->budget2->id]);
});

test('user cannot read another users plaid statement history', function () {
    $this->actingAs($this->user1)
        ->get(route('plaid.statement-history', [$this->budget2, $this->account2]))
        ->assertForbidden();
});

test('statement history rejects an account that does not belong to the budget', function () {
    $this->actingAs($this->user1)
        ->get(route('plaid.statement-history', [$this->budget1, $this->account2]))
        ->assertNotFound();
});

test('user cannot update liabilities on another users account', function () {
    $this->actingAs($this->user1)
        ->post(route('plaid.liabilities', [$this->budget2, $this->account2]))
        ->assertForbidden();
});

test('user cannot view another users multi-account projection', function () {
    $this->actingAs($this->user1)
        ->get(route('budget.projections.multi-account', $this->budget2))
        ->assertForbidden();
});

test('user cannot reorder another users categories', function () {
    $this->actingAs($this->user1)
        ->post(route('budgets.categories.reorder', $this->budget2), [
            'categories' => [['id' => 1, 'order' => 0]],
        ])
        ->assertForbidden();
});

test('user cannot sync all transactions on another users budget', function () {
    $this->actingAs($this->user1)
        ->post(route('plaid.sync-all', $this->budget2))
        ->assertForbidden();
});

test('user cannot open another users recurring transaction analysis', function () {
    $this->actingAs($this->user1)
        ->get(route('recurring-transactions.analysis', $this->budget2))
        ->assertForbidden();
});

test('user cannot analyze another users transactions', function () {
    $this->actingAs($this->user1)
        ->post(route('recurring-transactions.analysis.analyze', $this->budget2), [
            'account_id' => $this->account2->id,
        ])
        ->assertForbidden();
});

test('user cannot view another users monthly statistics', function () {
    $this->actingAs($this->user1)
        ->get(route('budget.statistics.monthly', $this->budget2))
        ->assertForbidden();
});

test('user cannot view another users yearly statistics', function () {
    $this->actingAs($this->user1)
        ->get(route('budget.statistics.yearly', $this->budget2))
        ->assertForbidden();
});

test('user cannot view another users budget via calendar budget_id param', function () {
    $this->actingAs($this->user1)
        ->get(route('calendar.index', ['budget_id' => $this->budget2->id]))
        ->assertForbidden();
});

// --- Plaid account-scoped routes: cross-budget account must 404 (own budget, other's account) ---

dataset('plaid_post_routes', [
    'plaid.sync',
    'plaid.balance',
    'plaid.liabilities',
    'plaid.investments',
    'plaid.upgrade-link-token',
    'plaid.update-connection',
]);

test('plaid POST route rejects a cross-budget account with 404', function (string $routeName) {
    $this->actingAs($this->user1)
        ->post(route($routeName, [$this->budget1, $this->account2]))
        ->assertNotFound();
})->with('plaid_post_routes');

test('plaid POST route rejects another users budget+account with 403', function (string $routeName) {
    $this->actingAs($this->user1)
        ->post(route($routeName, [$this->budget2, $this->account2]))
        ->assertForbidden();
})->with('plaid_post_routes');

test('plaid link form rejects a cross-budget account with 404', function () {
    $this->actingAs($this->user1)
        ->get(route('plaid.link', [$this->budget1, $this->account2]))
        ->assertNotFound();
});

test('plaid destroy rejects a cross-budget account with 404', function () {
    $this->actingAs($this->user1)
        ->delete(route('plaid.destroy', [$this->budget1, $this->account2]))
        ->assertNotFound();
});

test('account projections reject a cross-budget account with 404', function () {
    $this->actingAs($this->user1)
        ->get(route('budget.account.projections', [$this->budget1, $this->account2]))
        ->assertNotFound();
});

test('account projections reject another users budget+account with 403', function () {
    $this->actingAs($this->user1)
        ->get(route('budget.account.projections', [$this->budget2, $this->account2]))
        ->assertForbidden();
});

// --- Recurring-transaction template routes: cross-budget template must 404 ---

test('recurring transaction routes reject a cross-budget template with 404', function () {
    $template2 = RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget2->id,
        'account_id' => $this->account2->id,
    ]);

    $this->actingAs($this->user1)
        ->get(route('recurring-transactions.edit', [$this->budget1, $template2]))
        ->assertNotFound();

    $this->actingAs($this->user1)
        ->delete(route('recurring-transactions.destroy', [$this->budget1, $template2]))
        ->assertNotFound();

    $this->actingAs($this->user1)
        ->get(route('recurring-transactions.rules.index', [$this->budget1, $template2]))
        ->assertNotFound();
});

test('recurring transaction template routes reject another users budget with 403', function () {
    $template2 = RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget2->id,
        'account_id' => $this->account2->id,
    ]);

    $this->actingAs($this->user1)
        ->get(route('recurring-transactions.edit', [$this->budget2, $template2]))
        ->assertForbidden();
});

// --- Scoped validation: cannot reference another budget's account id in a transaction ---

test('cannot store a transaction referencing another budgets account', function () {
    $this->actingAs($this->user1)
        ->post(route('budget.transaction.store', $this->budget1), [
            'account_id' => $this->account2->id,
            'description' => 'Sneaky',
            'amount' => 10,
            'date' => now()->toDateString(),
            'category' => 'Other',
        ])
        ->assertSessionHasErrors('account_id');
});

// --- Scoped route bindings: cross-budget children must 404 (own budget URL,
//     other budget's child). These lock in the scopeBindings() behavior that
//     replaced the per-controller inline ownership checks. ---

test('transaction routes reject a cross-budget transaction with 404', function () {
    $transaction = Transaction::factory()->create([
        'budget_id' => $this->budget2->id,
        'account_id' => $this->account2->id,
    ]);

    $this->actingAs($this->user1)
        ->get(route('budget.transaction.edit', [$this->budget1, $transaction]))
        ->assertNotFound();

    $this->actingAs($this->user1)
        ->delete(route('budget.transaction.destroy', [$this->budget1, $transaction]))
        ->assertNotFound();
});

test('scenario routes reject a cross-budget scenario with 404', function () {
    $scenario = Scenario::factory()->create([
        'budget_id' => $this->budget2->id,
        'user_id' => $this->user2->id,
    ]);

    $this->actingAs($this->user1)
        ->get(route('budgets.scenarios.show', [$this->budget1, $scenario]))
        ->assertNotFound();
});

test('account resource routes reject a cross-budget account with 404', function () {
    $this->actingAs($this->user1)
        ->get(route('budgets.accounts.edit', [$this->budget1, $this->account2]))
        ->assertNotFound();
});

test('transfer routes reject a cross-budget transfer with 404', function () {
    // Exercises the newly added Budget::transfers() relationship used by scoped binding.
    $transfer = Transfer::create([
        'budget_id' => $this->budget2->id,
        'from_account_id' => $this->account2->id,
        'to_account_id' => $this->account2->id,
        'amount_in_cents' => 1000,
        'date' => now()->toDateString(),
        'description' => 'x',
    ]);

    $this->actingAs($this->user1)
        ->get(route('budget.transfers.show', [$this->budget1, $transfer]))
        ->assertNotFound();
});

test('owner passes authorization on their own nested route', function () {
    // The statement-history endpoint returns JSON (no Vite view render). For the
    // owner, authorization and the account-ownership check both pass, so the request
    // reaches the controller body and returns 404 ("not linked to Plaid") rather than
    // the 403 a non-owner would receive.
    $this->actingAs($this->user1)
        ->get(route('plaid.statement-history', [$this->budget1, $this->account1]))
        ->assertNotFound()
        ->assertJson(['error' => 'Account is not linked to Plaid.']);
});
