<?php

use App\Models\Account;
use App\Models\Budget;
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
