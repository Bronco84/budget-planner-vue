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
        'start_date' => '2026-01-01',
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
            ->has('plan.goals', 1)
            ->where('debtProjection.total_months', 12)
            ->has('debtProjection.timeline', 12)
            ->where('debtProjection.timeline.0.date', '2026-01-01')
            ->where('debtProjection.timeline.0.total_balance', 110000)
            ->where('debtProjection.timeline.11.date', '2026-12-01')
            ->where('debtProjection.timeline.11.total_balance', 0)
            ->where('debtProjection.payoff_date', '2027-01-01')
            ->where('debtProjection.total_interest_paid', 0)
            ->where('debtProjection.final_debt_data.0.balance', 0)
            ->has('goalProjections', 1)
            ->where('goalProjections.0.goal_id', $goal->id)
            // ceil($1,000 / $300) = 4 months from the plan start.
            ->where('goalProjections.0.months_to_complete', 4)
            ->where('goalProjections.0.completion_date', '2026-05-01'));
});

test('a plan whose debts all start at zero balance has an empty timeline and zero months', function () {
    projectionAddDebt($this->plan, $this->card, 0, 18, 2500);

    $this->actingAs($this->user)
        ->get(route('payoff-plans.show', [$this->budget, $this->plan]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('PayoffPlans/Show')
            ->has('debtProjection.timeline', 0)
            ->where('debtProjection.total_months', 0)
            ->where('debtProjection.payoff_date', '2026-01-01')
            ->where('debtProjection.total_interest_paid', 0));
});

test('a goal with no monthly contribution has no completion estimate', function () {
    projectionAddDebt($this->plan, $this->card, 120000, 0, 10000);
    PayoffPlanGoal::create([
        'payoff_plan_id' => $this->plan->id,
        'name' => 'Someday',
        'target_amount_cents' => 500000,
        'monthly_contribution_cents' => 0,
        'goal_type' => 'other',
    ]);

    $this->actingAs($this->user)
        ->get(route('payoff-plans.show', [$this->budget, $this->plan]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('goalProjections', 1)
            ->where('goalProjections.0.name', 'Someday')
            ->where('goalProjections.0.months_to_complete', null)
            ->where('goalProjections.0.completion_date', null));
});
