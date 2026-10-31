<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\PayoffPlan;
use App\Models\PayoffPlanDebt;
use App\Models\PayoffPlanGoal;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/*
 * HTTP-level coverage for PAYOFF-002: the owner opens a payoff plan and receives
 * the month-by-month debt projection and goal projections as Inertia props.
 */

beforeEach(function () {
    $this->travelTo('2026-03-04 09:00:00');

    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->card = Account::factory()->creditCard()->create([
        'budget_id' => $this->budget->id,
        'name' => 'Store Card',
        'current_balance_cents' => 120000,
    ]);

    $this->plan = PayoffPlan::create([
        'budget_id' => $this->budget->id,
        'name' => 'Card payoff',
        'strategy' => 'snowball',
        'monthly_extra_payment_cents' => 0,
        'is_active' => true,
        'start_date' => '2026-04-01',
    ]);
});

function projectionAddDebt(PayoffPlan $plan, Account $account, int $balance, float $apr, int $minimum): PayoffPlanDebt
{
    return PayoffPlanDebt::create([
        'payoff_plan_id' => $plan->id,
        'account_id' => $account->id,
        'starting_balance_cents' => $balance,
        'interest_rate' => $apr,
        'minimum_payment_cents' => $minimum,
        'priority' => 0,
    ]);
}

test('owner sees the plan page with the month-by-month projection and goal estimates', function () {
    // $1,200 at 0% APR with a $100 minimum.
    projectionAddDebt($this->plan, $this->card, 120000, 0, 10000);
    $goal = PayoffPlanGoal::create([
        'payoff_plan_id' => $this->plan->id,
        'name' => 'Vacation',
        'target_amount_cents' => 100000,
        'monthly_contribution_cents' => 30000,
        'goal_type' => 'savings',
    ]);

    $this->actingAs($this->user)
        ->get(route('payoff-plans.show', [$this->budget, $this->plan]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('PayoffPlans/Show')
            ->where('budget.id', $this->budget->id)
            ->where('plan.id', $this->plan->id)
            ->has('plan.debts', 1)
            ->where('plan.debts.0.account.name', 'Store Card')
            ->where('plan.debts.0.starting_balance_cents', 120000)
            ->where('plan.debts.0.current_balance_cents', 120000)
            ->where('plan.debts.0.paid_down_cents', 0)
            ->has('plan.goals', 1)
            ->where('debtProjection.projection_start_date', '2026-04-01')
            ->where('debtProjection.total_months', 12)
            ->has('debtProjection.timeline', 12)
            ->where('debtProjection.timeline.0.date', '2026-04-01')
            ->where('debtProjection.timeline.0.total_balance', 110000)
            ->where('debtProjection.timeline.11.date', '2027-03-01')
            ->where('debtProjection.timeline.11.total_balance', 0)
            ->where('debtProjection.payoff_date', '2027-04-01')
            ->where('debtProjection.total_interest_paid', 0)
            ->where('debtProjection.final_debt_data.0.balance', 0)
            ->has('goalProjections', 1)
            ->where('goalProjections.0.goal_id', $goal->id)
            // Goal funding starts in month 13, after the debt is paid off: its $300
            // plus the freed $100 minimum = $400/month => $1,000 reached in month 15.
            ->where('goalProjections.0.contributions_start_month', 13)
            ->where('goalProjections.0.contributions_start_date', '2027-04-01')
            ->where('goalProjections.0.months_to_complete', 15)
            ->where('goalProjections.0.completion_date', '2027-07-01'));
});

test('the projection uses the debt accounts current balance and shows how much was paid down since the start', function () {
    // The plan started at $1,200; the card now owes $500 (stored as a negative balance).
    projectionAddDebt($this->plan, $this->card, 120000, 0, 10000);
    $this->card->update(['current_balance_cents' => -50000]);

    $this->actingAs($this->user)
        ->get(route('payoff-plans.show', [$this->budget, $this->plan]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('plan.debts.0.starting_balance_cents', 120000)
            ->where('plan.debts.0.current_balance_cents', 50000)
            ->where('plan.debts.0.paid_down_cents', 70000)
            ->where('debtProjection.total_months', 5)
            ->where('debtProjection.timeline.0.total_balance', 40000));
});

test('a plan that started in the past is projected from today', function () {
    $this->plan->update(['start_date' => '2026-01-01']);
    projectionAddDebt($this->plan, $this->card, 120000, 0, 10000);

    $this->actingAs($this->user)
        ->get(route('payoff-plans.show', [$this->budget, $this->plan]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('debtProjection.projection_start_date', '2026-03-04')
            ->where('debtProjection.timeline.0.date', '2026-03-04')
            ->where('debtProjection.payoff_date', '2027-03-04'));
});

test('a plan whose debts are all paid off has an empty timeline and zero months', function () {
    projectionAddDebt($this->plan, $this->card, 120000, 18, 2500);
    $this->card->update(['current_balance_cents' => 0]);

    $this->actingAs($this->user)
        ->get(route('payoff-plans.show', [$this->budget, $this->plan]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('PayoffPlans/Show')
            ->has('debtProjection.timeline', 0)
            ->where('debtProjection.total_months', 0)
            ->where('debtProjection.payoff_date', '2026-04-01')
            ->where('debtProjection.total_interest_paid', 0));
});

test('a goal with no monthly contribution is funded by the freed debt payments', function () {
    projectionAddDebt($this->plan, $this->card, 120000, 0, 10000);
    PayoffPlanGoal::create([
        'payoff_plan_id' => $this->plan->id,
        'name' => 'Someday',
        'target_amount_cents' => 50000,
        'monthly_contribution_cents' => 0,
        'goal_type' => 'other',
    ]);

    // The freed $100/month from month 13 reaches $500 in month 17.
    $this->actingAs($this->user)
        ->get(route('payoff-plans.show', [$this->budget, $this->plan]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('goalProjections', 1)
            ->where('goalProjections.0.name', 'Someday')
            ->where('goalProjections.0.months_to_complete', 17)
            ->where('goalProjections.0.completion_date', '2027-09-01'));
});

test('a goal has no completion estimate while the debts are never paid off', function () {
    projectionAddDebt($this->plan, $this->card, 120000, 24, 1000);
    PayoffPlanGoal::create([
        'payoff_plan_id' => $this->plan->id,
        'name' => 'Someday',
        'target_amount_cents' => 50000,
        'monthly_contribution_cents' => 10000,
        'goal_type' => 'other',
    ]);

    $this->actingAs($this->user)
        ->get(route('payoff-plans.show', [$this->budget, $this->plan]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('goalProjections.0.contributions_start_month', null)
            ->where('goalProjections.0.months_to_complete', null)
            ->where('goalProjections.0.completion_date', null));
});

test('a plan whose payments never cover the interest is flagged as never paid off', function () {
    // 24% APR on $1,200 = $24/month interest against a $10 minimum.
    projectionAddDebt($this->plan, $this->card, 120000, 24, 1000);

    $this->actingAs($this->user)
        ->get(route('payoff-plans.show', [$this->budget, $this->plan]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('PayoffPlans/Show')
            ->where('debtProjection.never_paid_off', true)
            ->where('debtProjection.payoff_date', null)
            ->where('debtProjection.final_debt_data.0.never_paid_off', true));
});
