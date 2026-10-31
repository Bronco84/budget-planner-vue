<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\PlaidAccount;
use App\Models\PlaidConnection;
use App\Models\PlaidStatementHistory;
use App\Models\PlaidTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PlaidService;
use Tests\Support\FakePlaidApi;

/*
 * PLAID-003: sync one Plaid-linked account (transactions + balance, liabilities and
 * holdings refresh) and the refresh service methods sync uses. Plaid is faked at the Guzzle
 * boundary by Tests\Support\FakePlaidApi.
 */

beforeEach(function () {
    $this->travelTo(Carbon\Carbon::parse('2026-03-15 10:00:00'));

    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->plaid = FakePlaidApi::install();
});

/**
 * Create a local account linked to Plaid under an active connection.
 */
function plaidSyncLinkedAccount(Budget $budget, string $type, string $subtype, string $localType, int $balanceCents = 100000): PlaidAccount
{
    $account = Account::factory()->create([
        'budget_id' => $budget->id,
        'type' => $localType,
        'current_balance_cents' => $balanceCents,
    ]);

    $connection = PlaidConnection::create([
        'budget_id' => $budget->id,
        'plaid_item_id' => 'item-'.$subtype,
        'institution_name' => 'First Platypus Bank',
        'access_token' => 'access-sandbox-sync',
        'status' => PlaidConnection::STATUS_ACTIVE,
    ]);

    return PlaidAccount::create([
        'plaid_connection_id' => $connection->id,
        'account_id' => $account->id,
        'plaid_account_id' => 'plaid-'.str_replace(' ', '-', $subtype),
        'account_name' => ucfirst($subtype),
        'account_type' => $type,
        'account_subtype' => $subtype,
        'current_balance_cents' => $balanceCents,
    ]);
}

function plaidSyncAccountsResponse(string $plaidAccountId, float $current, ?float $available = null, ?float $limit = null): array
{
    return ['accounts' => [[
        'account_id' => $plaidAccountId, 'name' => 'Account', 'type' => 'depository', 'subtype' => 'checking',
        'balances' => ['current' => $current, 'available' => $available, 'limit' => $limit],
    ]]];
}

test('syncing a linked account imports new and updates existing transactions', function () {
    $plaidAccount = plaidSyncLinkedAccount($this->budget, 'depository', 'checking', 'checking');
    $account = $plaidAccount->account;

    $existing = Transaction::create([
        'budget_id' => $this->budget->id,
        'account_id' => $account->id,
        'description' => 'OLD NAME',
        'category' => 'Uncategorized',
        'amount_in_cents' => -1000,
        'date' => '2026-03-01',
        'plaid_transaction_id' => 'tx-existing',
        'is_plaid_imported' => true,
    ]);

    $this->plaid
        ->on('/transactions/get', ['transactions' => [
            [
                'transaction_id' => 'tx-existing', 'account_id' => 'plaid-checking', 'amount' => 12.34,
                'date' => '2026-03-02', 'name' => 'Corner Hardware', 'pending' => false, 'category' => ['Shops'],
            ],
            [
                'transaction_id' => 'tx-paycheck', 'account_id' => 'plaid-checking', 'amount' => -2500.00,
                'date' => '2026-03-13', 'name' => 'ACME PAYROLL', 'pending' => false, 'category' => ['Transfer'],
            ],
            [
                'transaction_id' => 'tx-other-account', 'account_id' => 'plaid-some-other', 'amount' => 99.00,
                'date' => '2026-03-10', 'name' => 'Not Mine', 'pending' => false,
            ],
        ]])
        ->on('/accounts/get', plaidSyncAccountsResponse('plaid-checking', 3487.66, 3400.00));

    $this->actingAs($this->user)
        ->from(route('plaid.link', [$this->budget, $account]))
        ->post(route('plaid.sync', [$this->budget, $account]))
        ->assertRedirect(route('plaid.link', [$this->budget, $account]))
        ->assertSessionHas('message', 'Synced 1 new and updated 1 transactions.');

    // 180 days of history up to today, for this connection's access token.
    expect($this->plaid->bodyFor('/transactions/get'))->toBe([
        'access_token' => 'access-sandbox-sync',
        'start_date' => '2025-09-16',
        'end_date' => '2026-03-15',
    ])->and($this->plaid->unexpected)->toBe([]);

    expect($existing->fresh())
        ->description->toBe('Corner Hardware')
        ->category->toBe('Shops')
        ->amount_in_cents->toBe(-1234)
        ->and($existing->fresh()->date->toDateString())->toBe('2026-03-02');

    $paycheck = Transaction::where('plaid_transaction_id', 'tx-paycheck')->sole();
    expect($paycheck)
        ->budget_id->toBe($this->budget->id)
        ->account_id->toBe($account->id)
        ->description->toBe('ACME PAYROLL')
        ->amount_in_cents->toBe(250000)
        ->is_plaid_imported->toBeTrue();

    expect(Transaction::where('account_id', $account->id)->count())->toBe(2)
        ->and(Transaction::where('plaid_transaction_id', 'tx-other-account')->exists())->toBeFalse()
        ->and(PlaidTransaction::where('account_id', $account->id)->pluck('plaid_transaction_id')->sort()->values()->all())
        ->toBe(['tx-existing', 'tx-paycheck']);

    // Balance refreshed and the connection marked as synced.
    expect($plaidAccount->fresh())
        ->current_balance_cents->toBe(348766)
        ->available_balance_cents->toBe(340000)
        ->and($account->fresh()->current_balance_cents)->toBe(348766)
        ->and($plaidAccount->plaidConnection->fresh()->last_sync_at->toDateTimeString())->toBe('2026-03-15 10:00:00');
});

