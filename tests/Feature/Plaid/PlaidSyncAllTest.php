<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\PlaidAccount;
use App\Models\PlaidConnection;
use App\Models\User;
use Tests\Support\FakePlaidApi;

/*
 * PLAID-004: sync every Plaid-linked account in a budget at once. Plaid is faked at
 * the Guzzle boundary by Tests\Support\FakePlaidApi; each account gets its own
 * connection/access token so responses can differ per account.
 */

beforeEach(function () {
    $this->travelTo(Carbon\Carbon::parse('2026-03-15 10:00:00'));

    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->plaid = FakePlaidApi::install();
});

function syncAllLinkedAccount(Budget $budget, string $key, string $name): PlaidAccount
{
    $account = Account::factory()->checking()->create(['budget_id' => $budget->id, 'name' => $name]);

    $connection = PlaidConnection::create([
        'budget_id' => $budget->id,
        'plaid_item_id' => "item-{$key}",
        'institution_name' => 'First Platypus Bank',
        'access_token' => "access-{$key}",
        'status' => PlaidConnection::STATUS_ACTIVE,
    ]);

    return PlaidAccount::create([
        'plaid_connection_id' => $connection->id,
        'account_id' => $account->id,
        'plaid_account_id' => "plaid-{$key}",
        'account_name' => $name,
        'account_type' => 'depository',
        'account_subtype' => 'checking',
        'current_balance_cents' => 0,
    ]);
}

/**
 * Answer /transactions/get per access token: an array of transactions for the
 * account, or a Plaid error code string.
 *
 * @param  array<string, array|string>  $byKey
 */
function stubSyncAllTransactions(FakePlaidApi $plaid, array $byKey): void
{
    $plaid->on('/transactions/get', function (array $body) use ($byKey) {
        $key = str_replace('access-', '', $body['access_token']);
        $outcome = $byKey[$key];

        if (is_string($outcome)) {
            return FakePlaidApi::error($outcome, "secret plaid detail for {$key}");
        }

        return ['transactions' => array_map(fn (string $id) => [
            'transaction_id' => $id, 'account_id' => "plaid-{$key}", 'amount' => 10.00,
            'date' => '2026-03-10', 'name' => "Purchase {$id}", 'pending' => false,
        ], $outcome)];
    })->on('/accounts/get', fn (array $body) => ['accounts' => [[
        'account_id' => 'plaid-'.str_replace('access-', '', $body['access_token']),
        'name' => 'Account', 'type' => 'depository', 'subtype' => 'checking',
        'balances' => ['current' => 100.00, 'available' => 100.00],
    ]]]);
}

test('syncing all accounts reports the combined counts', function () {
    syncAllLinkedAccount($this->budget, 'a', 'Checking A');
    syncAllLinkedAccount($this->budget, 'b', 'Checking B');
    stubSyncAllTransactions($this->plaid, ['a' => ['tx-a1', 'tx-a2'], 'b' => ['tx-b1']]);

    $this->actingAs($this->user)
        ->from(route('budgets.show', $this->budget))
        ->post(route('plaid.sync-all', $this->budget))
        ->assertRedirect(route('budgets.show', $this->budget))
        ->assertSessionMissing('error')
        ->assertSessionHas('message', 'Synced 3 new and updated 0 transactions.');
});

test('a budget with no linked accounts reports that nothing is connected', function () {
    $this->actingAs($this->user)
        ->post(route('plaid.sync-all', $this->budget))
        ->assertSessionHas('error', 'No Plaid-connected accounts found.');

    expect($this->plaid->requests)->toBe([]);
});

test('sync all reports accounts that failed alongside the successful totals', function () {
    syncAllLinkedAccount($this->budget, 'a', 'Checking A');
    $failing = syncAllLinkedAccount($this->budget, 'b', 'Checking B');
    stubSyncAllTransactions($this->plaid, ['a' => ['tx-a1'], 'b' => 'ITEM_LOGIN_REQUIRED']);

    $this->actingAs($this->user)
        ->post(route('plaid.sync-all', $this->budget))
        ->assertSessionHas('message', 'Synced 1 new and updated 0 transactions. 1 account could not be synced: Checking B.');

    expect(session('message'))->not->toContain('secret plaid detail')
        ->and($failing->plaidConnection->fresh()->status)->toBe(PlaidConnection::STATUS_ERROR);
});

test('sync all reports a failure even when a successful account had nothing new', function () {
    syncAllLinkedAccount($this->budget, 'a', 'Checking A');
    syncAllLinkedAccount($this->budget, 'b', 'Checking B');
    stubSyncAllTransactions($this->plaid, ['a' => [], 'b' => 'INTERNAL_SERVER_ERROR']);

    $this->actingAs($this->user)
        ->post(route('plaid.sync-all', $this->budget))
        ->assertSessionMissing('error')
        ->assertSessionHas('message', 'Synced 0 new and updated 0 transactions. 1 account could not be synced: Checking B.');
});

test('sync all shows an error when every account failed', function () {
    syncAllLinkedAccount($this->budget, 'a', 'Checking A');
    syncAllLinkedAccount($this->budget, 'b', 'Checking B');
    stubSyncAllTransactions($this->plaid, ['a' => 'INTERNAL_SERVER_ERROR', 'b' => 'ITEM_LOGIN_REQUIRED']);

    $this->actingAs($this->user)
        ->post(route('plaid.sync-all', $this->budget))
        ->assertSessionMissing('message')
        ->assertSessionHas('error', 'Failed to sync transactions. Please try again.');

    expect(session('error'))->not->toContain('secret plaid detail');
});

test('sync all skips accounts whose Plaid data is not ready yet', function () {
    syncAllLinkedAccount($this->budget, 'a', 'Checking A');
    syncAllLinkedAccount($this->budget, 'b', 'Checking B');
    stubSyncAllTransactions($this->plaid, ['a' => ['tx-a1'], 'b' => 'PRODUCT_NOT_READY']);

    $this->actingAs($this->user)
        ->post(route('plaid.sync-all', $this->budget))
        ->assertSessionMissing('error')
        ->assertSessionHas('message', 'Synced 1 new and updated 0 transactions.');
});
