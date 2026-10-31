<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\PayoffPlan;
use App\Models\PayoffPlanDebt;
use App\Models\User;
use App\Services\PayoffPlanService;

/*
 * PAYOFF-001: debt account ids must belong to the budget in the route, and a
 * failed store or preview must leave no partial state behind.
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

test('preview rejects a debt account from another budget', function () {
    $this->actingAs($this->user)
        ->postJson(route('payoff-plans.preview', $this->budget), [
            'monthly_extra_payment' => 0,
            'debts' => [
                ['account_id' => $this->foreignCard->id, 'balance' => 50000, 'interest_rate' => 10, 'minimum_payment' => 2500],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('debts.0.account_id');

    expect(PayoffPlan::count())->toBe(1)
        ->and(PayoffPlanDebt::count())->toBe(0);
});

test('preview leaves no temporary plans behind when the calculation fails', function () {
    $this->partialMock(PayoffPlanService::class)
        ->shouldReceive('calculatePayoffProjection')
        ->andThrow(new RuntimeException('boom'));

    $this->actingAs($this->user)
        ->postJson(route('payoff-plans.preview', $this->budget), [
            'monthly_extra_payment' => 0,
            'debts' => [
                ['account_id' => $this->card->id, 'balance' => 250000, 'interest_rate' => 20, 'minimum_payment' => 7500],
            ],
        ])
        ->assertServerError();

    expect(PayoffPlan::count())->toBe(1)
        ->and(PayoffPlanDebt::count())->toBe(0);
});
