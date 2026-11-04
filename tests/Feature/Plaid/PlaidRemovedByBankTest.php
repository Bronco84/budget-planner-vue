<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\PlaidAccount;
use App\Models\PlaidConnection;
use App\Models\PlaidTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BudgetService;
use Tests\Support\FakePlaidApi;

/*
 * PLAID-003: /transactions/get never reports removals, so after a complete fetch a sync
 * marks imported rows whose raw Plaid copy is still pending and whose id Plaid no longer
 * returns as removed by the bank. They stay listed, are left out of balances, totals and
 * projections, and the user deletes or keeps them (budget.transaction.keep).
 */

beforeEach(function () {
    $this->travelTo(Carbon\Carbon::parse('2026-03-15 10:00:00'));

    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->account = Account::factory()->create([
        'budget_id' => $this->budget->id, 'type' => 'checking', 'current_balance_cents' => 100000,
    ]);
    $connection = PlaidConnection::create([
        'budget_id' => $this->budget->id, 'plaid_item_id' => 'item-checking', 'institution_name' => 'First Platypus Bank',
        'access_token' => 'access-sandbox-sync', 'status' => PlaidConnection::STATUS_ACTIVE,
    ]);
    PlaidAccount::create([
        'plaid_connection_id' => $connection->id, 'account_id' => $this->account->id, 'plaid_account_id' => 'plaid-checking',
        'account_name' => 'Checking', 'account_type' => 'depository', 'account_subtype' => 'checking',
        'current_balance_cents' => 100000,
    ]);
    $this->plaid = FakePlaidApi::install();
    $this->plaid->on('/accounts/get', ['accounts' => [[
        'account_id' => 'plaid-checking', 'name' => 'Checking', 'type' => 'depository', 'subtype' => 'checking',
        'balances' => ['current' => 1000.00, 'available' => 1000.00],
    ]]]);

    // An imported transaction and its raw Plaid copy.
    $this->imported = function (string $plaidId, int $cents, string $date, bool $pending, array $attributes = []) {
        PlaidTransaction::create([
            'plaid_transaction_id' => $plaidId, 'account_id' => $this->account->id, 'plaid_account_id' => 'plaid-checking',
            'pending' => $pending, 'amount' => -$cents / 100, 'date' => $date, 'name' => strtoupper($plaidId),
        ]);

        return Transaction::create([
            'budget_id' => $this->budget->id, 'account_id' => $this->account->id, 'description' => strtoupper($plaidId),
            'category' => 'Uncategorized', 'amount_in_cents' => $cents, 'date' => $date,
            'plaid_transaction_id' => $plaidId, 'is_plaid_imported' => true, ...$attributes,
        ]);
    };

    $this->coffee = [
        'transaction_id' => 'tx-coffee', 'account_id' => 'plaid-checking', 'amount' => 4.00,
        'date' => '2026-03-14', 'name' => 'TX-COFFEE', 'pending' => true,
    ];

    $this->sync = fn () => $this->actingAs($this->user)
        ->post(route('plaid.sync', [$this->budget, $this->account]))
        ->assertSessionHasNoErrors();
});

test('a pending transaction Plaid stops returning is marked removed by the bank and left out of the balance', function () {
    $hold = ($this->imported)('tx-hotel-hold', -20000, '2026-03-12', true);
    $posted = ($this->imported)('tx-posted-gone', -1500, '2026-03-10', false);
    $outsideWindow = ($this->imported)('tx-ancient-hold', -700, '2025-08-01', true);
    $coffee = ($this->imported)('tx-coffee', -400, '2026-03-14', true);

    $this->plaid->on('/transactions/get', ['transactions' => [$this->coffee], 'total_transactions' => 1]);
    ($this->sync)()->assertSessionHas('message');

    expect($hold->fresh()->removed_by_bank_at)->not->toBeNull()
        ->and($posted->fresh()->removed_by_bank_at)->toBeNull()
        ->and($outsideWindow->fresh()->removed_by_bank_at)->toBeNull()
        ->and($coffee->fresh()->removed_by_bank_at)->toBeNull();

    // Still listed on the account, but it does not move the running balance; the
    // unmarked pending rows (coffee, and the hold outside the fetched window) still do.
    $service = app(BudgetService::class);
    $account = $this->account->fresh();
    $listed = $service->calculateRunningBalances(
        $service->getAccountTransactions($account, now()->subDays(90), now()->addMonth(), 1),
        $account
    );

    expect($listed->firstWhere('id', $hold->id)->running_balance)->toBeNull()
        ->and($listed->firstWhere('id', $coffee->id)->running_balance)->toBe(100000 - 700 - 400);
});

