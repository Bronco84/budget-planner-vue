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
 * REPORT-001: the reports dashboard as a whole: default render, account filter,
 * empty budget, income vs expenses normalisation and authorization.
 * Time is frozen on 2026-06-15, so the default 6-month range starts 2025-12-15.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(now()->setDate(2026, 6, 15)->setTime(12, 0));

    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
});

function reportsDashboardTx(Account $account, int $amount, string $date, array $attributes = []): Transaction
{
    return Transaction::factory()->create(array_merge([
        'budget_id' => $account->budget_id,
        'account_id' => $account->id,
        'amount_in_cents' => $amount,
        'date' => $date,
        'category' => 'Groceries',
    ], $attributes));
}

function reportsDashboardTemplate(Budget $budget, array $attributes): RecurringTransactionTemplate
{
    return RecurringTransactionTemplate::factory()->create(array_merge([
        'budget_id' => $budget->id,
        'account_id' => null,
        'friendly_label' => null,
        'category' => null,
        'is_dynamic_amount' => false,
        'frequency' => RecurringTransactionTemplate::FREQUENCY_MONTHLY,
        'start_date' => '2026-01-01',
        'end_date' => null,
    ], $attributes));
}

test('owner opens reports with the default six month range and receives every section', function () {
    $checking = Account::factory()->checking()->create(['budget_id' => $this->budget->id, 'current_balance_cents' => 100000]);
    reportsDashboardTx($checking, 300000, '2026-06-01', ['category' => 'Income']);
    reportsDashboardTx($checking, -4000, '2026-06-03');

    $this->actingAs($this->user)
        ->get(route('reports.index', $this->budget))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Reports/Index')
            ->where('budget.id', $this->budget->id)
            ->has('accounts', 1)
            ->where('selectedAccountId', null)
            ->where('dateRange', ['start' => '2025-12-15', 'end' => '2026-06-15', 'preset' => '6months'])
            ->has('overview.netWorth')
            ->has('overview.cashFlow')
            ->has('netWorth.dataPoints')
            ->where('netWorth.summary.currentNetWorth', 100000)
            ->where('cashFlow.summary.totalIncome', 300000)
            ->where('cashFlow.summary.totalExpenses', 4000)
            ->has('budgetPerformance.categories')
            ->where('spendingPatterns.totalTransactions', 1)
            ->has('debtPayoff.debts')
            ->has('incomeVsExpenses.totals')
        );
});