test('syncing a credit card also refreshes its statement liability data', function () {
    $plaidAccount = plaidSyncLinkedAccount($this->budget, 'credit', 'credit card', 'credit card');

    $this->plaid
        ->on('/transactions/get', ['transactions' => []])
        ->on('/accounts/get', plaidSyncAccountsResponse('plaid-credit-card', 812.40, 4187.60, 5000.00))
        ->on('/liabilities/get', ['liabilities' => ['credit' => [[
            'account_id' => 'plaid-credit-card',
            'last_statement_balance' => 645.20,
            'last_statement_issue_date' => '2026-02-28',
            'last_payment_amount' => 300.00,
            'last_payment_date' => '2026-02-20',
            'next_payment_due_date' => '2026-03-25',
            'minimum_payment_amount' => 35.00,
            'aprs' => [
                ['apr_type' => 'cash_apr', 'apr_percentage' => 29.99],
                ['apr_type' => 'purchase_apr', 'apr_percentage' => 21.24],
            ],
        ]]]]);

    $this->actingAs($this->user)
        ->post(route('plaid.sync', [$this->budget, $plaidAccount->account]))
        ->assertSessionHas('message', 'Synced 0 new and updated 0 transactions.');

    expect($this->plaid->paths())->toContain('/liabilities/get')
        ->and($this->plaid->unexpected)->toBe([]);

    $fresh = $plaidAccount->fresh();
    expect($fresh->current_balance_cents)->toBe(81240)
        ->and($fresh->last_statement_balance_cents)->toBe(64520)
        ->and($fresh->last_statement_issue_date->toDateString())->toBe('2026-02-28')
        ->and($fresh->next_payment_due_date->toDateString())->toBe('2026-03-25')
        ->and($fresh->minimum_payment_amount_cents)->toBe(3500)
        ->and($fresh->last_payment_amount_cents)->toBe(30000)
        ->and($fresh->apr_percentage)->toBe('21.24')
        ->and($fresh->credit_limit_cents)->toBe(500000);

    $history = PlaidStatementHistory::where('plaid_account_id', $plaidAccount->id)->sole();
    expect($history->statement_balance_cents)->toBe(64520)
        ->and($history->statement_issue_date->toDateString())->toBe('2026-02-28');
});

