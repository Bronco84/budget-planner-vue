<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\PlaidAccount;
use App\Models\PlaidConnection;
use App\Models\User;
use Tests\Support\FakePlaidApi;

/*
 * PLAID-006: unlink an account from Plaid. Plaid is faked at the Guzzle boundary.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->plaid = FakePlaidApi::install();

    $this->connection = PlaidConnection::create([
        'budget_id' => $this->budget->id,
        'plaid_item_id' => 'item-unlink',
        'institution_name' => 'First Platypus Bank',
        'access_token' => 'access-sandbox-unlink',
        'status' => PlaidConnection::STATUS_ACTIVE,
    ]);
});

function plaidUnlinkAccount(PlaidConnection $connection, Budget $budget, string $plaidId): Account
{
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

test('unlinking one of several accounts keeps the connection and its Plaid item', function () {
    $account = plaidUnlinkAccount($this->connection, $this->budget, 'acc-1');
    plaidUnlinkAccount($this->connection, $this->budget, 'acc-2');

    $this->actingAs($this->user)
        ->delete(route('plaid.destroy', [$this->budget, $account]))
        ->assertSessionHas('message', 'Account unlinked from Plaid.');

    expect($account->fresh())->not->toBeNull()
        ->and($account->plaidAccount()->exists())->toBeFalse()
        ->and($this->connection->fresh())->not->toBeNull()
        ->and($this->plaid->requests)->toBe([]);
});

test('unlinking the last account removes the connection and its Plaid item', function () {
    $account = plaidUnlinkAccount($this->connection, $this->budget, 'acc-1');
    $this->plaid->on('/item/remove', ['request_id' => 'req-1']);

    $this->actingAs($this->user)
        ->delete(route('plaid.destroy', [$this->budget, $account]))
        ->assertSessionHas('message', 'Account unlinked from Plaid. Connection to First Platypus Bank was also removed as it had no remaining accounts.');

    expect(PlaidConnection::find($this->connection->id))->toBeNull()
        ->and($this->plaid->bodyFor('/item/remove'))->toBe(['access_token' => 'access-sandbox-unlink']);
});

test('a Plaid failure removing the item still unlinks the last account locally', function () {
    $account = plaidUnlinkAccount($this->connection, $this->budget, 'acc-1');
    $this->plaid->on('/item/remove', FakePlaidApi::TIMEOUT);

    $this->actingAs($this->user)
        ->delete(route('plaid.destroy', [$this->budget, $account]))
        ->assertSessionHas('message');

    expect($this->plaid->paths())->toBe(['/item/remove'])
        ->and(PlaidConnection::find($this->connection->id))->toBeNull()
        ->and(PlaidAccount::count())->toBe(0);
});