test('selecting an account limits cash flow performance and spending to that account', function () {
    $checking = Account::factory()->checking()->create(['budget_id' => $this->budget->id, 'current_balance_cents' => 100000]);
    $savings = Account::factory()->savings()->create(['budget_id' => $this->budget->id, 'current_balance_cents' => 500000]);
    Category::create(['budget_id' => $this->budget->id, 'name' => 'Groceries', 'amount' => 100, 'order' => 1]);
    reportsDashboardTx($checking, 200000, '2026-06-01', ['category' => 'Income']);
    reportsDashboardTx($checking, -3000, '2026-06-05');
    reportsDashboardTx($savings, 900000, '2026-06-02', ['category' => 'Income']);
    reportsDashboardTx($savings, -7000, '2026-06-06');

    $this->actingAs($this->user)
        ->get(route('reports.index', [$this->budget, 'account_id' => $checking->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selectedAccountId', (string) $checking->id)
            ->where('netWorth.summary.currentAssets', 100000)
            ->where('cashFlow.summary.totalIncome', 200000)
            ->where('cashFlow.summary.totalExpenses', 3000)
            ->where('budgetPerformance.categories.0.category', 'Groceries')
            ->where('budgetPerformance.categories.0.spent', 3000)
            ->where('spendingPatterns.totalTransactions', 1)
            ->where('spendingPatterns.totalSpending', 3000)
        );
});

test('an account id from another budget is ignored and all accounts are shown', function () {
    $checking = Account::factory()->checking()->create(['budget_id' => $this->budget->id, 'current_balance_cents' => 100000]);
    $savings = Account::factory()->savings()->create(['budget_id' => $this->budget->id, 'current_balance_cents' => 50000]);
    reportsDashboardTx($checking, -1000, '2026-06-05');
    reportsDashboardTx($savings, -2000, '2026-06-06');

    $foreignBudget = Budget::factory()->create(['user_id' => User::factory()->create()->id]);
    $foreignAccount = Account::factory()->checking()->create(['budget_id' => $foreignBudget->id, 'current_balance_cents' => 999999]);

    $this->actingAs($this->user)
        ->get(route('reports.index', [$this->budget, 'account_id' => $foreignAccount->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selectedAccountId', null)
            ->where('netWorth.summary.currentAssets', 150000)
            ->where('spendingPatterns.totalTransactions', 2)
            ->where('spendingPatterns.totalSpending', 3000)
        );
});

test('income vs expenses uses only active templates', function () {
    reportsDashboardTemplate($this->budget, ['description' => 'Salary', 'amount_in_cents' => 300000]);
    reportsDashboardTemplate($this->budget, ['description' => 'Ends today', 'amount_in_cents' => -1000, 'end_date' => '2026-06-15']);
    reportsDashboardTemplate($this->budget, ['description' => 'Ended', 'amount_in_cents' => -2000, 'end_date' => '2026-06-14']);
    reportsDashboardTemplate($this->budget, ['description' => 'Not started', 'amount_in_cents' => -4000, 'start_date' => '2026-06-16']);

    $this->actingAs($this->user)
        ->get(route('reports.index', $this->budget))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $data = $page->toArray()['props']['incomeVsExpenses'];

            expect(collect($data['incomeItems'])->pluck('description')->all())->toBe(['Salary'])
                ->and(collect($data['expenseItems'])->pluck('amount_in_cents')->all())->toBe([-1000])
                ->and($data['totals']['monthly_expenses'])->toBe(1000);
        });
});

test('income vs expenses normalises each frequency to a monthly amount', function () {
    $cases = [
        RecurringTransactionTemplate::FREQUENCY_WEEKLY => [-10000, -43300],     // x4.33
        RecurringTransactionTemplate::FREQUENCY_BIWEEKLY => [-10000, -21700],   // x2.17
        RecurringTransactionTemplate::FREQUENCY_BIMONTHLY => [-10000, -20000],  // twice a month
        RecurringTransactionTemplate::FREQUENCY_QUARTERLY => [-30000, -10000],  // /3
        RecurringTransactionTemplate::FREQUENCY_YEARLY => [-120000, -10000],    // /12
        RecurringTransactionTemplate::FREQUENCY_MONTHLY => [-10000, -10000],
    ];
    foreach ($cases as $frequency => [$amount]) {
        reportsDashboardTemplate($this->budget, ['description' => $frequency, 'amount_in_cents' => $amount, 'frequency' => $frequency]);
    }

    $this->actingAs($this->user)
        ->get(route('reports.index', $this->budget))
        ->assertOk()
        ->assertInertia(function (Assert $page) use ($cases) {
            $data = $page->toArray()['props']['incomeVsExpenses'];
            $monthly = collect($data['expenseItems'])->pluck('monthly_amount', 'description')->all();

            foreach ($cases as $frequency => [, $expected]) {
                expect($monthly[$frequency])->toBe($expected);
            }

            expect($data['totals']['monthly_expenses'])->toBe(43300 + 21700 + 20000 + 10000 + 10000 + 10000);
        });
});

test('a starting net worth of zero yields a zero change percent', function () {
    $checking = Account::factory()->checking()->create(['budget_id' => $this->budget->id, 'current_balance_cents' => 50000]);
    reportsDashboardTx($checking, 50000, '2026-06-10', ['category' => 'Income']);

    $this->actingAs($this->user)
        ->get(route('reports.index', $this->budget))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('netWorth.summary.startingNetWorth', 0)
            ->where('netWorth.summary.currentNetWorth', 50000)
            ->where('netWorth.summary.change', 50000)
            ->where('netWorth.summary.changePercent', 0)
            ->where('overview.netWorth.changePercent', 0)
        );
});

test('a budget with no accounts or transactions renders every section with zero values', function () {
    $this->actingAs($this->user)
        ->get(route('reports.index', $this->budget))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Reports/Index')
            ->has('accounts', 0)
            ->where('netWorth.summary.currentNetWorth', 0)
            ->where('netWorth.summary.changePercent', 0)
            ->where('cashFlow.summary.totalIncome', 0)
            ->where('cashFlow.summary.savingsRate', 0)
            ->has('budgetPerformance.categories', 0)
            ->where('spendingPatterns.totalTransactions', 0)
            ->where('spendingPatterns.totalSpending', 0)
            ->has('debtPayoff.debts', 0)
            ->where('incomeVsExpenses.totals.net', 0)
        );
});

test('another user cannot open reports for a budget they do not own', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('reports.index', $this->budget))
        ->assertForbidden();
});
