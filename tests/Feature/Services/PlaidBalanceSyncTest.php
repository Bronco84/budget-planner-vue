<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\PlaidAccount;
use App\Models\PlaidConnection;
use App\Services\PlaidService;

/*
 * Tests for PlaidService::updateAccountBalance(): which Plaid balance field is
 * stored when an institution omits the current balance.
 */

function makePlaidCheckingAccount(int $existingBalanceCents): PlaidAccount
{
    $budget = Budget::factory()->create();

    $account = Account::factory()->checking()->create([
        'budget_id' => $budget->id,
        'current_balance_cents' => $existingBalanceCents,
    ]);

    $connection = PlaidConnection::create([
        'budget_id' => $budget->id,
        'plaid_item_id' => 'item-test',
        'institution_name' => 'Regions Bank',
        'access_token' => 'access-sandbox-test',
        'status' => 'active',
    ]);

    return PlaidAccount::create([
        'plaid_connection_id' => $connection->id,
        'account_id' => $account->id,
        'plaid_account_id' => 'plaid-checking-1',
        'account_name' => 'LIFEGREEN PREFERRED CHECKING',
        'account_type' => 'depository',
        'account_subtype' => 'checking',
        'current_balance_cents' => $existingBalanceCents,
    ]);
}

function plaidServiceReturning(array $balances): PlaidService
{
    $service = Mockery::mock(PlaidService::class)->makePartial();
    $service->shouldReceive('getAccounts')->andReturn([
        ['account_id' => 'plaid-checking-1', 'balances' => $balances],
    ]);

    return $service;
}

test('uses the current balance when Plaid provides it', function () {
    $plaidAccount = makePlaidCheckingAccount(500000);

    plaidServiceReturning(['current' => 9511.57, 'available' => 9411.57])
        ->updateAccountBalance($plaidAccount);

    expect($plaidAccount->fresh()->current_balance_cents)->toBe(951157)
        ->and($plaidAccount->fresh()->available_balance_cents)->toBe(941157)
        ->and($plaidAccount->account->fresh()->current_balance_cents)->toBe(951157);
});

test('falls back to the available balance when current is null', function () {
    $plaidAccount = makePlaidCheckingAccount(500000);

    plaidServiceReturning(['current' => null, 'available' => 9411.57])
        ->updateAccountBalance($plaidAccount);

    expect($plaidAccount->fresh()->current_balance_cents)->toBe(941157)
        ->and($plaidAccount->account->fresh()->current_balance_cents)->toBe(941157);
});

test('keeps the last known balance when Plaid omits both balances', function () {
    $plaidAccount = makePlaidCheckingAccount(500000);

    plaidServiceReturning(['current' => null, 'available' => null])
        ->updateAccountBalance($plaidAccount);

    expect($plaidAccount->fresh()->current_balance_cents)->toBe(500000)
        ->and($plaidAccount->account->fresh()->current_balance_cents)->toBe(500000);
});
