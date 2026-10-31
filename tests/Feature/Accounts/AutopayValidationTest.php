<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\PlaidAccount;
use App\Models\PlaidConnection;
use App\Models\User;

/*
 * ACCOUNT-003: autopay can only be configured on a Plaid credit card with
 * statement data, from a checking/savings account in the same budget. Anything
 * else returns an error flash and leaves the card untouched.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->connection = PlaidConnection::create([
        'budget_id' => $this->budget->id,
        'plaid_item_id' => 'item-autopay',
        'institution_name' => 'Test Bank',
        'access_token' => 'access-sandbox-test',
        'status' => 'active',
    ]);

    $this->attachPlaid = function (Account $account, string $type, string $subtype, array $extra = []): PlaidAccount {
        return PlaidAccount::create(array_merge([
            'plaid_connection_id' => $this->connection->id,
            'account_id' => $account->id,
            'plaid_account_id' => 'plaid-'.$account->id,
            'account_name' => $account->name,
            'account_type' => $type,
            'account_subtype' => $subtype,
        ], $extra));
    };

    $this->card = Account::factory()->creditCard()->create(['budget_id' => $this->budget->id]);
    ($this->attachPlaid)($this->card, 'credit', 'credit card', ['last_statement_balance_cents' => 120000]);

    $this->checking = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
    ($this->attachPlaid)($this->checking, 'depository', 'checking');
});

function autopayStateOf(Account $account): array
{
    $fresh = $account->fresh();

    return [
        'enabled' => (bool) $fresh->autopay_enabled,
        'source' => $fresh->autopay_source_account_id,
        'override' => $fresh->autopay_amount_override_cents,
    ];
}

test('autopay is rejected for accounts that are not credit cards with statement data', function (string $case) {
    $account = match ($case) {
        'manual checking' => Account::factory()->checking()->create(['budget_id' => $this->budget->id]),
        'card without statement' => tap(
            Account::factory()->creditCard()->create(['budget_id' => $this->budget->id]),
            fn (Account $card) => ($this->attachPlaid)($card, 'credit', 'credit card')
        ),
    };
    $before = autopayStateOf($account);

    $this->actingAs($this->user)
        ->from(route('budgets.show', $this->budget))
        ->post(route('accounts.updateAutopay', [$this->budget, $account]), [
            'autopay_enabled' => true,
            'autopay_source_account_id' => $this->checking->id,
            'autopay_amount_override_cents' => null,
        ])
        ->assertRedirect(route('budgets.show', $this->budget))
        ->assertSessionHas('error', 'Autopay is only available for credit card accounts with statement data.');

    expect(autopayStateOf($account))->toBe($before);
})->with(['manual checking', 'card without statement']);

test('autopay is rejected for a missing, non-depository or self source account', function (string $case, string $error) {
    $source = match ($case) {
        'missing' => null,
        'other credit card' => tap(
            Account::factory()->creditCard()->create(['budget_id' => $this->budget->id]),
            fn (Account $card) => ($this->attachPlaid)($card, 'credit', 'credit card')
        )->id,
        'manual checking' => Account::factory()->checking()->create(['budget_id' => $this->budget->id])->id,
        'the card itself' => $this->card->id,
    };
    $before = autopayStateOf($this->card);

    $this->actingAs($this->user)
        ->from(route('budgets.show', $this->budget))
        ->post(route('accounts.updateAutopay', [$this->budget, $this->card]), [
            'autopay_enabled' => true,
            'autopay_source_account_id' => $source,
            'autopay_amount_override_cents' => null,
        ])
        ->assertRedirect(route('budgets.show', $this->budget))
        ->assertSessionHas('error', $error);

    expect(autopayStateOf($this->card))->toBe($before);
})->with([
    'missing' => ['missing', 'Please select a source account for autopay.'],
    'other credit card' => ['other credit card', 'Source account must be a checking or savings account.'],
    'manual checking' => ['manual checking', 'Source account must be a checking or savings account.'],
    'the card itself' => ['the card itself', 'Source account must be a checking or savings account.'],
]);

test('enabling autopay with a checking source saves the configuration', function () {
    $this->actingAs($this->user)
        ->post(route('accounts.updateAutopay', [$this->budget, $this->card]), [
            'autopay_enabled' => true,
            'autopay_source_account_id' => $this->checking->id,
            'autopay_amount_override_cents' => 5000,
        ])
        ->assertSessionHas('message');

    expect(autopayStateOf($this->card))->toBe(['enabled' => true, 'source' => $this->checking->id, 'override' => 5000]);
});

test('disabling autopay without sending the optional fields clears the configuration', function () {
    $this->card->update(['autopay_enabled' => true, 'autopay_source_account_id' => $this->checking->id, 'autopay_amount_override_cents' => 5000]);

    $this->actingAs($this->user)
        ->post(route('accounts.updateAutopay', [$this->budget, $this->card]), ['autopay_enabled' => false])
        ->assertRedirect()
        ->assertSessionHas('message', 'Autopay disabled successfully.');

    expect(autopayStateOf($this->card))->toBe(['enabled' => false, 'source' => null, 'override' => null]);
});

test('autopay cannot be configured on another users account', function () {
    $other = User::factory()->create();

    $this->actingAs($other)
        ->post(route('accounts.updateAutopay', [$this->budget, $this->card]), [
            'autopay_enabled' => true,
            'autopay_source_account_id' => $this->checking->id,
        ])
        ->assertForbidden();

    expect(autopayStateOf($this->card)['enabled'])->toBeFalse();
});
