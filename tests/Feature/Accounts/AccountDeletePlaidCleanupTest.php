<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\PlaidAccount;
use App\Models\PlaidConnection;
use App\Models\User;
use Tests\Support\FakePlaidApi;

/*
 * ACCOUNT-002: deleting a Plaid-linked account cleans up its connection and the
 * Item at Plaid when it was the connection's last account, as unlinking does.
 * Plaid is faked at the Guzzle boundary.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->plaid = FakePlaidApi::install();

    $this->connection = PlaidConnection::create([
        'budget_id' => $this->budget->id,
        'plaid_item_id' => 'item-brokerage',
        'institution_name' => 'First Platypus Brokerage',
        'access_token' => 'access-sandbox-brokerage',
        'status' => PlaidConnection::STATUS_ACTIVE,
    ]);
});

function accountDeletePlaidAccount(PlaidConnection $connection, Budget $budget, string $plaidId): Account
{
    $account = Account::factory()->checking()->create(['budget_id' => $budget->id]);
    PlaidAccount::create([
        'plaid_connection_id' => $connection->id,
        'account_id' => $account->id,
        'plaid_account_id' => $plaidId,
        'account_name' => 'Brokerage',
        'account_type' => 'investment',
        'account_subtype' => 'brokerage',
    ]);

    return $account;
}

test('deleting the last account of a Plaid connection removes the connection and its Plaid item', function () {
    $account = accountDeletePlaidAccount($this->connection, $this->budget, 'acc-1');
    $this->plaid->on('/item/remove', ['request_id' => 'req-1']);

    $this->actingAs($this->user)
        ->delete(route('budgets.accounts.destroy', [$this->budget, $account]))
        ->assertRedirect(route('budgets.show', $this->budget))
        ->assertSessionHas('message', 'Account deleted successfully');

    expect(Account::find($account->id))->toBeNull()
        ->and(PlaidAccount::count())->toBe(0)
        ->and(PlaidConnection::find($this->connection->id))->toBeNull()
        ->and($this->plaid->bodyFor('/item/remove'))->toBe(['access_token' => 'access-sandbox-brokerage']);
});

test('deleting one of several accounts on a Plaid connection keeps the connection and its Plaid item', function () {
    $account = accountDeletePlaidAccount($this->connection, $this->budget, 'acc-1');
    accountDeletePlaidAccount($this->connection, $this->budget, 'acc-2');

    $this->actingAs($this->user)
        ->delete(route('budgets.accounts.destroy', [$this->budget, $account]))
        ->assertRedirect(route('budgets.show', $this->budget));

    expect(Account::find($account->id))->toBeNull()
        ->and(PlaidConnection::find($this->connection->id))->not->toBeNull()
        ->and($this->plaid->requests)->toBe([]);
});

test('a Plaid failure removing the item still deletes the account and its connection locally', function () {
    $account = accountDeletePlaidAccount($this->connection, $this->budget, 'acc-1');
    $this->plaid->on('/item/remove', FakePlaidApi::TIMEOUT);

    $this->actingAs($this->user)
        ->delete(route('budgets.accounts.destroy', [$this->budget, $account]))
        ->assertRedirect(route('budgets.show', $this->budget))
        ->assertSessionHas('message', 'Account deleted successfully');

    expect($this->plaid->paths())->toBe(['/item/remove'])
        ->and(Account::find($account->id))->toBeNull()
        ->and(PlaidConnection::find($this->connection->id))->toBeNull();
});

test('an editor deleting the last account of a Plaid connection leaves the connection and its Plaid item for the owner', function () {
    $editor = User::factory()->create();
    $this->budget->connected_users()->attach($editor->id, ['access_level' => 'editor']);
    $account = accountDeletePlaidAccount($this->connection, $this->budget, 'acc-1');

    $this->actingAs($editor)
        ->delete(route('budgets.accounts.destroy', [$this->budget, $account]))
        ->assertRedirect(route('budgets.show', $this->budget))
        ->assertSessionHas('message', 'Account deleted successfully');

    expect(Account::find($account->id))->toBeNull()
        ->and(PlaidAccount::count())->toBe(0)
        ->and(PlaidConnection::find($this->connection->id))->not->toBeNull()
        ->and($this->plaid->requests)->toBe([]);
});

test('deleting a manual account makes no Plaid call', function () {
    $account = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);

    $this->actingAs($this->user)
        ->delete(route('budgets.accounts.destroy', [$this->budget, $account]))
        ->assertRedirect(route('budgets.show', $this->budget));

    expect(Account::find($account->id))->toBeNull()
        ->and(PlaidConnection::find($this->connection->id))->not->toBeNull()
        ->and($this->plaid->requests)->toBe([]);
});
