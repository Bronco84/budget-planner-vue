<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * BUDGET-002: owner views the budget dashboard and account ledger.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(now()->setDate(2026, 6, 15)->setTime(12, 0));

    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
});

test('owner sees the dashboard with accounts, total balance and the selected account ledger', function () {
    $checking = Account::factory()->checking()->create([
        'budget_id' => $this->budget->id,
        'name' => 'Main Checking',
        'current_balance_cents' => 250000,
    ]);
    Transaction::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $checking->id,
        'description' => 'Upcoming paycheck',
        'amount_in_cents' => 300000,
        // Future-dated: past ledger rows are sourced from the Plaid feed only.
        'date' => '2026-06-20',
    ]);

    $this->actingAs($this->user)
        ->get(route('budgets.show', $this->budget))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Budgets/Show')
            ->where('budget.id', $this->budget->id)
            ->has('accounts', 1)
            ->where('accounts.0.name', 'Main Checking')
            ->where('totalBalance', 250000)
            ->where('selectedAccountId', $checking->id)
            ->where('projectionParams.months', 1)
            ->where('transactions.total', 1)
            ->where('transactions.data.0.description', 'Upcoming paycheck')
            ->has('properties')
            ->has('userAccountTypeOrder'));
});

test('dashboard ledger follows the requested account', function () {
    $checking = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
    $savings = Account::factory()->savings()->create(['budget_id' => $this->budget->id]);
    Transaction::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $savings->id,
        'description' => 'Interest',
        'amount_in_cents' => 1234,
        'date' => '2026-06-25',
    ]);
    Transaction::factory()->count(2)->create([
        'budget_id' => $this->budget->id,
        'account_id' => $checking->id,
        'date' => '2026-06-22',
    ]);

    $this->actingAs($this->user)
        ->get(route('budgets.show', [$this->budget, 'account_id' => $savings->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Budgets/Show')
            ->where('selectedAccountId', $savings->id)
            ->where('transactions.total', 1));
});

test('budget without accounts renders the empty state', function () {
    $this->actingAs($this->user)
        ->get(route('budgets.show', $this->budget))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Budgets/Show')
            ->has('accounts', 0)
            ->where('selectedAccountId', null)
            ->where('transactions', null)
            ->where('totalBalance', 0));
});

test('requested projection months are saved and reused on the next visit', function () {
    Account::factory()->checking()->create(['budget_id' => $this->budget->id]);

    $this->actingAs($this->user)
        ->get(route('budgets.show', [$this->budget, 'projection_months' => 6]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('projectionParams.months', 6));

    expect((int) $this->user->fresh()->getPreference("budget_{$this->budget->id}_projection_months"))->toBe(6);

    $this->actingAs($this->user)
        ->get(route('budgets.show', $this->budget))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('projectionParams.months', 6));
});

test('per page is clamped to the allowed range', function (int $requested, int $expected) {
    Account::factory()->checking()->create(['budget_id' => $this->budget->id]);

    $this->actingAs($this->user)
        ->get(route('budgets.show', [$this->budget, 'per_page' => $requested]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('transactions.per_page', $expected));
})->with([
    'below minimum' => [1, 10],
    'within range' => [50, 50],
    'above maximum' => [1000, 200],
]);

test('an account id that is not in the budget falls back to the default account', function (string $case) {
    $checking = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
    $otherBudget = Budget::factory()->create(['user_id' => $this->user->id]);
    $foreign = Account::factory()->checking()->create(['budget_id' => $otherBudget->id]);

    $accountId = $case === 'other budget' ? $foreign->id : 999999;

    $this->actingAs($this->user)
        ->get(route('budgets.show', [$this->budget, 'account_id' => $accountId]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Budgets/Show')
            ->where('selectedAccountId', $checking->id));
})->with(['other budget', 'nonexistent']);