test('syncing an investment account also refreshes its holdings', function () {
    $plaidAccount = plaidSyncLinkedAccount($this->budget, 'investment', 'brokerage', 'brokerage');

    $this->plaid
        ->on('/transactions/get', FakePlaidApi::error('PRODUCTS_NOT_SUPPORTED', 'no transactions for investments'))
        ->on('/accounts/get', plaidSyncAccountsResponse('plaid-brokerage', 10500.00))
        ->on('/investments/holdings/get', [
            'accounts' => [['account_id' => 'plaid-brokerage', 'balances' => ['current' => 10750.00, 'available' => null]]],
            'securities' => [[
                'security_id' => 'sec-vti', 'ticker_symbol' => 'VTI', 'name' => 'Vanguard Total Stock Market ETF',
                'type' => 'etf', 'close_price' => 250.00, 'is_cash_equivalent' => false,
            ]],
            'holdings' => [[
                'account_id' => 'plaid-brokerage', 'security_id' => 'sec-vti', 'quantity' => 43,
                'cost_basis' => 9000.00, 'institution_price' => 250.00, 'institution_value' => 10750.00,
            ]],
        ]);

    $this->actingAs($this->user)
        ->post(route('plaid.sync', [$this->budget, $plaidAccount->account]))
        ->assertSessionHas('message', 'Synced 0 new and updated 0 transactions.');

    $holding = $plaidAccount->holdings()->with('security')->sole();
    expect($holding->security->ticker_symbol)->toBe('VTI')
        ->and((float) $holding->quantity)->toBe(43.0)
        ->and($holding->institution_value_cents)->toBe(1075000)
        ->and($plaidAccount->fresh()->current_balance_cents)->toBe(1075000)
        ->and($plaidAccount->account->fresh()->current_balance_cents)->toBe(1075000);
});

test('sync refuses an account that is not linked to Plaid', function () {
    $account = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);

    $this->actingAs($this->user)
        ->from(route('budgets.show', $this->budget))
        ->post(route('plaid.sync', [$this->budget, $account]))
        ->assertRedirect(route('budgets.show', $this->budget))
        ->assertSessionHas('error', 'Account is not linked to Plaid.');

    expect($this->plaid->requests)->toBe([]);
});

test('balance refresh updates the balance and reports Plaid failures', function () {
    $plaidAccount = plaidSyncLinkedAccount($this->budget, 'depository', 'checking', 'checking', 100000);
    $service = app(PlaidService::class);

    $this->plaid->on('/accounts/get', plaidSyncAccountsResponse('plaid-checking', 1500.25, 1400.00));
    expect($service->updateAccountBalance($plaidAccount))->toBeTrue()
        ->and($plaidAccount->account->fresh()->current_balance_cents)->toBe(150025);

    $this->plaid->on('/accounts/get', FakePlaidApi::error('ITEM_LOGIN_REQUIRED', 'the login details of this item have changed'));
    expect($service->updateAccountBalance($plaidAccount->fresh()))->toBeFalse()
        ->and($plaidAccount->account->fresh()->current_balance_cents)->toBe(150025);
});

test('liabilities refresh updates statement data and reports when none is available', function () {
    $plaidAccount = plaidSyncLinkedAccount($this->budget, 'credit', 'credit card', 'credit card');
    $service = app(PlaidService::class);

    $this->plaid
        ->on('/accounts/get', plaidSyncAccountsResponse('plaid-credit-card', 100.00, 900.00, 1000.00))
        ->on('/liabilities/get', ['liabilities' => ['credit' => [[
            'account_id' => 'plaid-credit-card', 'last_statement_balance' => 80.00,
            'last_statement_issue_date' => '2026-03-01', 'minimum_payment_amount' => 25.00,
        ]]]]);
    expect($service->updateLiabilityData($plaidAccount))->toBeTrue()
        ->and($plaidAccount->fresh()->last_statement_balance_cents)->toBe(8000);

    $this->plaid->on('/liabilities/get', FakePlaidApi::error('PRODUCTS_NOT_SUPPORTED', 'not supported'));
    expect($service->updateLiabilityData($plaidAccount->fresh()))->toBeFalse();
});

