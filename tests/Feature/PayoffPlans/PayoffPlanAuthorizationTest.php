<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\PayoffPlan;
use App\Models\PayoffPlanDebt;
use App\Models\User;

/*
 * Cross-user (403) and cross-budget (404) denial for every payoff-plan route
 * (PAYOFF-001, PAYOFF-002, PAYOFF-003). A denied request must not change state.
 */

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->intruder = User::factory()->create();

    $this->budget = Budget::factory()->create(['user_id' => $this->owner->id]);
    $this->card = Account::factory()->creditCard()->create([
        'budget_id' => $this->budget->id,
        'current_balance_cents' => 120000,
    ]);

    $this->plan = PayoffPlan::create([
        'budget_id' => $this->budget->id,
        'name' => 'Owner plan',
        'strategy' => 'snowball',
        'monthly_extra_payment_cents' => 0,
        'is_active' => true,
        'start_date' => '2026-01-01',
    ]);
    PayoffPlanDebt::create([
        'payoff_plan_id' => $this->plan->id,
        'account_id' => $this->card->id,
        'starting_balance_cents' => 120000,
        'interest_rate' => 0,
        'minimum_payment_cents' => 10000,
        'priority' => 0,
    ]);

    // The intruder has a budget of their own to address the owner's plan through.
    $this->intruderBudget = Budget::factory()->create(['user_id' => $this->intruder->id]);
});

function payoffAuthzUpdatePayload(): array
{
    return ['name' => 'Hijacked', 'strategy' => 'avalanche', 'monthly_extra_payment_cents' => 99900, 'is_active' => false];
}

function payoffAuthzAssertPlanUntouched(PayoffPlan $plan): void
{
    $fresh = PayoffPlan::find($plan->id);

    expect($fresh)->not->toBeNull()
        ->and($fresh->name)->toBe('Owner plan')
        ->and($fresh->strategy)->toBe('snowball')
        ->and($fresh->monthly_extra_payment_cents)->toBe(0)
        ->and($fresh->is_active)->toBeTrue();
}

// --- Budget-level routes (PAYOFF-001): another user's budget is forbidden ---

test('another user cannot list payoff plans in a budget they do not own', function () {
    $this->actingAs($this->intruder)
        ->get(route('payoff-plans.index', $this->budget))
        ->assertForbidden();
});

test('another user cannot open the payoff plan wizard for a budget they do not own', function () {
    $this->actingAs($this->intruder)
        ->get(route('payoff-plans.create', $this->budget))
        ->assertForbidden();
});

test('another user cannot store a payoff plan in a budget they do not own', function () {
    $this->actingAs($this->intruder)
        ->post(route('payoff-plans.store', $this->budget), [
            'name' => 'Intruder plan',
            'strategy' => 'snowball',
            'monthly_extra_payment_cents' => 0,
            'start_date' => '2026-01-01',
            'debts' => [['account_id' => $this->card->id, 'interest_rate' => 0, 'minimum_payment_cents' => 100]],
        ])
        ->assertForbidden();

    expect(PayoffPlan::count())->toBe(1);
    payoffAuthzAssertPlanUntouched($this->plan);
});

test('another user cannot preview payoff strategies in a budget they do not own', function () {
    $this->actingAs($this->intruder)
        ->postJson(route('payoff-plans.preview', $this->budget), [
            'monthly_extra_payment' => 0,
            'debts' => [['account_id' => $this->card->id, 'balance' => 1000, 'interest_rate' => 0, 'minimum_payment' => 100]],
        ])
        ->assertForbidden();

    expect(PayoffPlan::count())->toBe(1);
});

// --- Plan-level routes (PAYOFF-002/003): foreign budget is 403 ---

test('another user cannot view a payoff plan in a budget they do not own', function () {
    $this->actingAs($this->intruder)
        ->get(route('payoff-plans.show', [$this->budget, $this->plan]))
        ->assertForbidden();
});

test('another user cannot open the edit page of a payoff plan they do not own', function () {
    $this->actingAs($this->intruder)
        ->get(route('payoff-plans.edit', [$this->budget, $this->plan]))
        ->assertForbidden();
});

test('another user cannot update a payoff plan they do not own', function () {
    $this->actingAs($this->intruder)
        ->patch(route('payoff-plans.update', [$this->budget, $this->plan]), payoffAuthzUpdatePayload())
        ->assertForbidden();

    payoffAuthzAssertPlanUntouched($this->plan);
});

test('another user cannot delete a payoff plan they do not own', function () {
    $this->actingAs($this->intruder)
        ->delete(route('payoff-plans.destroy', [$this->budget, $this->plan]))
        ->assertForbidden();

    payoffAuthzAssertPlanUntouched($this->plan);
});

// --- Plan-level routes: a plan addressed through a budget it does not belong to is 404 ---

test('payoff plan routes reject a plan addressed through another budget with 404', function () {
    $this->actingAs($this->intruder)
        ->get(route('payoff-plans.show', [$this->intruderBudget, $this->plan]))
        ->assertNotFound();

    $this->actingAs($this->intruder)
        ->get(route('payoff-plans.edit', [$this->intruderBudget, $this->plan]))
        ->assertNotFound();

    $this->actingAs($this->intruder)
        ->patch(route('payoff-plans.update', [$this->intruderBudget, $this->plan]), payoffAuthzUpdatePayload())
        ->assertNotFound();

    $this->actingAs($this->intruder)
        ->delete(route('payoff-plans.destroy', [$this->intruderBudget, $this->plan]))
        ->assertNotFound();

    payoffAuthzAssertPlanUntouched($this->plan);
});

test('payoff plan routes reject the owners plan addressed through the owners other budget with 404', function () {
    $secondBudget = Budget::factory()->create(['user_id' => $this->owner->id]);

    $this->actingAs($this->owner)
        ->get(route('payoff-plans.show', [$secondBudget, $this->plan]))
        ->assertNotFound();

    $this->actingAs($this->owner)
        ->patch(route('payoff-plans.update', [$secondBudget, $this->plan]), payoffAuthzUpdatePayload())
        ->assertNotFound();

    $this->actingAs($this->owner)
        ->delete(route('payoff-plans.destroy', [$secondBudget, $this->plan]))
        ->assertNotFound();

    payoffAuthzAssertPlanUntouched($this->plan);
});
