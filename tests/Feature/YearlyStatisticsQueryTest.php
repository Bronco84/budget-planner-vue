<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * REPORT-002: Budget::getYearlyStatistics output pinned for a small fixture,
 * plus a guard that it no longer runs four SUM queries per month.
 */

beforeEach(function () {
    $this->budget = Budget::factory()->create(['user_id' => User::factory()->create()->id]);
    $account = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);

    $other = Budget::factory()->create(['user_id' => User::factory()->create()->id]);
    $otherAccount = Account::factory()->checking()->create(['budget_id' => $other->id]);

    $rows = [
        [$this->budget, $account, '2026-01-01', 300000],
        [$this->budget, $account, '2026-01-31', -45000],
        [$this->budget, $account, '2026-01-15', -5000],
        [$this->budget, $account, '2026-02-01', 310000],
        [$this->budget, $account, '2026-02-28', -60000],
        [$this->budget, $account, '2026-06-30', 125000],
        [$this->budget, $account, '2026-12-31', -99900],
        [$this->budget, $account, '2026-07-04', 0],
        [$this->budget, $account, '2025-01-10', 250000],
        [$this->budget, $account, '2025-01-20', -50000],
        [$this->budget, $account, '2025-02-14', -80000],
        [$this->budget, $account, '2025-06-01', 100000],
        [$this->budget, $account, '2025-12-01', -1000],
        [$this->budget, $account, '2024-12-31', 777700],  // two years back: excluded
        [$this->budget, $account, '2027-01-01', -888800], // next year: excluded
        [$other, $otherAccount, '2026-01-10', 999900],   // other budget: excluded
    ];

    foreach ($rows as [$budget, $acct, $date, $amount]) {
        Transaction::create([
            'budget_id' => $budget->id,
            'account_id' => $acct->id,
            'description' => 'Fixture',
            'category' => 'Misc',
            'amount_in_cents' => $amount,
            'date' => $date,
        ]);
    }
});

test('yearly statistics match the pinned values', function () {
    expect($this->budget->getYearlyStatistics(2026))->toBe([
        'monthly' => [
            'January' => [
                'total_income' => 300000,
                'total_expenses' => -50000,
                'prev_year_income' => 250000,
                'prev_year_expenses' => -50000,
                'month_number' => 1,
                'year' => 2026,
                'income_change' => 20.0,
                'expenses_change' => 0,
            ],
            'February' => [
                'total_income' => 310000,
                'total_expenses' => -60000,
                'prev_year_income' => 0,
                'prev_year_expenses' => -80000,
                'month_number' => 2,
                'year' => 2026,
                'income_change' => null,
                'expenses_change' => 25.0,
            ],
            'March' => [
                'total_income' => 0,
                'total_expenses' => 0,
                'prev_year_income' => 0,
                'prev_year_expenses' => 0,
                'month_number' => 3,
                'year' => 2026,
                'income_change' => null,
                'expenses_change' => null,
            ],
            'April' => [
                'total_income' => 0,
                'total_expenses' => 0,
                'prev_year_income' => 0,
                'prev_year_expenses' => 0,
                'month_number' => 4,
                'year' => 2026,
                'income_change' => null,
                'expenses_change' => null,
            ],
            'May' => [
                'total_income' => 0,
                'total_expenses' => 0,
                'prev_year_income' => 0,
                'prev_year_expenses' => 0,
                'month_number' => 5,
                'year' => 2026,
                'income_change' => null,
                'expenses_change' => null,
            ],
            'June' => [
                'total_income' => 125000,
                'total_expenses' => 0,
                'prev_year_income' => 100000,
                'prev_year_expenses' => 0,
                'month_number' => 6,
                'year' => 2026,
                'income_change' => 25.0,
                'expenses_change' => null,
            ],
            'July' => [
                'total_income' => 0,
                'total_expenses' => 0,
                'prev_year_income' => 0,
                'prev_year_expenses' => 0,
                'month_number' => 7,
                'year' => 2026,
                'income_change' => null,
                'expenses_change' => null,
            ],
            'August' => [
                'total_income' => 0,
                'total_expenses' => 0,
                'prev_year_income' => 0,
                'prev_year_expenses' => 0,
                'month_number' => 8,
                'year' => 2026,
                'income_change' => null,
                'expenses_change' => null,
            ],
            'September' => [
                'total_income' => 0,
                'total_expenses' => 0,
                'prev_year_income' => 0,
                'prev_year_expenses' => 0,
                'month_number' => 9,
                'year' => 2026,
                'income_change' => null,
                'expenses_change' => null,
            ],
            'October' => [
                'total_income' => 0,
                'total_expenses' => 0,
                'prev_year_income' => 0,
                'prev_year_expenses' => 0,
                'month_number' => 10,
                'year' => 2026,
                'income_change' => null,
                'expenses_change' => null,
            ],
            'November' => [
                'total_income' => 0,
                'total_expenses' => 0,
                'prev_year_income' => 0,
                'prev_year_expenses' => 0,
                'month_number' => 11,
                'year' => 2026,
                'income_change' => null,
                'expenses_change' => null,
            ],
            'December' => [
                'total_income' => 0,
                'total_expenses' => -99900,
                'prev_year_income' => 0,
                'prev_year_expenses' => -1000,
                'month_number' => 12,
                'year' => 2026,
                'income_change' => null,
                'expenses_change' => -9890.0,
            ],
        ],
        'yearly_totals' => [
            'income' => 735000,
            'expenses' => -209900,
            'prev_year_income' => 350000,
            'prev_year_expenses' => -131000,
            'income_change' => 110.00000000000001,
            'expenses_change' => -60.229007633587784,
        ],
    ]);
});

test('yearly statistics use a constant number of queries instead of four per month', function () {
    DB::enableQueryLog();
    $this->budget->getYearlyStatistics(2026);
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Previously 4 SUM queries x 12 months = 48.
    expect($count)->toBe(1);
});