test('a transaction removed by the bank is left out of category spending', function () {
    $groceries = Category::create(['budget_id' => $this->budget->id, 'name' => 'Groceries', 'amount' => 500]);
    ($this->imported)('tx-groceries', -5000, '2026-03-11', false, ['category_id' => $groceries->id]);
    ($this->imported)('tx-voided', -20000, '2026-03-12', true, ['category_id' => $groceries->id]);

    expect($groceries->fresh()->remaining_amount)->toBe(250.0);

    $this->plaid->on('/transactions/get', ['transactions' => [[
        'transaction_id' => 'tx-groceries', 'account_id' => 'plaid-checking', 'amount' => 50.00,
        'date' => '2026-03-11', 'name' => 'TX-GROCERIES', 'pending' => false,
    ]], 'total_transactions' => 1]);
    ($this->sync)();

    expect($groceries->fresh()->remaining_amount)->toBe(450.0);
});

test('nothing is marked after an incomplete, empty or failed fetch', function (mixed $response) {
    $hold = ($this->imported)('tx-hotel-hold', -20000, '2026-03-12', true);

    $this->plaid->on('/transactions/get', $response);
    ($this->sync)();

    expect($hold->fresh()->removed_by_bank_at)->toBeNull();
})->with([
    // Plaid reports more transactions than it hands over.
    'incomplete' => [fn () => fn (array $body) => ($body['options']['offset'] ?? 0) === 0
        ? ['transactions' => [test()->coffee], 'total_transactions' => 3]
        : ['transactions' => [], 'total_transactions' => 3]],
    'empty' => [['transactions' => [], 'total_transactions' => 0]],
    'failed' => [fn () => FakePlaidApi::error('INTERNAL_SERVER_ERROR', 'boom', 500)],
]);

test('keeping a transaction counts it again and later syncs never mark it again', function () {
    $hold = ($this->imported)('tx-hotel-hold', -20000, '2026-03-12', true, ['removed_by_bank_at' => now()]);
    ($this->imported)('tx-coffee', -400, '2026-03-14', true);

    $this->actingAs($this->user)
        ->from(route('budget.transaction.index', $this->budget))
        ->patch(route('budget.transaction.keep', [$this->budget, $hold]))
        ->assertRedirect(route('budget.transaction.index', $this->budget))
        ->assertSessionHas('message', 'Transaction kept. It counts in your totals again.');

    expect($hold->fresh())
        ->removed_by_bank_at->toBeNull()
        ->bank_removal_kept_at->not->toBeNull();

    $this->plaid->on('/transactions/get', ['transactions' => [$this->coffee], 'total_transactions' => 1]);
    ($this->sync)();

    expect($hold->fresh()->removed_by_bank_at)->toBeNull();
});

test('a transaction Plaid returns again is no longer marked as removed', function () {
    $hold = ($this->imported)('tx-coffee', -400, '2026-03-14', true, ['removed_by_bank_at' => now()]);

    $this->plaid->on('/transactions/get', ['transactions' => [$this->coffee], 'total_transactions' => 1]);
    ($this->sync)();

    expect($hold->fresh()->removed_by_bank_at)->toBeNull();
});

test('a viewer cannot keep a transaction removed by the bank, and another budget cannot reach it', function () {
    $hold = ($this->imported)('tx-hotel-hold', -20000, '2026-03-12', true, ['removed_by_bank_at' => now()]);

    $viewer = User::factory()->create();
    $this->budget->connected_users()->attach($viewer->id, ['access_level' => 'viewer']);
    $this->actingAs($viewer)
        ->patch(route('budget.transaction.keep', [$this->budget, $hold]))
        ->assertForbidden();

    $otherBudget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->actingAs($this->user)
        ->patch(route('budget.transaction.keep', [$otherBudget, $hold]))
        ->assertNotFound();

    expect($hold->fresh()->removed_by_bank_at)->not->toBeNull();
});
