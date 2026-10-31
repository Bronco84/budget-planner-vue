<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\PlaidAccount;
use App\Models\PlaidConnection;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakePlaidApi;

/*
 * PLAID-005: the bank connection page tells the user to sign in again when
 * the connection is in the 'error' state (e.g. after ITEM_LOGIN_REQUIRED).
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->account = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
    FakePlaidApi::install()->on('/link/token/create', ['link_token' => 'link-sandbox-1', 'expiration' => now()->addHour()->toIso8601String()]);
});

function reauthPromptLinkedAccount(Account $account, Budget $budget, string $status): PlaidAccount
{
    $connection = PlaidConnection::create([
        'budget_id' => $budget->id,
        'plaid_item_id' => 'item-reauth-1',
        'institution_name' => 'First Platypus Bank',
        'access_token' => 'access-sandbox-reauth',
        'status' => $status,
        'error_message' => $status === PlaidConnection::STATUS_ERROR ? 'Login required' : null,
    ]);

    return PlaidAccount::create([
        'plaid_connection_id' => $connection->id,
        'account_id' => $account->id,
        'plaid_account_id' => 'acc-reauth',
        'account_name' => 'Checking',
        'account_type' => 'depository',
        'account_subtype' => 'checking',
        'current_balance_cents' => 0,
    ]);
}

test('the bank connection page flags a connection in the error state as needing re-authentication', function () {
    reauthPromptLinkedAccount($this->account, $this->budget, PlaidConnection::STATUS_ERROR);

    $this->actingAs($this->user)
        ->get(route('plaid.link', [$this->budget, $this->account]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Plaid/Link')
            ->where('connectionMetadata.status', PlaidConnection::STATUS_ERROR)
            ->where('connectionMetadata.needs_reauth', true));
});

test('the bank connection page does not flag an active connection as needing re-authentication', function () {
    reauthPromptLinkedAccount($this->account, $this->budget, PlaidConnection::STATUS_ACTIVE);

    $this->actingAs($this->user)
        ->get(route('plaid.link', [$this->budget, $this->account]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('connectionMetadata.status', PlaidConnection::STATUS_ACTIVE)
            ->where('connectionMetadata.needs_reauth', false));
});
