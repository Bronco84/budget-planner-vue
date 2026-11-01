<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/*
 * REPORT-001: cash flow series pinned for a small fixture. The transactions
 * are bucketed by month once; these values must not change with how the
 * bucketing is done (PR-033).
 */

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(now()->setDate(2026, 6, 15)->setTime(12, 0));

    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);

    $this->checking = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
    $this->card = Account::factory()->creditCard()->create(['budget_id' => $this->budget->id]);

    $rows = [
        [$this->checking, '2025-12-31', 99900],   // before the range
        [$this->checking, '2026-01-01', 250000],  // first day of the range
        [$this->checking, '2026-01-31', -4500],   // last day of a month
        [$this->card, '2026-01-15', -15000],
        [$this->checking, '2026-02-01', -80000],  // first day of the next month
        [$this->card, '2026-02-20', 40000],
        [$this->checking, '2026-02-28', 0],       // neither income nor expense
        // March has no transactions
        [$this->checking, '2026-04-10', 250000],
        [$this->checking, '2026-04-30', -12000],  // on the end date
        [$this->checking, '2026-05-01', -30000],  // after the range
    ];

    foreach ($rows as [$account, $date, $amount]) {
        Transaction::create([
            'budget_id' => $this->budget->id,
            'account_id' => $account->id,
            'description' => 'Fixture',
            'category' => 'Misc',
            'amount_in_cents' => $amount,
            'date' => $date,
        ]);
    }
});

function cashFlowReport($test, array $query = []): array
{
    $response = $test->actingAs($test->user)->get(route('reports.index', array_merge([
        $test->budget,
        'date_range' => 'custom',
        'start_date' => '2026-01-01',
        'end_date' => '2026-04-30',
    ], $query)));

    $response->assertOk();

    return $response->viewData('page')['props']['cashFlow'];
}

test('cash flow monthly totals for all accounts match the pinned values', function () {
    $cashFlow = cashFlowReport($this);

    expect($cashFlow['monthly'])->toBe([
        ['month' => 'Jan 2026', 'income' => 250000, 'expenses' => 19500, 'netCashFlow' => 230500],
        ['month' => 'Feb 2026', 'income' => 40000, 'expenses' => 80000, 'netCashFlow' => -40000],
        ['month' => 'Mar 2026', 'income' => 0, 'expenses' => 0, 'netCashFlow' => 0],
        ['month' => 'Apr 2026', 'income' => 250000, 'expenses' => 12000, 'netCashFlow' => 238000],
    ])->and($cashFlow['summary'])->toMatchArray([
        'totalIncome' => 540000,
        'totalExpenses' => 111500,
        'netCashFlow' => 428500,
    ])->and($cashFlow['summary']['avgMonthlyIncome'])->toEqual(135000)
        ->and($cashFlow['summary']['avgMonthlyExpenses'])->toEqual(27875)
        ->and($cashFlow['summary']['avgNetCashFlow'])->toEqual(107125)
        ->and($cashFlow['summary']['savingsRate'])->toEqualWithDelta(79.351851851851, 1e-9);
});

test('cash flow filtered to one account matches the pinned values', function () {
    $cashFlow = cashFlowReport($this, ['account_id' => $this->card->id]);

    expect($cashFlow['monthly'])->toBe([
        ['month' => 'Jan 2026', 'income' => 0, 'expenses' => 15000, 'netCashFlow' => -15000],
        ['month' => 'Feb 2026', 'income' => 40000, 'expenses' => 0, 'netCashFlow' => 40000],
        ['month' => 'Mar 2026', 'income' => 0, 'expenses' => 0, 'netCashFlow' => 0],
        ['month' => 'Apr 2026', 'income' => 0, 'expenses' => 0, 'netCashFlow' => 0],
    ]);
});

test('all time cash flow keeps one entry per month from January 2000 to now', function () {
    $response = $this->actingAs($this->user)->get(route('reports.index', [$this->budget, 'date_range' => 'all']));
    $response->assertOk();
    $cashFlow = $response->viewData('page')['props']['cashFlow'];

    // Jan 2000 through Jun 2026 inclusive.
    expect($cashFlow['monthly'])->toHaveCount(318)
        ->and($cashFlow['monthly'][0]['month'])->toBe('Jan 2000')
        ->and(end($cashFlow['monthly'])['month'])->toBe('Jun 2026')
        ->and($cashFlow['summary']['totalIncome'])->toBe(639900)
        ->and($cashFlow['summary']['totalExpenses'])->toBe(141500);
});
