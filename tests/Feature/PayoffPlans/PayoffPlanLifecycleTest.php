<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\PayoffPlan;
use App\Models\PayoffPlanDebt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/*
 * HTTP-level coverage for PAYOFF-001 (create) and PAYOFF-003 (edit/delete):
 * index, wizard, store, preview, edit, update and destroy as the budget owner.
 */

beforeEach(function () {
    $this->travelTo('2026-03-04 09:00:00');

    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);

    $this->card = Account::factory()->creditCard()->create([
        'budget_id' => $this->budget->id,
        'name' => 'Visa',
        'current_balance_cents' => 250000,
    ]);
});

function lifecyclePlanPayload(array $overrides = []): array
{
    return array_replace([
        'name' => 'Get out of debt',
        'description' => 'Visa first',
        'strategy' => 'avalanche',
        'monthly_extra_payment_cents' => 5000,
        'start_date' => '2026-04-01',
        'debts' => [],
        'goals' => [],
    ], $overrides);
}

function lifecycleCreatePlan(Budget $budget, Account $account, array $attributes = []): PayoffPlan
{
    $plan = PayoffPlan::create(array_merge([
        'budget_id' => $budget->id,
        'name' => 'Existing plan',
        'strategy' => 'snowball',
        'monthly_extra_payment_cents' => 0,
        'is_active' => true,
        'start_date' => '2026-01-01',
    ], $attributes));

    PayoffPlanDebt::create([
        'payoff_plan_id' => $plan->id,
        'account_id' => $account->id,
        'starting_balance_cents' => 120000,
        'interest_rate' => 0,
        'minimum_payment_cents' => 10000,
        'priority' => 0,
    ]);

    return $plan;
}

// --- PAYOFF-001: index ---

test('index lists the budgets plans with the active plan first', function () {
    $inactive = lifecycleCreatePlan($this->budget, $this->card, ['name' => 'Old', 'is_active' => false]);
    $active = lifecycleCreatePlan($this->budget, $this->card, ['name' => 'Current', 'is_active' => true]);

    $otherBudget = Budget::factory()->create(['user_id' => $this->user->id]);
    $otherCard = Account::factory()->creditCard()->create(['budget_id' => $otherBudget->id]);
    lifecycleCreatePlan($otherBudget, $otherCard, ['name' => 'Elsewhere']);

    $this->actingAs($this->user)
        ->get(route('payoff-plans.index', $this->budget))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('PayoffPlans/Index')
            ->where('budget.id', $this->budget->id)
            ->has('plans', 2)
            ->where('plans.0.id', $active->id)
            ->where('plans.1.id', $inactive->id)
            ->has('plans.0.debts', 1)
            ->where('plans.0.debts.0.account.id', $this->card->id));
});

test('index shows an empty list when the budget has no plans', function () {
    $this->actingAs($this->user)
        ->get(route('payoff-plans.index', $this->budget))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('PayoffPlans/Index')
            ->has('plans', 0));
});

// --- PAYOFF-001: wizard ---

test('create wizard offers only liability accounts with a positive balance', function () {
    Account::factory()->checking()->create(['budget_id' => $this->budget->id, 'current_balance_cents' => 900000]);
    Account::factory()->creditCard()->create(['budget_id' => $this->budget->id, 'current_balance_cents' => 0]);

    $this->actingAs($this->user)
        ->get(route('payoff-plans.create', $this->budget))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('PayoffPlans/Create')
            ->where('budget.id', $this->budget->id)
            ->has('availableCashFlow')
            ->has('debtAccounts', 1)
            ->where('debtAccounts.0.id', $this->card->id)
            ->where('debtAccounts.0.current_balance_cents', 250000));
});

test('create wizard has no debt accounts when the budget has no liabilities', function () {
    $this->card->delete();
    Account::factory()->savings()->create(['budget_id' => $this->budget->id, 'current_balance_cents' => 100000]);

    $this->actingAs($this->user)
        ->get(route('payoff-plans.create', $this->budget))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('PayoffPlans/Create')
            ->has('debtAccounts', 0));
});

// --- PAYOFF-001: store ---

test('storing a plan persists debts and goals, makes it the only active plan and redirects to it', function () {
    $previous = lifecycleCreatePlan($this->budget, $this->card, ['is_active' => true]);

    $response = $this->actingAs($this->user)
        ->post(route('payoff-plans.store', $this->budget), lifecyclePlanPayload([
            'debts' => [[
                'account_id' => $this->card->id,
                'interest_rate' => 19.99,
                'minimum_payment_cents' => 7500,
                'priority' => 1,
            ]],
            'goals' => [[
                'name' => 'Emergency fund',
                'target_amount_cents' => 100000,
                'monthly_contribution_cents' => 25000,
                'goal_type' => 'savings',
            ]],
        ]));

    $plan = PayoffPlan::where('name', 'Get out of debt')->firstOrFail();

    $response->assertRedirect(route('payoff-plans.show', [$this->budget, $plan]));

    expect($plan->budget_id)->toBe($this->budget->id)
        ->and($plan->is_active)->toBeTrue()
        ->and($plan->strategy)->toBe('avalanche')
        ->and($plan->monthly_extra_payment_cents)->toBe(5000)
        ->and($plan->start_date->toDateString())->toBe('2026-04-01')
        ->and($previous->fresh()->is_active)->toBeFalse()
        ->and($this->budget->payoffPlans()->where('is_active', true)->count())->toBe(1);

    $debt = $plan->debts()->sole();
    expect($debt->account_id)->toBe($this->card->id)
        ->and($debt->starting_balance_cents)->toBe(250000)
        ->and((float) $debt->interest_rate)->toBe(19.99)
        ->and($debt->minimum_payment_cents)->toBe(7500)
        ->and($debt->priority)->toBe(1);

    $goal = $plan->goals()->sole();
    expect($goal->name)->toBe('Emergency fund')
        ->and($goal->target_amount_cents)->toBe(100000)
        ->and($goal->monthly_contribution_cents)->toBe(25000);
});

