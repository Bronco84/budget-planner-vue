<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\RecurringTransactionTemplate;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia;

/*
 * PROJECT-001: account balance projection pages. Time is frozen on Wednesday
 * 2026-03-04, so the series starts with a point for today (03-04), the first
 * weekly bucket covers Thu 03-05 .. Sun 03-08 and later buckets end on Sundays
 * (03-15, 03-22, ...).
 */

beforeEach(function () {
    $this->travelTo('2026-03-04 09:00:00');

    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
});

function projectionTemplate(Account $account, array $attributes): RecurringTransactionTemplate
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

/** Map weekly buckets to [end-of-week date => balance]. */
function projectionBalancesByWeek(array $days): array
{
    return collect($days)->mapWithKeys(fn ($d) => [$d['date'] => $d['balance']])->all();
}

test('owner sees the weekly account projection rolled forward from the current balance', function () {
    $checking = Account::factory()->checking()->create([
        'budget_id' => $this->budget->id,
        'current_balance_cents' => 50000,
    ]);
    projectionTemplate($checking, ['description' => 'Paycheck', 'amount_in_cents' => 100000, 'day_of_month' => 15]);
    Transaction::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $checking->id,
        'description' => 'Concert tickets',
        'amount_in_cents' => -20000,
        'date' => '2026-03-20',
    ]);
    // Already posted, so it must not be projected again.
    Transaction::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $checking->id,
        'amount_in_cents' => -99999,
        'date' => '2026-02-20',
    ]);

    $this->actingAs($this->user)
        // Default window (3 months).
        ->get(route('budget.account.projections', [$this->budget, $checking]))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) {
            $page->component('Accounts/Projections')
                ->where('monthsAhead', 3)
                ->has('projectedTransactions', 4)
                ->has('balanceProjection.days');

            $props = $page->toArray()['props'];
            $balances = projectionBalancesByWeek($props['balanceProjection']['days']);

            expect(array_key_first($balances))->toBe('2026-03-04')
                ->and($balances['2026-03-04'])->toBe(50000)    // today
                ->and($balances['2026-03-08'])->toBe(50000)    // nothing before the 15th
                ->and($balances['2026-03-15'])->toBe(150000)   // + paycheck
                ->and($balances['2026-03-22'])->toBe(130000)   // - concert tickets
                ->and($balances['2026-04-19'])->toBe(230000)   // + April paycheck
                ->and(end($balances))->toBe(330000);           // + May paycheck

            $descriptions = collect($props['projectedTransactions'])->pluck('description')->all();
            expect($descriptions)->toBe(['Paycheck', 'Concert tickets', 'Paycheck', 'Paycheck']);
        });
});

test('the projection series starts today and does not include days before today', function () {
    $checking = Account::factory()->checking()->create([
        'budget_id' => $this->budget->id,
        'current_balance_cents' => 50000,
    ]);
    // Due today (Wednesday 03-04) and on 03-07 later this week.
    projectionTemplate($checking, ['description' => 'Allowance', 'amount_in_cents' => 1000, 'day_of_month' => 4]);
    projectionTemplate($checking, ['description' => 'Coffee', 'amount_in_cents' => -300, 'day_of_month' => 7]);

    $this->actingAs($this->user)
        ->get(route('budget.account.balance-projection', [$this->budget, $checking, 'months' => 1]))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) {
            $days = $page->toArray()['props']['balanceProjection']['days'];

            expect($days[0])->toBe(['date' => '2026-03-04', 'balance' => 51000, 'income' => 1000, 'expense' => 0])
                // The rest of this week (03-05 .. 03-08) holds only the 03-07 coffee.
                ->and($days[1])->toBe(['date' => '2026-03-08', 'balance' => 50700, 'income' => 0, 'expense' => 300])
                ->and(collect($days)->pluck('date')->min())->toBe('2026-03-04')
                // Every amount is counted exactly once: +1000 x2 (03-04, 04-04) and -300 once (03-07).
                ->and(end($days)['balance'])->toBe(50000 + 2000 - 300)
                ->and(collect($days)->sum('income'))->toBe(2000)
                ->and(collect($days)->sum('expense'))->toBe(300);
        });
});

test('balance projection page defaults to twelve months and renders the detailed projection', function () {
    $checking = Account::factory()->checking()->create([
        'budget_id' => $this->budget->id,
        'current_balance_cents' => 10000,
    ]);
    projectionTemplate($checking, ['description' => 'Rent', 'amount_in_cents' => -5000, 'day_of_month' => 10]);

    $this->actingAs($this->user)
        ->get(route('budget.account.balance-projection', [$this->budget, $checking]))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($checking) {
            $page->component('Accounts/BalanceProjection')
                ->where('budget.id', $this->budget->id)
                ->where('account.id', $checking->id)
                ->where('monthsAhead', 12)
                // Mar 10 2026 .. Feb 10 2027 inclusive (end is 2027-03-04).
                ->has('projectedTransactions', 12);

            $days = $page->toArray()['props']['balanceProjection']['days'];
            $balances = projectionBalancesByWeek($days);

            expect($balances['2026-03-08'])->toBe(10000)
                ->and($balances['2026-03-15'])->toBe(5000)
                ->and(end($balances))->toBe(10000 - 12 * 5000)
                ->and(end($days)['date'] >= '2027-03-04')->toBeTrue();
        });
});

