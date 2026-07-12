<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\PayoffPlan;
use App\Models\PayoffPlanDebt;
use App\Services\PayoffPlanService;
use Carbon\Carbon;

/*
 * Characterization tests for the debt-payoff projection math. These lock in the
 * current month-by-month behavior of PayoffPlanService::calculatePayoffProjection
 * (interest accrual, minimum payments, extra-payment focus) and the avalanche vs
 * snowball ordering used by compareStrategies.
 */

function makePlan(Budget $budget, string $strategy, int $extraCents, array $debts): PayoffPlan
{
    $plan = PayoffPlan::create([
        'budget_id' => $budget->id,
        'name' => 'Test Plan',
        'strategy' => $strategy,
        'monthly_extra_payment_cents' => $extraCents,
        'is_active' => true,
        'start_date' => Carbon::parse('2026-01-01'),
    ]);

    foreach ($debts as $debt) {
        $account = Account::factory()->creditCard()->create([
            'budget_id' => $budget->id,
            'current_balance_cents' => $debt['balance'],
        ]);

        PayoffPlanDebt::create([
            'payoff_plan_id' => $plan->id,
            'account_id' => $account->id,
            'starting_balance_cents' => $debt['balance'],
            'interest_rate' => $debt['interest_rate'],
            'minimum_payment_cents' => $debt['minimum_payment'],
            'priority' => $debt['priority'] ?? 0,
        ]);
    }

    return $plan->fresh();
}

test('pays off a zero-interest debt over the expected number of months', function () {
    $budget = Budget::factory()->create();
    $plan = makePlan($budget, 'avalanche', 0, [
        ['balance' => 120000, 'interest_rate' => 0, 'minimum_payment' => 10000],
    ]);

    $result = app(PayoffPlanService::class)->calculatePayoffProjection($plan);

    // $1,200 at $100/month with no interest = 12 months.
    expect($result['total_months'])->toBe(12)
        ->and($result['total_interest_paid'])->toBe(0)
        ->and($result['final_debt_data'][0]['balance'])->toBe(0);
});

test('extra payments shorten the payoff timeline', function () {
    $budget = Budget::factory()->create();
    $plan = makePlan($budget, 'avalanche', 10000, [
        ['balance' => 120000, 'interest_rate' => 0, 'minimum_payment' => 10000],
    ]);

    $result = app(PayoffPlanService::class)->calculatePayoffProjection($plan);

    // $100 minimum + $100 extra = $200/month against $1,200 = 6 months.
    expect($result['total_months'])->toBe(6)
        ->and($result['final_debt_data'][0]['balance'])->toBe(0);
});

test('accrues interest month over month', function () {
    $budget = Budget::factory()->create();
    $plan = makePlan($budget, 'avalanche', 0, [
        ['balance' => 100000, 'interest_rate' => 12, 'minimum_payment' => 50000],
    ]);

    $result = app(PayoffPlanService::class)->calculatePayoffProjection($plan);

    // 12% APR = 1%/month. Traced: 1000 + 510 + 15 = 1525 cents of interest over 3 months.
    expect($result['total_months'])->toBe(3)
        ->and($result['total_interest_paid'])->toBe(1525)
        ->and($result['final_debt_data'][0]['balance'])->toBe(0);
});

test('avalanche pays no more interest than snowball', function () {
    $budget = Budget::factory()->create();

    // Higher-rate debt has the larger balance, so avalanche (rate-first) and
    // snowball (balance-first) target different debts, and avalanche should cost
    // no more in total interest.
    $accountA = Account::factory()->creditCard()->create(['budget_id' => $budget->id]);
    $accountB = Account::factory()->creditCard()->create(['budget_id' => $budget->id]);

    $debtData = [
        ['account_id' => $accountA->id, 'balance' => 60000, 'interest_rate' => 24, 'minimum_payment' => 5000, 'priority' => 0],
        ['account_id' => $accountB->id, 'balance' => 40000, 'interest_rate' => 6, 'minimum_payment' => 5000, 'priority' => 0],
    ];

    $comparison = app(PayoffPlanService::class)->compareStrategies($budget, $debtData, 20000);

    expect($comparison['avalanche']['total_interest_paid'])
        ->toBeLessThanOrEqual($comparison['snowball']['total_interest_paid']);
});
