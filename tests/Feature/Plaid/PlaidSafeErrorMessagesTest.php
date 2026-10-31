<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\PlaidAccount;
use App\Models\PlaidConnection;
use App\Models\User;
use Tests\Support\FakePlaidApi;

/*
 * PLAID-001/002/005/010: failures on the connect, link and re-authenticate paths
 * must show the user a generic message, never the raw exception or Plaid text.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->account = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
    $this->plaid = FakePlaidApi::install();
});

function linkedPlaidAccountForSafeErrors(Account $account, Budget $budget): PlaidAccount
{
    $connection = PlaidConnection::create([
        'budget_id' => $budget->id,
        'plaid_item_id' => 'item-platypus-1',
        'institution_name' => 'First Platypus Bank',
        'access_token' => 'access-sandbox-original',
        'status' => PlaidConnection::STATUS_ACTIVE,
    ]);

    return PlaidAccount::create([
        'plaid_connection_id' => $connection->id,
        'account_id' => $account->id,
        'plaid_account_id' => 'acc-checking',
        'account_name' => 'Checking',
        'account_type' => 'depository',
        'account_subtype' => 'checking',
        'current_balance_cents' => 0,
    ]);
}

function safeErrorStorePayload(): array
{
    return [
        'public_token' => 'public-sandbox-abc',
        'metadata' => [
            'institution' => ['name' => 'First Platypus Bank'],
            'item' => ['id' => 'item-platypus-1'],
            'account' => ['id' => 'acc-checking'],
        ],
    ];
}

test('a Plaid error on import shows a generic message without the raw Plaid text', function () {
    $this->plaid->on('/item/public_token/exchange', FakePlaidApi::error('INVALID_PUBLIC_TOKEN', 'provided public token is expired'));

    $this->actingAs($this->user)
        ->from(route('plaid.discover', $this->budget))
        ->post(route('plaid.import', $this->budget), [
            'public_token' => 'public-sandbox-abc',
            'metadata' => ['institution' => ['name' => 'First Platypus Bank']],
            'selected_accounts' => ['acc-checking'],
        ])
        ->assertRedirect(route('plaid.discover', $this->budget))
        ->assertSessionHas('error', 'Failed to import accounts. Please try again.');
});

test('a connect timeout on token exchange shows a could not reach message without the cURL error', function (string $route) {
    $this->plaid->on('/item/public_token/exchange', FakePlaidApi::TIMEOUT);

    $response = match ($route) {
        'import' => $this->actingAs($this->user)->post(route('plaid.import', $this->budget), [
            'public_token' => 'public-sandbox-abc',
            'metadata' => ['institution' => ['name' => 'First Platypus Bank']],
            'selected_accounts' => ['acc-checking'],
        ]),
        'store' => $this->actingAs($this->user)->post(route('plaid.store', [$this->budget, $this->account]), safeErrorStorePayload()),
    };

    $response->assertSessionHas('error', "Couldn't reach the bank connection service, please try again.");
    expect(session('error'))->not->toContain('cURL')
        ->and(PlaidConnection::count())->toBe(0);
})->with(['import', 'store']);

test('a Plaid error when linking an account shows a generic message without the raw Plaid text', function () {
    $this->plaid->on('/item/public_token/exchange', FakePlaidApi::error('INVALID_PUBLIC_TOKEN', 'provided public token is expired'));

    $this->actingAs($this->user)
        ->post(route('plaid.store', [$this->budget, $this->account]), safeErrorStorePayload())
        ->assertSessionHas('error', 'Failed to link account. Please try again.');

    expect(session('error'))->not->toContain('expired');
});

test('a Plaid error creating an update-mode link token returns a generic JSON error', function () {
    linkedPlaidAccountForSafeErrors($this->account, $this->budget);
    $this->plaid->on('/link/token/create', FakePlaidApi::error('INVALID_ACCESS_TOKEN', 'secret internal plaid detail', 400));

    $response = $this->actingAs($this->user)
        ->postJson(route('plaid.upgrade-link-token', [$this->budget, $this->account]))
        ->assertStatus(500)
        ->assertExactJson(['error' => 'Failed to create re-authentication link. Please try again.']);

    expect($response->getContent())->not->toContain('secret internal plaid detail');
});

test('a Plaid error re-authenticating leaves the connection unchanged and shows a generic message', function () {
    $plaidAccount = linkedPlaidAccountForSafeErrors($this->account, $this->budget);
    $this->plaid->on('/item/public_token/exchange', FakePlaidApi::error('INVALID_PUBLIC_TOKEN', 'provided public token is expired'));

    $this->actingAs($this->user)
        ->post(route('plaid.update-connection', [$this->budget, $this->account]), [
            'public_token' => 'public-sandbox-abc',
            'metadata' => ['institution' => ['name' => 'First Platypus Bank']],
        ])
        ->assertSessionHas('error', 'Failed to re-authenticate. Please try again.');

    expect(session('error'))->not->toContain('expired')
        ->and($plaidAccount->plaidConnection->fresh()->access_token)->toBe('access-sandbox-original');
});
