<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\PlaidAccount;
use App\Models\PlaidConnection;
use App\Models\User;
use Tests\Support\FakePlaidApi;

/*
 * PLAID-005: re-authenticate an errored connection with an update-mode link
 * token (plaid.upgrade-link-token) and the update-mode public token
 * (plaid.update-connection).
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->account = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
    $this->plaid = FakePlaidApi::install();
});

function plaidReauthLinkedAccount(Account $account, Budget $budget, string $accessToken = 'access-sandbox-old'): PlaidConnection
{
    $connection = PlaidConnection::create([
        'budget_id' => $budget->id,
        'plaid_item_id' => 'item-reauth',
        'institution_name' => 'First Platypus Bank',
        'access_token' => $accessToken,
        'status' => PlaidConnection::STATUS_ERROR,
        'error_message' => 'ITEM_LOGIN_REQUIRED',
    ]);

    PlaidAccount::create([
        'plaid_connection_id' => $connection->id,
        'account_id' => $account->id,
        'plaid_account_id' => 'acc-reauth',
        'account_name' => 'Checking',
        'account_type' => 'depository',
        'account_subtype' => 'checking',
        'current_balance_cents' => 0,
    ]);

    return $connection;
}

test('a linked account receives an update mode link token and the institution name', function () {
    plaidReauthLinkedAccount($this->account, $this->budget);
    $this->plaid->on('/link/token/create', ['link_token' => 'link-sandbox-update', 'expiration' => '2026-03-15T14:00:00Z']);

    $this->actingAs($this->user)
        ->postJson(route('plaid.upgrade-link-token', [$this->budget, $this->account]))
        ->assertOk()
        ->assertExactJson([
            'link_token' => 'link-sandbox-update',
            'institution_name' => 'First Platypus Bank',
        ]);

    expect($this->plaid->bodyFor('/link/token/create'))
        ->toHaveKey('access_token', 'access-sandbox-old')
        ->toHaveKey('update');
});

test('posting the update mode public token replaces the access token and reactivates the connection', function () {
    $connection = plaidReauthLinkedAccount($this->account, $this->budget);
    $this->plaid->on('/item/public_token/exchange', ['access_token' => 'access-sandbox-renewed', 'item_id' => 'item-reauth']);

    $this->actingAs($this->user)
        ->from(route('plaid.link', [$this->budget, $this->account]))
        ->post(route('plaid.update-connection', [$this->budget, $this->account]), [
            'public_token' => 'public-sandbox-update',
            'metadata' => ['institution' => ['name' => 'First Platypus Bank']],
        ])
        ->assertRedirect(route('plaid.link', [$this->budget, $this->account]))
        ->assertSessionMissing('error')
        ->assertSessionHas('message', 'Connection re-authenticated successfully. All accounts under this connection have been updated.');

    expect(PlaidConnection::count())->toBe(1)
        ->and($connection->fresh())
        ->access_token->toBe('access-sandbox-renewed')
        ->status->toBe(PlaidConnection::STATUS_ACTIVE)
        ->error_message->toBeNull();
});

test('requesting an upgrade token for an unlinked account returns a JSON 404', function () {
    $this->actingAs($this->user)
        ->postJson(route('plaid.upgrade-link-token', [$this->budget, $this->account]))
        ->assertNotFound()
        ->assertExactJson(['error' => 'Account is not linked to Plaid.']);

    expect($this->plaid->requests)->toBe([]);
});

test('requesting an upgrade token for a connection without an access token returns a JSON 400', function () {
    plaidReauthLinkedAccount($this->account, $this->budget, accessToken: '');

    $this->actingAs($this->user)
        ->postJson(route('plaid.upgrade-link-token', [$this->budget, $this->account]))
        ->assertStatus(400)
        ->assertJsonPath('error', 'No access token found for this connection.');

    expect($this->plaid->requests)->toBe([]);
});

test('updating a connection requires a public token and metadata and leaves it unchanged', function () {
    $connection = plaidReauthLinkedAccount($this->account, $this->budget);

    $this->actingAs($this->user)
        ->post(route('plaid.update-connection', [$this->budget, $this->account]), [])
        ->assertSessionHasErrors(['public_token', 'metadata']);

    expect($connection->fresh())
        ->access_token->toBe('access-sandbox-old')
        ->status->toBe(PlaidConnection::STATUS_ERROR)
        ->and($this->plaid->requests)->toBe([]);
});
