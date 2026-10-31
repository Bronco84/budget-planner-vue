<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\PlaidAccount;
use App\Models\PlaidConnection;
use App\Models\RecurringTransactionTemplate;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/*
 * PROJECT-001: credit-card autopay projections on the source account's balance
 * projection. Time is frozen on Wednesday 2026-03-04; the one-month window ends
 * 2026-04-04, so only the first (statement balance) payment on 03-20 falls in it.
 */

beforeEach(function () {
    $this->travelTo('2026-03-04 09:00:00');

    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
});

test('credit card autopay is projected on the source account and replaces the linked template payment', function () {
    $checking = Account::factory()->checking()->create([
        'budget_id' => $this->budget->id,
        'current_balance_cents' => 100000,
    ]);
    $card = Account::factory()->creditCard()->create([
        'budget_id' => $this->budget->id,
        'current_balance_cents' => 45000,
        'autopay_enabled' => true,
        'autopay_source_account_id' => $checking->id,
    ]);
    $connection = PlaidConnection::create([
        'budget_id' => $this->budget->id,
        'plaid_item_id' => 'item-autopay-projection',
        'institution_name' => 'Test Bank',
        'access_token' => 'access-sandbox-test',
        'status' => PlaidConnection::STATUS_ACTIVE,
    ]);
    PlaidAccount::create([
        'plaid_connection_id' => $connection->id,
        'account_id' => $card->id,
        'plaid_account_id' => 'plaid-card',
        'account_name' => 'Visa',
        'account_type' => 'credit',
        'account_subtype' => 'credit card',
        'last_statement_balance_cents' => 30000,
        'next_payment_due_date' => '2026-03-20',
    ]);
    // A manual "pay the card" template on checking; autopay must take over its future occurrences.
    RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $checking->id,
        'linked_credit_card_account_id' => $card->id,
        'description' => 'Card payment',
        'amount_in_cents' => -12345,
        'frequency' => RecurringTransactionTemplate::FREQUENCY_MONTHLY,
        'day_of_month' => 18,
        'start_date' => '2026-01-01',
        'end_date' => null,
        'is_dynamic_amount' => false,
    ]);

    $this->actingAs($this->user)
        ->get(route('budget.account.balance-projection', [$this->budget, $checking, 'months' => 1]))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($card) {
            $page->component('Accounts/BalanceProjection')->has('projectedTransactions', 1);

            $props = $page->toArray()['props'];
            $autopay = $props['projectedTransactions'][0];

            expect($autopay['projection_source'])->toBe('autopay')
                ->and($autopay['amount_in_cents'])->toBe(-30000)
                ->and($autopay['source_account_id'])->toBe($card->id)
                ->and(substr((string) $autopay['date'], 0, 10))->toBe('2026-03-20');

            $balances = collect($props['balanceProjection']['days'])->mapWithKeys(fn ($d) => [$d['date'] => $d['balance']])->all();

            expect($balances['2026-03-15'])->toBe(100000)
                ->and($balances['2026-03-22'])->toBe(70000)
                ->and(end($balances))->toBe(70000);
        });
});
