<?php

use App\Models\Budget;
use App\Models\PlaidAccount;
use App\Models\PlaidConnection;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakePlaidApi;

/*
 * PLAID-009: the Plaid liabilities admin page, refreshing one connection and
 * refreshing every connection with credit cards.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id, 'name' => 'Household']);
    $this->plaid = FakePlaidApi::install();
});

function plaidLiabilitiesConnection(Budget $budget, string $name, string $token): PlaidConnection
{
    return PlaidConnection::create([
        'budget_id' => $budget->id,
        'plaid_item_id' => 'item-'.$token,
        'institution_name' => $name,
        'access_token' => $token,
        'status' => PlaidConnection::STATUS_ACTIVE,
    ]);
}

function plaidLiabilitiesAccount(PlaidConnection $connection, string $plaidId, string $type = 'credit', string $subtype = 'credit card'): PlaidAccount
{
    return PlaidAccount::create([
        'plaid_connection_id' => $connection->id,
        'account_id' => null,
        'plaid_account_id' => $plaidId,
        'account_name' => 'Card '.$plaidId,
        'account_type' => $type,
        'account_subtype' => $subtype,
        'current_balance_cents' => 10000,
    ]);
}

/**
 * Stub /liabilities/get and /accounts/get per access token: tokens listed in
 * $unsupported get a PRODUCTS_NOT_SUPPORTED error.
 */
function plaidLiabilitiesStub(FakePlaidApi $plaid, array $cardsByToken, array $unsupported = []): void
{
    $plaid
        ->on('/liabilities/get', function (array $body) use ($cardsByToken, $unsupported) {
            if (in_array($body['access_token'], $unsupported, true)) {
                return FakePlaidApi::error('PRODUCTS_NOT_SUPPORTED', 'not supported');
            }

            return ['liabilities' => ['credit' => array_map(fn ($id) => [
                'account_id' => $id,
                'last_statement_balance' => 250.75,
                'last_statement_issue_date' => '2026-02-28',
                'minimum_payment_amount' => 25.00,
                'next_payment_due_date' => '2026-03-25',
            ], $cardsByToken[$body['access_token']] ?? [])]];
        })
        ->on('/accounts/get', fn (array $body) => ['accounts' => array_map(fn ($id) => [
            'account_id' => $id, 'name' => 'Card', 'type' => 'credit', 'subtype' => 'credit card',
            'balances' => ['current' => 100.00, 'available' => 900.00, 'limit' => 1000.00],
        ], $cardsByToken[$body['access_token']] ?? [])]);
}

test('the liabilities page lists only the users own connections with credit cards', function () {
    $withCards = plaidLiabilitiesConnection($this->budget, 'Card Bank', 'access-cards');
    plaidLiabilitiesAccount($withCards, 'card-1');
    plaidLiabilitiesAccount($withCards, 'chk-1', 'depository', 'checking');
    $checkingOnly = plaidLiabilitiesConnection($this->budget, 'Checking Bank', 'access-checking');
    plaidLiabilitiesAccount($checkingOnly, 'chk-2', 'depository', 'checking');
    $foreign = plaidLiabilitiesConnection(Budget::factory()->create(), 'Foreign Bank', 'access-foreign');
    plaidLiabilitiesAccount($foreign, 'card-foreign');

    $this->actingAs($this->user)
        ->get(route('admin.plaid-liabilities.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/PlaidLiabilities')
            ->has('connections', 1)
            ->where('connections.0.id', $withCards->id)
            ->where('connections.0.institution_name', 'Card Bank')
            ->where('connections.0.budget_name', 'Household')
            ->has('connections.0.credit_cards', 1)
            ->where('connections.0.credit_cards.0.account_name', 'Card card-1'));

    expect($this->plaid->requests)->toBe([]);
});

test('refreshing one connection updates its cards and flashes success', function () {
    $connection = plaidLiabilitiesConnection($this->budget, 'Card Bank', 'access-cards');
    $card = plaidLiabilitiesAccount($connection, 'card-1');
    plaidLiabilitiesStub($this->plaid, ['access-cards' => ['card-1']]);

    $this->actingAs($this->user)
        ->from(route('admin.plaid-liabilities.index'))
        ->post(route('admin.plaid-liabilities.update-connection', $connection))
        ->assertRedirect(route('admin.plaid-liabilities.index'))
        ->assertSessionMissing('error')
        ->assertSessionHas('message', 'Successfully updated liability data for Card Bank.');

    expect($card->fresh())
        ->last_statement_balance_cents->toBe(25075)
        ->minimum_payment_amount_cents->toBe(2500)
        ->and($this->plaid->unexpected)->toBe([]);
});

test('refreshing all connections reports how many succeeded and failed', function () {
    $supported = plaidLiabilitiesConnection($this->budget, 'Card Bank', 'access-cards');
    $card = plaidLiabilitiesAccount($supported, 'card-1');
    $unsupported = plaidLiabilitiesConnection($this->budget, 'Old Bank', 'access-old');
    $oldCard = plaidLiabilitiesAccount($unsupported, 'card-2');
    plaidLiabilitiesStub($this->plaid, ['access-cards' => ['card-1']], unsupported: ['access-old']);

    $this->actingAs($this->user)
        ->post(route('admin.plaid-liabilities.update-all'))
        ->assertSessionHas('message', 'Updated 1 connection(s), but 1 failed. Some institutions may not support this feature.');

    expect($card->fresh()->last_statement_balance_cents)->toBe(25075)
        ->and($oldCard->fresh()->last_statement_balance_cents)->toBeNull();
});

test('with no credit card connections the page is empty and update all reports nothing to update', function () {
    $checkingOnly = plaidLiabilitiesConnection($this->budget, 'Checking Bank', 'access-checking');
    plaidLiabilitiesAccount($checkingOnly, 'chk-1', 'depository', 'checking');

    $this->actingAs($this->user)
        ->get(route('admin.plaid-liabilities.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Admin/PlaidLiabilities')->has('connections', 0));

    $this->actingAs($this->user)
        ->post(route('admin.plaid-liabilities.update-all'))
        ->assertSessionMissing('message')
        ->assertSessionHas('error', 'No credit card accounts found to update.');

    expect($this->plaid->requests)->toBe([]);
});

test('refreshing a connection without credit cards flashes an error', function () {
    $checkingOnly = plaidLiabilitiesConnection($this->budget, 'Checking Bank', 'access-checking');
    plaidLiabilitiesAccount($checkingOnly, 'chk-1', 'depository', 'checking');

    $this->actingAs($this->user)
        ->post(route('admin.plaid-liabilities.update-connection', $checkingOnly))
        ->assertSessionMissing('message')
        ->assertSessionHas('error', 'No credit card accounts found for this connection.');

    expect($this->plaid->requests)->toBe([]);
});

test('refreshing one connection is rate limited to ten per minute', function () {
    $connection = plaidLiabilitiesConnection($this->budget, 'Checking Bank', 'access-checking');
    $this->actingAs($this->user);

    for ($i = 0; $i < 10; $i++) {
        expect($this->post(route('admin.plaid-liabilities.update-connection', $connection))->status())->not->toBe(429);
    }
    $this->post(route('admin.plaid-liabilities.update-connection', $connection))->assertStatus(429);
});

test('refreshing all liabilities is rate limited to three per minute', function () {
    $this->actingAs($this->user);

    for ($i = 0; $i < 3; $i++) {
        expect($this->post(route('admin.plaid-liabilities.update-all'))->status())->not->toBe(429);
    }
    $this->post(route('admin.plaid-liabilities.update-all'))->assertStatus(429);
});