test('credit card projection increases with purchases and decreases with payments', function () {
    $card = Account::factory()->creditCard()->create([
        'budget_id' => $this->budget->id,
        'current_balance_cents' => 100000,
        'autopay_enabled' => false,
    ]);
    projectionTemplate($card, ['description' => 'Streaming', 'amount_in_cents' => -5000, 'day_of_month' => 10]);
    Transaction::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $card->id,
        'description' => 'Card payment',
        'amount_in_cents' => 30000,
        'date' => '2026-03-20',
    ]);

    $this->actingAs($this->user)
        ->get(route('budget.account.balance-projection', [$this->budget, $card]).'?months=1')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) {
            $page->component('Accounts/BalanceProjection')->where('monthsAhead', 1);

            $balances = projectionBalancesByWeek($page->toArray()['props']['balanceProjection']['days']);

            expect($balances['2026-03-08'])->toBe(100000)
                ->and($balances['2026-03-15'])->toBe(105000)  // purchase raises the debt
                ->and($balances['2026-03-22'])->toBe(75000)   // payment lowers it
                ->and(end($balances))->toBe(75000);
        });
});

test('a monthly template on day 31 lands on the last day of shorter months', function () {
    $this->travelTo('2026-01-05 09:00:00');

    $checking = Account::factory()->checking()->create([
        'budget_id' => $this->budget->id,
        'current_balance_cents' => 0,
    ]);
    projectionTemplate($checking, ['description' => 'Month end', 'amount_in_cents' => 1000, 'day_of_month' => 31]);

    $this->actingAs($this->user)
        ->get(route('budget.account.balance-projection', [$this->budget, $checking]).'?months=4')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) {
            $dates = collect($page->toArray()['props']['projectedTransactions'])
                ->map(fn ($t) => Carbon::parse($t['date'])->setTimezone(config('app.timezone'))->toDateString())
                ->all();

            expect($dates)->toBe(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30']);
        });
});

test('an account with nothing scheduled projects a flat line at the current balance', function () {
    $savings = Account::factory()->savings()->create([
        'budget_id' => $this->budget->id,
        'current_balance_cents' => 25000,
    ]);

    $this->actingAs($this->user)
        ->get(route('budget.account.balance-projection', [$this->budget, $savings]))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) {
            $page->has('projectedTransactions', 0);

            $days = $page->toArray()['props']['balanceProjection']['days'];

            expect($days)->not->toBeEmpty()
                ->and(collect($days)->pluck('balance')->unique()->values()->all())->toBe([25000])
                ->and(collect($days)->sum('income') + collect($days)->sum('expense'))->toBe(0);
        });
});

test('a zero balance account projects from zero and can go negative', function () {
    $checking = Account::factory()->checking()->create([
        'budget_id' => $this->budget->id,
        'current_balance_cents' => 0,
    ]);
    projectionTemplate($checking, ['description' => 'Gym', 'amount_in_cents' => -10000, 'day_of_month' => 15]);

    $this->actingAs($this->user)
        ->get(route('budget.account.projections', [$this->budget, $checking]))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) {
            $page->component('Accounts/Projections')->where('monthsAhead', 3);

            $balances = projectionBalancesByWeek($page->toArray()['props']['balanceProjection']['days']);

            expect($balances['2026-03-08'])->toBe(0)
                ->and($balances['2026-03-15'])->toBe(-10000)
                ->and(end($balances))->toBe(-30000);
        });
});

// --- Authorization for the detailed balance-projection route ---
// (budget.account.projections is covered in NestedRouteAuthorizationTest.php.)

test('balance projection rejects an account from another budget with 404', function () {
    $foreignAccount = Account::factory()->create();

    $this->actingAs($this->user)
        ->get(route('budget.account.balance-projection', [$this->budget, $foreignAccount]))
        ->assertNotFound();
});

test('balance projection rejects another users budget and account with 403', function () {
    $otherBudget = Budget::factory()->create();
    $otherAccount = Account::factory()->create(['budget_id' => $otherBudget->id]);

    $this->actingAs($this->user)
        ->get(route('budget.account.balance-projection', [$otherBudget, $otherAccount]))
        ->assertForbidden();
});

test('account projections honour an explicit months parameter', function () {
    $checking = Account::factory()->checking()->create([
        'budget_id' => $this->budget->id,
        'current_balance_cents' => 0,
    ]);
    projectionTemplate($checking, ['description' => 'Paycheck', 'amount_in_cents' => 1000, 'day_of_month' => 15]);

    $this->actingAs($this->user)
        ->get(route('budget.account.projections', [$this->budget, $checking, 'months' => 6]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Accounts/Projections')
            ->where('monthsAhead', 6)
            // Mar 15 .. Aug 15 2026 (end is 2026-09-04).
            ->has('projectedTransactions', 6));
});

test('projection pages reject an invalid months parameter', function (string $routeName, mixed $months) {
    $checking = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);

    $this->actingAs($this->user)
        ->get(route($routeName, [$this->budget, $checking, 'months' => $months]))
        ->assertRedirect()
        ->assertSessionHasErrors('months');
})->with([
    'projections, zero' => ['budget.account.projections', 0],
    'projections, too large' => ['budget.account.projections', 61],
    'projections, not a number' => ['budget.account.projections', 'abc'],
    'balance projection, negative' => ['budget.account.balance-projection', -3],
    'balance projection, too large' => ['budget.account.balance-projection', 1000],
]);
