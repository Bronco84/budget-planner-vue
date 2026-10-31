<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\RecurringTransactionTemplate;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/*
 * PROJECT-002: budget-wide projected transactions page. Time is frozen on
 * Wednesday 2026-03-04.
 */

beforeEach(function () {
    $this->travelTo('2026-03-04 09:00:00');

    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
});

function budgetProjectionTemplate(Account $account, array $attributes): RecurringTransactionTemplate
{
    return RecurringTransactionTemplate::factory()->create(array_merge([
        'budget_id' => $account->budget_id,
        'account_id' => $account->id,
        'frequency' => RecurringTransactionTemplate::FREQUENCY_MONTHLY,
        'start_date' => '2026-01-01',
        'end_date' => null,
        'is_dynamic_amount' => false,
    ], $attributes));
}

test('owner sees projected transactions grouped by month with totals and running balances', function () {
    $checking = Account::factory()->checking()->create([
        'budget_id' => $this->budget->id,
        'name' => 'Checking',
        'current_balance_cents' => 50000,
    ]);
    $card = Account::factory()->creditCard()->create([
        'budget_id' => $this->budget->id,
        'name' => 'Visa',
        'current_balance_cents' => 20000, // debt
        'autopay_enabled' => false,
    ]);
    $excluded = Account::factory()->savings()->create([
        'budget_id' => $this->budget->id,
        'current_balance_cents' => 99999,
        'exclude_from_total_balance' => true,
    ]);
    budgetProjectionTemplate($checking, ['description' => 'Paycheck', 'amount_in_cents' => 100000, 'day_of_month' => 15]);
    budgetProjectionTemplate($card, ['description' => 'Streaming', 'amount_in_cents' => -5000, 'day_of_month' => 10]);
    budgetProjectionTemplate($excluded, ['description' => 'Ignored', 'amount_in_cents' => 777, 'day_of_month' => 12]);

    $this->actingAs($this->user)
        ->get(route('budget.projections', [$this->budget, 'months' => 2]))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($card) {
            $page->component('Budgets/Projections')
                ->where('budget.id', $this->budget->id)
                ->where('params.months', 2)
                ->where('params.start_date', '2026-03-04')
                // Net worth of included accounts: 50000 - 20000.
                ->where('projections.starting_balance', 30000)
                ->where('projections.monthly_totals', [
                    'March 2026' => ['income' => 100000, 'expense' => 5000, 'net' => 95000, 'ending_balance' => 125000],
                    'April 2026' => ['income' => 100000, 'expense' => 5000, 'net' => 95000, 'ending_balance' => 220000],
                ]);

            $transactions = $page->toArray()['props']['projections']['transactions'];

            expect(array_keys($transactions))->toBe(['March 2026', 'April 2026'])
                // Day keys are non-numeric so JSON keeps them in date order.
                ->and(array_keys($transactions['March 2026']))->toBe(['Tue 10', 'Sun 15']);

            $streaming = $transactions['March 2026']['Tue 10'][0];
            expect($streaming['description'])->toBe('Streaming')
                ->and($streaming['amount_in_cents'])->toBe(-5000)
                ->and($streaming['running_balance'])->toBe(25000)
                ->and($streaming['is_projected'])->toBeTrue()
                ->and($streaming['account'])->toBe(['id' => $card->id, 'name' => 'Visa'])
                ->and($transactions['March 2026']['Sun 15'][0]['running_balance'])->toBe(125000)
                ->and($transactions['April 2026']['Fri 10'][0]['running_balance'])->toBe(120000)
                ->and($transactions['April 2026']['Wed 15'][0]['running_balance'])->toBe(220000);
        });
});

test('budget projections default to six months', function () {
    $this->actingAs($this->user)
        ->get(route('budget.projections', $this->budget))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Budgets/Projections')
            ->where('params.months', 6)
            ->where('params.start_date', '2026-03-04'));
});

test('a budget with no templates has no projected transactions', function () {
    Account::factory()->checking()->create([
        'budget_id' => $this->budget->id,
        'current_balance_cents' => 12345,
    ]);

    $this->actingAs($this->user)
        ->get(route('budget.projections', $this->budget))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('projections.starting_balance', 12345)
            ->where('projections.monthly_totals', [])
            ->where('projections.transactions', []));
});

test('budget projections reject months outside 1-24', function (mixed $months) {
    $this->actingAs($this->user)
        ->get(route('budget.projections', [$this->budget, 'months' => $months]))
        ->assertRedirect()
        ->assertSessionHasErrors('months');
})->with([0, 25, 'abc']);

test('another user cannot view projections for a budget they do not own', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('budget.projections', $this->budget))
        ->assertForbidden();
});
