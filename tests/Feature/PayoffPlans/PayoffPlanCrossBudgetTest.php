<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\PayoffPlan;
use App\Models\PayoffPlanDebt;
use App\Models\User;

/*
 * PAYOFF-001: debt account ids must belong to the budget in the route, and a
 * failed store must leave no partial state behind.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->card = Account::factory()->creditCard()->create([
        'budget_id' => $this->budget->id,
        'current_balance_cents' => 250000,
    ]);

    $this->foreignCard = Account::factory()->creditCard()->create([
        'budget_id' => Budget::factory()->create(['user_id' => User::factory()->create()->id])->id,
        'current_balance_cents' => 50000,
    ]);

    $this->activePlan = $this->budget->payoffPlans()->create([
        'name' => 'Existing plan',
        'strategy' => 'snowball',
        'monthly_extra_payment_cents' => 0,
        'start_date' => '2026-01-01',
        'is_active' => true,
    ]);
});

function crossBudgetPlanPayload(array $debts): array
{
    return [
        'name' => 'New plan',
        'strategy' => 'avalanche',
        'monthly_extra_payment_cents' => 5000,
        'start_date' => '2026-04-01',
        'debts' => $debts,
        'goals' => [],
    ];
}

test('storing a plan with a debt account from another budget is rejected and the active plan is untouched', function () {
    $this->actingAs($this->user)
        ->post(route('payoff-plans.store', $this->budget), crossBudgetPlanPayload([
            ['account_id' => $this->card->id, 'interest_rate' => 20, 'minimum_payment_cents' => 5000],
            ['account_id' => $this->foreignCard->id, 'interest_rate' => 10, 'minimum_payment_cents' => 5000],
        ]))
        ->assertSessionHasErrors('debts.1.account_id');

    expect(PayoffPlan::count())->toBe(1)
        ->and($this->activePlan->fresh()->is_active)->toBeTrue()
        ->and(PayoffPlanDebt::count())->toBe(0);
});

test('a failure while storing a plan rolls back and keeps the existing active plan', function () {
    PayoffPlanDebt::creating(fn () => throw new RuntimeException('boom'));

    $this->actingAs($this->user)
        ->post(route('payoff-plans.store', $this->budget), crossBudgetPlanPayload([
            ['account_id' => $this->card->id, 'interest_rate' => 20, 'minimum_payment_cents' => 5000],
        ]))
        ->assertServerError();

    expect(PayoffPlan::count())->toBe(1)
        ->and($this->activePlan->fresh()->is_active)->toBeTrue();
});