test('storing a plan rejects invalid wizard input without creating anything', function (Closure $overrides, string $errorKey) {
    $previous = lifecycleCreatePlan($this->budget, $this->card, ['is_active' => true]);

    $validDebt = ['account_id' => $this->card->id, 'interest_rate' => 10, 'minimum_payment_cents' => 5000];
    $payload = lifecyclePlanPayload(array_replace(['debts' => [$validDebt]], $overrides($validDebt)));

    $this->actingAs($this->user)
        ->from(route('payoff-plans.create', $this->budget))
        ->post(route('payoff-plans.store', $this->budget), $payload)
        ->assertRedirect(route('payoff-plans.create', $this->budget))
        ->assertSessionHasErrors($errorKey);

    expect(PayoffPlan::count())->toBe(1)
        ->and($previous->fresh()->is_active)->toBeTrue();
})->with([
    'missing debts' => [fn ($debt) => ['debts' => []], 'debts'],
    'unknown strategy' => [fn ($debt) => ['strategy' => 'lottery'], 'strategy'],
    'negative extra payment' => [fn ($debt) => ['monthly_extra_payment_cents' => -1], 'monthly_extra_payment_cents'],
    'APR above 100' => [fn ($debt) => ['debts' => [array_merge($debt, ['interest_rate' => 100.5])]], 'debts.0.interest_rate'],
]);

test('storing a plan with a debt account from another budget is rejected and attaches no foreign debt', function () {
    $foreignCard = Account::factory()->creditCard()->create(['current_balance_cents' => 50000]);

    $this->actingAs($this->user)
        ->post(route('payoff-plans.store', $this->budget), lifecyclePlanPayload([
            'debts' => [['account_id' => $foreignCard->id, 'interest_rate' => 10, 'minimum_payment_cents' => 5000]],
        ]))
        ->assertSessionHasErrors('debts.0.account_id');

    expect(PayoffPlanDebt::where('account_id', $foreignCard->id)->exists())->toBeFalse();
});

// --- PAYOFF-001: preview ---

test('preview compares snowball and avalanche and leaves no temporary plans behind', function () {
    $smallCheap = Account::factory()->creditCard()->create(['budget_id' => $this->budget->id, 'current_balance_cents' => 50000]);

    $response = $this->actingAs($this->user)
        ->postJson(route('payoff-plans.preview', $this->budget), [
            'monthly_extra_payment' => 20000,
            'debts' => [
                ['account_id' => $smallCheap->id, 'balance' => 50000, 'interest_rate' => 5, 'minimum_payment' => 2500],
                ['account_id' => $this->card->id, 'balance' => 250000, 'interest_rate' => 24, 'minimum_payment' => 7500],
            ],
        ])
        ->assertOk()
        ->assertJsonStructure(['comparisons' => [
            'snowball' => ['strategy', 'total_months', 'payoff_date', 'total_interest_paid'],
            'avalanche' => ['strategy', 'total_months', 'payoff_date', 'total_interest_paid'],
        ]]);

    $comparisons = $response->json('comparisons');
    expect($comparisons['avalanche']['total_interest_paid'])->toBeLessThanOrEqual($comparisons['snowball']['total_interest_paid'])
        ->and($comparisons['snowball']['total_months'])->toBeGreaterThan(0)
        ->and(PayoffPlan::count())->toBe(0)
        ->and(PayoffPlanDebt::count())->toBe(0);
});

test('preview computes the comparison in memory without writing any plan rows', function () {
    $writes = [];
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete)\b.*payoff_plan/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    $response = $this->actingAs($this->user)
        ->postJson(route('payoff-plans.preview', $this->budget), [
            'monthly_extra_payment' => 10000,
            'debts' => [
                ['account_id' => $this->card->id, 'balance' => 120000, 'interest_rate' => 12, 'minimum_payment' => 10000],
            ],
        ])
        ->assertOk();

    // 1,200.00 at 12% APR paid 200.00/month clears in 7 months either way.
    expect($response->json('comparisons.snowball.total_months'))->toBe(7)
        ->and($response->json('comparisons.avalanche.total_months'))->toBe(7)
        ->and($response->json('comparisons.snowball.never_paid_off'))->toBeFalse()
        ->and($writes)->toBe([]);
});

