<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\PlaidAccount;
use App\Models\PlaidConnection;
use App\Models\PlaidStatementHistory;
use App\Models\User;
use Tests\Support\FakePlaidApi;

/*
 * PLAID-007: credit card statement history JSON (plaid.statement-history).
 */

beforeEach(function () {
    $this->travelTo(Carbon\Carbon::parse('2026-03-15 10:00:00'));

    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->plaid = FakePlaidApi::install();
});

function plaidStatementHistoryAccount(Budget $budget, string $type, string $subtype, ?int $creditLimitCents = null): PlaidAccount
{
    $account = Account::factory()->create([
        'budget_id' => $budget->id,
        'name' => 'Rewards Card',
        'type' => $subtype === 'credit card' ? 'credit card' : 'checking',
    ]);
    $connection = PlaidConnection::create([
        'budget_id' => $budget->id,
        'plaid_item_id' => 'item-statements-'.$subtype,
        'institution_name' => 'First Platypus Bank',
        'access_token' => 'access-sandbox-statements',
        'status' => PlaidConnection::STATUS_ACTIVE,
    ]);

    return PlaidAccount::create([
        'plaid_connection_id' => $connection->id,
        'account_id' => $account->id,
        'plaid_account_id' => 'acc-'.str_replace(' ', '-', $subtype),
        'account_name' => 'Card',
        'account_type' => $type,
        'account_subtype' => $subtype,
        'credit_limit_cents' => $creditLimitCents,
    ]);
}

test('a linked credit card returns its statement history and credit limit', function () {
    $plaidAccount = plaidStatementHistoryAccount($this->budget, 'credit', 'credit card', 500000);
    $statement = fn (string $issued, int $balance) => PlaidStatementHistory::create([
        'plaid_account_id' => $plaidAccount->id,
        'statement_issue_date' => $issued,
        'statement_balance_cents' => $balance,
        'payment_due_date' => Carbon\Carbon::parse($issued)->addDays(25)->toDateString(),
        'minimum_payment_cents' => 3500,
        'apr_percentage' => 21.24,
    ]);
    $statement('2026-02-28', 64520);
    $statement('2026-01-31', 51000);
    $statement('2024-12-31', 99900); // older than 12 months, excluded

    $this->actingAs($this->user)
        ->getJson(route('plaid.statement-history', [$this->budget, $plaidAccount->account]))
        ->assertOk()
        ->assertJsonPath('account_id', $plaidAccount->account_id)
        ->assertJsonPath('account_name', 'Rewards Card')
        ->assertJsonPath('credit_limit', 5000)
        ->assertJsonPath('credit_limit_cents', 500000)
        ->assertJsonCount(2, 'history')
        ->assertJsonPath('history.0.statement_issue_date', '2026-01-31')
        ->assertJsonPath('history.0.statement_balance_cents', 51000)
        ->assertJsonPath('history.1.statement_issue_date', '2026-02-28')
        ->assertJsonPath('history.1.statement_balance', 645.2)
        ->assertJsonPath('history.1.payment_due_date', '2026-03-25')
        ->assertJsonPath('history.1.minimum_payment', 35);

    expect($this->plaid->requests)->toBe([]);
});

test('a linked account that is not a credit card returns 400', function () {
    $plaidAccount = plaidStatementHistoryAccount($this->budget, 'depository', 'checking');

    $this->actingAs($this->user)
        ->getJson(route('plaid.statement-history', [$this->budget, $plaidAccount->account]))
        ->assertStatus(400)
        ->assertExactJson(['error' => 'Statement history is only available for credit card accounts.']);
});

test('a credit card with no statements in the last 12 months returns an empty history', function () {
    $plaidAccount = plaidStatementHistoryAccount($this->budget, 'credit', 'credit card');
    PlaidStatementHistory::create([
        'plaid_account_id' => $plaidAccount->id,
        'statement_issue_date' => '2025-01-31',
        'statement_balance_cents' => 12000,
    ]);

    $this->actingAs($this->user)
        ->getJson(route('plaid.statement-history', [$this->budget, $plaidAccount->account]))
        ->assertOk()
        ->assertJsonPath('credit_limit', null)
        ->assertJsonPath('history', []);
});
