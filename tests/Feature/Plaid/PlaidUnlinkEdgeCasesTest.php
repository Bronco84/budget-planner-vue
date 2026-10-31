<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\PlaidAccount;
use App\Models\PlaidConnection;
use App\Models\Transaction;
use App\Models\User;
use Tests\Support\FakePlaidApi;

/*
 * PLAID-006: unlinking keeps the account's transactions, refuses an unlinked
 * account, and is forbidden for another user's budget.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->plaid = FakePlaidApi::install();
});

function plaidUnlinkEdgeLinkedAccount(Budget $budget, string $plaidId = 'acc-edge'): Account
{
    $connection = PlaidConnection::firstOrCreate(
        ['budget_id' => $budget->id, 'plaid_item_id' => 'item-edge-'.$budget->id],
        ['institution_name' => 'First Platypus Bank', 'access_token' => 'access-sandbox-edge', 'status' => PlaidConnection::STATUS_ACTIVE],
    );
    $account = Account::factory()->checking()->create(['budget_id' => $budget->id]);
    PlaidAccount::create([
        'plaid_connection_id' => $connection->id,
        'account_id' => $account->id,
        'plaid_account_id' => $plaidId,
        'account_name' => 'Checking',
        'account_type' => 'depository',
        'account_subtype' => 'checking',
    ]);

    return $account;
}

test('unlinking an account keeps the account and its transactions', function () {
    $account = plaidUnlinkEdgeLinkedAccount($this->budget, 'acc-edge-1');
    plaidUnlinkEdgeLinkedAccount($this->budget, 'acc-edge-2');
    $transactions = Transaction::factory()->count(3)->create([
        'budget_id' => $this->budget->id,
        'account_id' => $account->id,
    ]);

    $this->actingAs($this->user)
        ->delete(route('plaid.destroy', [$this->budget, $account]))
        ->assertSessionHas('message', 'Account unlinked from Plaid.');

    expect($account->fresh())->not->toBeNull()
        ->and($account->plaidAccount()->exists())->toBeFalse()
        ->and(Transaction::where('account_id', $account->id)->pluck('id')->sort()->values()->all())
        ->toBe($transactions->pluck('id')->sort()->values()->all());
});

test('disconnecting an account that is not linked redirects back with an error', function () {
    $account = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);

    $this->actingAs($this->user)
        ->from(route('budgets.show', $this->budget))
        ->delete(route('plaid.destroy', [$this->budget, $account]))
        ->assertRedirect(route('budgets.show', $this->budget))
        ->assertSessionMissing('message')
        ->assertSessionHas('error', 'Account is not linked to Plaid.');

    expect($account->fresh())->not->toBeNull()
        ->and($this->plaid->requests)->toBe([]);
});

test('disconnecting another users account is forbidden and leaves the link in place', function () {
    $otherBudget = Budget::factory()->create();
    $otherAccount = plaidUnlinkEdgeLinkedAccount($otherBudget);

    $this->actingAs($this->user)
        ->delete(route('plaid.destroy', [$otherBudget, $otherAccount]))
        ->assertForbidden();

    expect($otherAccount->plaidAccount()->exists())->toBeTrue()
        ->and(PlaidConnection::where('budget_id', $otherBudget->id)->exists())->toBeTrue()
        ->and($this->plaid->requests)->toBe([]);
});