// --- PAYOFF-003: edit / update / destroy ---

test('edit page renders the plan with its debts and goals', function () {
    $plan = lifecycleCreatePlan($this->budget, $this->card);

    $this->actingAs($this->user)
        ->get(route('payoff-plans.edit', [$this->budget, $plan]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('PayoffPlans/Edit')
            ->where('plan.id', $plan->id)
            ->has('plan.debts', 1)
            ->has('plan.goals', 0));
});

test('updating a plan persists the changes and the show page reflects the recalculated projection', function () {
    $plan = lifecycleCreatePlan($this->budget, $this->card, ['monthly_extra_payment_cents' => 0]);

    // $1,200 at 0% with a $100 minimum: 12 months before the update.
    $this->actingAs($this->user)
        ->get(route('payoff-plans.show', [$this->budget, $plan]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('debtProjection.total_months', 12));

    $this->actingAs($this->user)
        ->patch(route('payoff-plans.update', [$this->budget, $plan]), [
            'name' => 'Renamed',
            'description' => 'Faster',
            'strategy' => 'custom',
            'monthly_extra_payment_cents' => 10000,
            'is_active' => false,
        ])
        ->assertRedirect(route('payoff-plans.show', [$this->budget, $plan]));

    $plan->refresh();
    expect($plan->name)->toBe('Renamed')
        ->and($plan->description)->toBe('Faster')
        ->and($plan->strategy)->toBe('custom')
        ->and($plan->monthly_extra_payment_cents)->toBe(10000)
        ->and($plan->is_active)->toBeFalse();

    // $100 minimum + $100 extra against $1,200 = 6 months.
    $this->actingAs($this->user)
        ->get(route('payoff-plans.show', [$this->budget, $plan]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('plan.strategy', 'custom')
            ->where('debtProjection.total_months', 6));
});

test('updating a plan rejects invalid input and leaves the plan unchanged', function (array $overrides, string $errorKey) {
    $plan = lifecycleCreatePlan($this->budget, $this->card, ['name' => 'Original', 'monthly_extra_payment_cents' => 0]);

    $this->actingAs($this->user)
        ->from(route('payoff-plans.edit', [$this->budget, $plan]))
        ->patch(route('payoff-plans.update', [$this->budget, $plan]), array_replace([
            'name' => 'Changed',
            'strategy' => 'avalanche',
            'monthly_extra_payment_cents' => 1000,
        ], $overrides))
        ->assertRedirect(route('payoff-plans.edit', [$this->budget, $plan]))
        ->assertSessionHasErrors($errorKey);

    $plan->refresh();
    expect($plan->name)->toBe('Original')
        ->and($plan->strategy)->toBe('snowball')
        ->and($plan->monthly_extra_payment_cents)->toBe(0);
})->with([
    'unknown strategy' => [['strategy' => 'lottery'], 'strategy'],
    'negative extra payment' => [['monthly_extra_payment_cents' => -500], 'monthly_extra_payment_cents'],
    'missing name' => [['name' => ''], 'name'],
]);

test('deleting a plan removes it with its debts and redirects to the plans index', function () {
    $plan = lifecycleCreatePlan($this->budget, $this->card);

    $this->actingAs($this->user)
        ->delete(route('payoff-plans.destroy', [$this->budget, $plan]))
        ->assertRedirect(route('payoff-plans.index', $this->budget));

    expect(PayoffPlan::find($plan->id))->toBeNull()
        ->and(PayoffPlanDebt::where('payoff_plan_id', $plan->id)->exists())->toBeFalse()
        ->and($this->card->fresh())->not->toBeNull();
});

test('activating a plan through update deactivates the budgets other plans', function () {
    $previous = lifecycleCreatePlan($this->budget, $this->card, ['name' => 'Current', 'is_active' => true]);
    $plan = lifecycleCreatePlan($this->budget, $this->card, ['name' => 'Draft', 'is_active' => false]);

    $otherBudget = Budget::factory()->create(['user_id' => $this->user->id]);
    $otherCard = Account::factory()->creditCard()->create(['budget_id' => $otherBudget->id]);
    $otherBudgetPlan = lifecycleCreatePlan($otherBudget, $otherCard, ['is_active' => true]);

    $this->actingAs($this->user)
        ->patch(route('payoff-plans.update', ['budget' => $this->budget, 'payoff_plan' => $plan]), [
            'name' => 'Draft',
            'strategy' => 'snowball',
            'monthly_extra_payment_cents' => 0,
            'is_active' => true,
        ])
        ->assertRedirect(route('payoff-plans.show', ['budget' => $this->budget, 'payoff_plan' => $plan]));

    expect($plan->fresh()->is_active)->toBeTrue()
        ->and($previous->fresh()->is_active)->toBeFalse()
        ->and($this->budget->payoffPlans()->where('is_active', true)->count())->toBe(1)
        ->and($otherBudgetPlan->fresh()->is_active)->toBeTrue();
});