test('a login required error during sync shows a re-authenticate error and marks the connection as errored', function () {
    $plaidAccount = plaidSyncLinkedAccount($this->budget, 'depository', 'checking', 'checking');
    $account = $plaidAccount->account;
    $this->plaid->on('/transactions/get', FakePlaidApi::error('ITEM_LOGIN_REQUIRED', 'the login details of this item have changed'));

    $this->actingAs($this->user)
        ->from(route('plaid.link', [$this->budget, $account]))
        ->post(route('plaid.sync', [$this->budget, $account]))
        ->assertRedirect(route('plaid.link', [$this->budget, $account]))
        ->assertSessionMissing('message')
        ->assertSessionHas('error', 'Your bank needs you to sign in again. Use Re-authenticate to reconnect this account.');

    expect(session('error'))->not->toContain('login details')
        ->and($plaidAccount->plaidConnection->fresh())
        ->status->toBe(PlaidConnection::STATUS_ERROR)
        ->error_message->not->toBeNull();
});

test('a login required error on a balance, liabilities or investments refresh marks the connection as errored', function (string $method, string $path, array $account) {
    $plaidAccount = plaidSyncLinkedAccount($this->budget, ...$account);
    $this->plaid->on($path, FakePlaidApi::error('ITEM_LOGIN_REQUIRED', 'the login details of this item have changed'));

    expect(app(PlaidService::class)->{$method}($plaidAccount))->toBeFalse();

    expect($plaidAccount->plaidConnection->fresh())
        ->status->toBe(PlaidConnection::STATUS_ERROR)
        ->error_message->toContain('ITEM_LOGIN_REQUIRED');
})->with([
    'balance' => ['updateAccountBalance', '/accounts/get', ['depository', 'checking', 'checking']],
    'liabilities' => ['updateLiabilityData', '/liabilities/get', ['credit', 'credit card', 'credit card']],
    'investments' => ['updateInvestmentData', '/investments/holdings/get', ['investment', 'brokerage', 'brokerage']],
]);

test('a Plaid error or timeout during sync shows a failure instead of a zero count success', function (mixed $response, string $expected) {
    $plaidAccount = plaidSyncLinkedAccount($this->budget, 'depository', 'checking', 'checking');
    $account = $plaidAccount->account;
    $this->plaid->on('/transactions/get', $response);

    $this->actingAs($this->user)
        ->post(route('plaid.sync', [$this->budget, $account]))
        ->assertSessionMissing('message')
        ->assertSessionHas('error', $expected);

    expect(session('error'))->not->toContain('internal plaid detail')
        ->and(session('error'))->not->toContain('cURL')
        ->and($plaidAccount->plaidConnection->fresh()->status)->toBe(PlaidConnection::STATUS_ACTIVE);
})->with([
    'api error' => [fn () => FakePlaidApi::error('INTERNAL_SERVER_ERROR', 'secret internal plaid detail', 500), 'Failed to sync transactions. Please try again.'],
    'connect timeout' => [FakePlaidApi::TIMEOUT, "Couldn't reach the bank connection service, please try again."],
]);

test('PRODUCT_NOT_READY during sync shows the still being processed message', function () {
    $plaidAccount = plaidSyncLinkedAccount($this->budget, 'credit', 'credit card', 'credit card');
    $account = $plaidAccount->account;
    $this->plaid->on('/transactions/get', FakePlaidApi::error('PRODUCT_NOT_READY', 'the requested product is not yet ready'));

    $this->actingAs($this->user)
        ->post(route('plaid.sync', [$this->budget, $account]))
        ->assertSessionMissing('message')
        ->assertSessionHas('error', 'Transaction data for this account is still being processed by Plaid. This can take 1-3 days for credit cards after initial connection. Please try again later.');
});
