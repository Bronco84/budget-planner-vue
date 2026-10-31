<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\PayoffPlan;
use App\Models\PayoffPlanDebt;
use App\Models\PayoffPlanGoal;
use App\Services\PayoffPlanService;
use Carbon\Carbon;

/*
 * Tests for the debt-payoff projection math in PayoffPlanService:
 * calculatePayoffProjection (month 1 pays before interest, later months accrue
 * interest first; minimum payments; extra-payment focus; live balances) and
 * calculateGoalProjections (goals funded after the debts are paid off).
 */

beforeEach(function () {
    // Plans below start in the future, so projections start on the plan's start date.
    $this->travelTo('2025-12-15 09:00:00');
});

function makePlan(Budget $budget, string $strategy, int $extraCents, array $debts, array $goals = []): PayoffPlan
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
            'current_balance_cents' => $debt['current_balance'] ?? $debt['balance'],
        ]);

        PayoffPlanDebt::create([
            'payoff_plan_id' => $plan->id,
            'account_id' => $account->id,
            'starting_balance_cents' => $debt['starting_balance'] ?? $debt['balance'],
            'interest_rate' => $debt['interest_rate'],
            'minimum_payment_cents' => $debt['minimum_payment'],
            'priority' => $debt['priority'] ?? 0,
        ]);
    }

    foreach ($goals as $goal) {
        PayoffPlanGoal::create(array_merge([
            'payoff_plan_id' => $plan->id,
            'goal_type' => 'savings',
            'priority' => 0,
        ], $goal));
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

test('accrues interest month over month after the first payment', function () {
    $budget = Budget::factory()->create();
    $plan = makePlan($budget, 'avalanche', 0, [
        ['balance' => 100000, 'interest_rate' => 12, 'minimum_payment' => 50000],
    ]);

    $result = app(PayoffPlanService::class)->calculatePayoffProjection($plan);

    // 12% APR = 1%/month. Month 1 pays before any interest: 100000 - 50000 = 50000.
    // M2: +500 interest = 50500 - 50000 = 500. M3: +5 = 505, paid off. 505 cents total.
    expect($result['total_months'])->toBe(3)
        ->and($result['timeline'][0]['debts'][0]['balance'])->toBe(50000)
        ->and($result['timeline'][0]['debts'][0]['total_interest_paid'])->toBe(0)
        ->and($result['timeline'][1]['debts'][0]['balance'])->toBe(500)
        ->and($result['total_interest_paid'])->toBe(505)
        ->and($result['final_debt_data'][0]['balance'])->toBe(0);
});

test('the first month applies the payment before any interest is charged', function () {
    $budget = Budget::factory()->create();
    // 24% APR (2%/month) on $1,000 with a $1,000 minimum: month 1 clears it with no interest.
    $plan = makePlan($budget, 'avalanche', 0, [
        ['balance' => 100000, 'interest_rate' => 24, 'minimum_payment' => 100000],
    ]);

    $result = app(PayoffPlanService::class)->calculatePayoffProjection($plan);

    expect($result['total_months'])->toBe(1)
        ->and($result['total_interest_paid'])->toBe(0)
        ->and($result['final_debt_data'][0]['paid_off_month'])->toBe(1);
});

test('avalanche pays no more interest than snowball', function () {
    $budget = Budget::factory()->create();

    // Higher-rate debt has the larger balance, so avalanche (rate-first) and
    // snowball (balance-first) target different debts, and avalanche should cost
    // no more in total interest.
    $debts = [
        ['balance' => 60000, 'interest_rate' => 24, 'minimum_payment' => 5000],
        ['balance' => 40000, 'interest_rate' => 6, 'minimum_payment' => 5000],
    ];

    $service = app(PayoffPlanService::class);
    $avalanche = $service->calculatePayoffProjection(makePlan($budget, 'avalanche', 20000, $debts));
    $snowball = $service->calculatePayoffProjection(makePlan($budget, 'snowball', 20000, $debts));

    expect($avalanche['total_interest_paid'])->toBeLessThanOrEqual($snowball['total_interest_paid']);
});

test('projects from the debt accounts current balances and keeps the starting balance for progress', function () {
    $budget = Budget::factory()->create();
    // Started at $1,200; the card is now down to $600. $100/month at 0% => 6 months left.
    $plan = makePlan($budget, 'avalanche', 0, [
        ['starting_balance' => 120000, 'current_balance' => 60000, 'interest_rate' => 0, 'minimum_payment' => 10000],
    ]);

    $result = app(PayoffPlanService::class)->calculatePayoffProjection($plan);

    expect($result['total_months'])->toBe(6)
        ->and($result['timeline'][0]['debts'][0]['balance'])->toBe(50000)
        ->and($result['final_debt_data'][0]['starting_balance'])->toBe(120000)
        ->and($result['final_debt_data'][0]['current_balance'])->toBe(60000)
        ->and($result['final_debt_data'][0]['paid_down_since_start'])->toBe(60000);
});

test('a liability stored with a negative balance is projected as a positive debt', function () {
    $budget = Budget::factory()->create();
    $plan = makePlan($budget, 'avalanche', 0, [
        ['starting_balance' => 30000, 'current_balance' => -30000, 'interest_rate' => 0, 'minimum_payment' => 10000],
    ]);

    $result = app(PayoffPlanService::class)->calculatePayoffProjection($plan);

    expect($result['total_months'])->toBe(3)
        ->and($result['final_debt_data'][0]['current_balance'])->toBe(30000);
});

test('goal contributions start after the debts are paid off and freed payments roll into goals in priority order', function () {
    $budget = Budget::factory()->create();
    // Debt: $1,200 at 0%, $100 minimum + $100 extra => paid off in month 6, freeing $200/month.
    // From month 7 each goal gets its own $100, and the freed $200 goes to the first unfinished goal.
    // M7: A 100+200=300, B 100. M8: A +100 => 400, +100 of freed => 500 done, B +100+100 => 300.
    // M9: B +100 own, +$100 from A's finished contribution and the freed $200 => needs 300 => 600 done.
    $plan = makePlan($budget, 'avalanche', 10000, [
        ['balance' => 120000, 'interest_rate' => 0, 'minimum_payment' => 10000],
    ], [
        ['name' => 'Second', 'target_amount_cents' => 60000, 'monthly_contribution_cents' => 10000, 'priority' => 2],
        ['name' => 'First', 'target_amount_cents' => 50000, 'monthly_contribution_cents' => 10000, 'priority' => 1],
    ]);

    $goals = collect(app(PayoffPlanService::class)->calculateGoalProjections($plan))->keyBy('name');

    expect($goals['First']['contributions_start_month'])->toBe(7)
        ->and($goals['First']['contributions_start_date'])->toBe('2026-07-01')
        ->and($goals['First']['months_to_complete'])->toBe(8)
        ->and($goals['First']['completion_date'])->toBe('2026-09-01')
        ->and($goals['Second']['months_to_complete'])->toBe(9)
        ->and($goals['Second']['completion_date'])->toBe('2026-10-01');
});

test('a goal with no contribution of its own is funded by the freed debt payments', function () {
    $budget = Budget::factory()->create();
    // $300 debt at $100/month => paid off in month 3; the goal then gets the freed $100/month.
    $plan = makePlan($budget, 'avalanche', 0, [
        ['balance' => 30000, 'interest_rate' => 0, 'minimum_payment' => 10000],
    ], [
        ['name' => 'Someday', 'target_amount_cents' => 25000, 'monthly_contribution_cents' => 0],
    ]);

    $goal = app(PayoffPlanService::class)->calculateGoalProjections($plan)[0];

    // Months 4, 5, 6 => $300 >= $250, complete in month 6.
    expect($goal['months_to_complete'])->toBe(6);
});

test('goals are never funded while the debts are never paid off', function () {
    $budget = Budget::factory()->create();
    $plan = makePlan($budget, 'avalanche', 0, [
        ['balance' => 100000, 'interest_rate' => 24, 'minimum_payment' => 1000],
    ], [
        ['name' => 'Vacation', 'target_amount_cents' => 10000, 'monthly_contribution_cents' => 5000],
    ]);

    $goal = app(PayoffPlanService::class)->calculateGoalProjections($plan)[0];

    expect($goal['contributions_start_month'])->toBeNull()
        ->and($goal['months_to_complete'])->toBeNull()
        ->and($goal['completion_date'])->toBeNull();
});

test('rolls freed minimums from a paid-off debt into the next debt', function () {
    $budget = Budget::factory()->create();
    // Snowball, 0% APR: A $250 (min $50), B $1,000 (min $100), extra $50 => $200/month total.
    // M1 A150 B900, M2 A50 B800, M3 A0 + $50 leftover to B => B650,
    // then B gets the full $200: M4 450, M5 250, M6 50, M7 0.
    $plan = makePlan($budget, 'snowball', 5000, [
        ['balance' => 25000, 'interest_rate' => 0, 'minimum_payment' => 5000],
        ['balance' => 100000, 'interest_rate' => 0, 'minimum_payment' => 10000],
    ]);

    $result = app(PayoffPlanService::class)->calculatePayoffProjection($plan);

    expect($result['total_months'])->toBe(7)
        ->and($result['never_paid_off'])->toBeFalse()
        ->and($result['final_debt_data'][0]['paid_off_month'])->toBe(3)
        ->and($result['final_debt_data'][1]['paid_off_month'])->toBe(7)
        ->and($result['timeline'][3]['debts'][1]['balance'])->toBe(45000);
});

test('rolls the extra payment remainder into the next debt in the same month', function () {
    $budget = Budget::factory()->create();
    // Snowball, 0% APR: A $100 (min $10), B $1,000 (min $100), extra $200 => $310/month.
    // M1: minimums leave A90 B900; the $200 pays A's $90 and the $110 remainder goes to B => B790.
    // M2 480, M3 170, M4 0.
    $plan = makePlan($budget, 'snowball', 20000, [
        ['balance' => 10000, 'interest_rate' => 0, 'minimum_payment' => 1000],
        ['balance' => 100000, 'interest_rate' => 0, 'minimum_payment' => 10000],
    ]);

    $result = app(PayoffPlanService::class)->calculatePayoffProjection($plan);

    expect($result['timeline'][0]['debts'][0]['balance'])->toBe(0)
        ->and($result['timeline'][0]['debts'][1]['balance'])->toBe(79000)
        ->and($result['timeline'][0]['total_payment'])->toBe(31000)
        ->and($result['final_debt_data'][0]['paid_off_month'])->toBe(1)
        ->and($result['final_debt_data'][1]['paid_off_month'])->toBe(4)
        ->and($result['total_months'])->toBe(4);
});

test('flags a plan whose payments never cover the interest', function () {
    $budget = Budget::factory()->create();
    // 24% APR on $1,000 = $20/month interest against a $10 minimum: balance only grows.
    $plan = makePlan($budget, 'avalanche', 0, [
        ['balance' => 100000, 'interest_rate' => 24, 'minimum_payment' => 1000],
    ]);

    $result = app(PayoffPlanService::class)->calculatePayoffProjection($plan);

    expect($result['never_paid_off'])->toBeTrue()
        ->and($result['payoff_date'])->toBeNull()
        ->and($result['final_debt_data'][0]['never_paid_off'])->toBeTrue()
        ->and($result['final_debt_data'][0]['paid_off_month'])->toBeNull()
        ->and($result['final_debt_data'][0]['balance'])->toBeGreaterThan(100000);
});
