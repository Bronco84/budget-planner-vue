<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\RecurringTransactionTemplate;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * REPORT-001: report calculations checked against small hand-computed data.
 * Time is frozen on 2026-06-15.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(now()->setDate(2026, 6, 15)->setTime(12, 0));

    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
});

function reportsTransaction(Account $account, int $amount, string $date, array $attributes = []): Transaction
{
    return Transaction::factory()->create(array_merge([
        'budget_id' => $account->budget_id,
        'account_id' => $account->id,
        'amount_in_cents' => $amount,
        'date' => $date,
    ], $attributes));
}

test('historical net worth rolls liability balances back in the opposite direction to assets', function () {
    $checking = Account::factory()->checking()->create([
        'budget_id' => $this->budget->id,
        'current_balance_cents' => 100000,
    ]);
    $card = Account::factory()->creditCard()->create([
        'budget_id' => $this->budget->id,
        'current_balance_cents' => 50000, // debt owed today
        'autopay_enabled' => false,
    ]);
    reportsTransaction($checking, 20000, '2026-06-05'); // deposit after the start date
    reportsTransaction($card, -10000, '2026-06-10');     // purchase after the start date

    $this->actingAs($this->user)
        ->get(route('reports.index', [
            $this->budget,
            'date_range' => 'custom',
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-15',
        ]))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $netWorth = $page->toArray()['props']['netWorth'];
            $first = $netWorth['dataPoints'][0];

            // On 06-01 checking held 100000 - 20000 and the card owed 50000 - 10000.
            expect($first['date'])->toBe('2026-06-01')
                ->and($first['assets'])->toBe(80000)
                ->and($first['liabilities'])->toBe(40000)
                ->and($first['netWorth'])->toBe(40000)
                ->and($netWorth['summary']['currentAssets'])->toBe(100000)
                ->and($netWorth['summary']['currentLiabilities'])->toBe(50000)
                ->and($netWorth['summary']['change'])->toBe(10000);
        });
});

test('budget performance prorates the monthly category allocation over the selected range', function () {
    $checking = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
    Category::create([
        'budget_id' => $this->budget->id,
        'name' => 'Groceries',
        'amount' => 300, // $300 per month
        'color' => '#00ff00',
    ]);
    reportsTransaction($checking, -45000, '2026-02-10', ['category' => 'Groceries']);

    $this->actingAs($this->user)
        ->get(route('reports.index', [
            $this->budget,
            'date_range' => 'custom',
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-31',
        ]))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $groceries = collect($page->toArray()['props']['budgetPerformance']['categories'])
                ->firstWhere('category', 'Groceries');

            // Three full months x $300 = $900 allocated; $450 spent.
            expect($groceries['allocated'])->toBe(90000)
                ->and($groceries['spent'])->toBe(45000)
                ->and($groceries['remaining'])->toBe(45000)
                ->and((float) $groceries['percentUsed'])->toBe(50.0)
                ->and($groceries['isOverBudget'])->toBeFalse();
        });
});

test('budget performance prorates partial months by the days covered', function () {
    Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
    Category::create([
        'budget_id' => $this->budget->id,
        'name' => 'Dining',
        'amount' => 310,
        'color' => '#ff0000',
    ]);

    // 2026-05-17 .. 2026-06-15: 15/31 of May + 15/30 of June = 0.98387 months.
    $this->actingAs($this->user)
        ->get(route('reports.index', [
            $this->budget,
            'date_range' => 'custom',
            'start_date' => '2026-05-17',
            'end_date' => '2026-06-15',
        ]))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $dining = collect($page->toArray()['props']['budgetPerformance']['categories'])
                ->firstWhere('category', 'Dining');

            // 31000 * 15/31 + 31000 * 15/30 = 15000 + 15500
            expect($dining['allocated'])->toBe(30500);
        });
});

test('income vs expenses includes dynamic-amount templates at their projected amount', function () {
    $checking = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
    $utilities = RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $checking->id,
        'description' => 'Electric bill',
        'amount_in_cents' => 0,
        'is_dynamic_amount' => true,
        'frequency' => RecurringTransactionTemplate::FREQUENCY_MONTHLY,
        'start_date' => '2026-01-01',
    ]);
    RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $checking->id,
        'description' => 'Salary',
        'amount_in_cents' => 300000,
        'is_dynamic_amount' => false,
        'frequency' => RecurringTransactionTemplate::FREQUENCY_MONTHLY,
        'start_date' => '2026-01-01',
    ]);
    // The projected amount of a dynamic template is the average of its linked transactions.
    reportsTransaction($checking, -12000, '2026-04-05', ['recurring_transaction_template_id' => $utilities->id]);
    reportsTransaction($checking, -8000, '2026-05-05', ['recurring_transaction_template_id' => $utilities->id]);

    $this->actingAs($this->user)
        ->get(route('reports.index', $this->budget))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $data = $page->toArray()['props']['incomeVsExpenses'];
            $electric = collect($data['expenseItems'])->firstWhere('description', 'Electric bill');

            expect($electric)->not->toBeNull()
                ->and($electric['monthly_amount'])->toBe(-10000)
                ->and($data['totals']['monthly_income'])->toBe(300000)
                ->and($data['totals']['monthly_expenses'])->toBe(10000)
                ->and($data['totals']['net'])->toBe(290000);
        });
});
