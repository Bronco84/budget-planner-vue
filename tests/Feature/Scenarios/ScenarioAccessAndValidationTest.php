<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\Scenario;
use App\Models\ScenarioAdjustment;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * SCENARIO-001 / SCENARIO-002: the multi-account projection without scenarios,
 * foreign account ids, scenario validation, adjustment replacement on update,
 * and another user's budget for every scenario write.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->account = Account::factory()->checking()->create([
        'budget_id' => $this->budget->id,
        'current_balance_cents' => 100000,
    ]);
});

function scenarioPayload(int $accountId, array $overrides = []): array
{
    return array_merge([
        'name' => 'New car',
        'color' => '#3b82f6',
        'adjustments' => [[
            'adjustment_type' => 'one_time_expense',
            'account_id' => $accountId,
            'amount_in_cents' => -500000,
            'start_date' => now()->addDays(10)->format('Y-m-d'),
        ]],
    ], $overrides);
}

test('the multi account projection without scenarios shows only base projections', function () {
    $this->actingAs($this->user)
        ->get(route('budget.projections.multi-account', ['budget' => $this->budget, 'months' => 2]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Budgets/MultiAccountProjection')
            ->has('scenarios', 0)
            ->has('activeScenarios', 0)
            ->has('scenarioProjections', 0)
            ->has('baseProjections.'.$this->account->id));
});

test('the multi account projection rejects an account from another budget with 422', function () {
    $foreign = Account::factory()->checking()->create([
        'budget_id' => Budget::factory()->create(['user_id' => $this->user->id])->id,
    ]);

    $this->actingAs($this->user)
        ->get(route('budget.projections.multi-account', [
            'budget' => $this->budget,
            'account_ids' => [$this->account->id, $foreign->id],
        ]))
        ->assertStatus(422);
});

test('another user cannot list or toggle scenarios on a budget they do not own', function () {
    $scenario = Scenario::factory()->create([
        'budget_id' => $this->budget->id,
        'user_id' => $this->user->id,
        'is_active' => true,
    ]);
    $intruder = User::factory()->create();

    $this->actingAs($intruder)
        ->getJson(route('budgets.scenarios.index', $this->budget))
        ->assertForbidden();

    $this->actingAs($intruder)
        ->postJson(route('budgets.scenarios.toggle', [$this->budget, $scenario]))
        ->assertForbidden();

    expect($scenario->fresh()->is_active)->toBeTrue();
});

test('another user cannot create, update or delete scenarios on a budget they do not own', function () {
    $scenario = Scenario::factory()->create([
        'budget_id' => $this->budget->id,
        'user_id' => $this->user->id,
        'name' => 'Original',
    ]);
    $adjustment = ScenarioAdjustment::factory()->oneTime()->create([
        'scenario_id' => $scenario->id,
        'account_id' => $this->account->id,
    ]);
    $intruder = User::factory()->create();

    $this->actingAs($intruder)
        ->postJson(route('budgets.scenarios.store', $this->budget), scenarioPayload($this->account->id, ['name' => 'Injected']))
        ->assertForbidden();

    $this->actingAs($intruder)
        ->patchJson(route('budgets.scenarios.update', [$this->budget, $scenario]), scenarioPayload($this->account->id, ['name' => 'Hijacked']))
        ->assertForbidden();

    $this->actingAs($intruder)
        ->deleteJson(route('budgets.scenarios.destroy', [$this->budget, $scenario]))
        ->assertForbidden();

    expect(Scenario::where('name', 'Injected')->exists())->toBeFalse()
        ->and($scenario->fresh()->name)->toBe('Original')
        ->and($scenario->adjustments()->pluck('id')->all())->toBe([$adjustment->id]);
});

test('a scenario with a non hex colour or unknown adjustment type is rejected', function (array $overrides, string $field) {
    $payload = scenarioPayload($this->account->id);
    $payload = array_replace_recursive($payload, $overrides);

    $this->actingAs($this->user)
        ->postJson(route('budgets.scenarios.store', $this->budget), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(Scenario::count())->toBe(0);
})->with([
    'colour name' => [['color' => 'blue'], 'color'],
    'short hex' => [['color' => '#fff'], 'color'],
    'hex without hash' => [['color' => '3b82f6'], 'color'],
    'unknown adjustment type' => [['adjustments' => [['adjustment_type' => 'lottery_win']]], 'adjustments.0.adjustment_type'],
]);

test('updating a scenario with invalid colour or type leaves it unchanged', function () {
    $scenario = Scenario::factory()->create([
        'budget_id' => $this->budget->id,
        'user_id' => $this->user->id,
        'color' => '#10b981',
    ]);

    $this->actingAs($this->user)
        ->patchJson(route('budgets.scenarios.update', [$this->budget, $scenario]), ['color' => 'green'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('color');

    $this->actingAs($this->user)
        ->patchJson(route('budgets.scenarios.update', [$this->budget, $scenario]), [
            'adjustments' => [[
                'adjustment_type' => 'lottery_win',
                'account_id' => $this->account->id,
                'amount_in_cents' => 100,
                'start_date' => now()->format('Y-m-d'),
            ]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('adjustments.0.adjustment_type');

    expect($scenario->fresh()->color)->toBe('#10b981');
});

test('updating a scenario with adjustments replaces the existing adjustments', function () {
    $scenario = Scenario::factory()->create([
        'budget_id' => $this->budget->id,
        'user_id' => $this->user->id,
    ]);
    $old = ScenarioAdjustment::factory()->oneTime()->count(2)->create([
        'scenario_id' => $scenario->id,
        'account_id' => $this->account->id,
        'amount_in_cents' => -1000,
    ]);

    $this->actingAs($this->user)
        ->patchJson(route('budgets.scenarios.update', [$this->budget, $scenario]), [
            'adjustments' => [[
                'id' => $old[0]->id,
                'adjustment_type' => 'recurring_expense',
                'account_id' => $this->account->id,
                'amount_in_cents' => -25000,
                'start_date' => now()->addDays(5)->format('Y-m-d'),
                'frequency' => 'monthly',
                'day_of_month' => 12,
                'description' => 'Gym',
            ]],
        ])
        ->assertOk()
        ->assertJsonCount(1, 'scenario.adjustments');

    $adjustments = $scenario->adjustments()->get();

    expect($adjustments)->toHaveCount(1)
        ->and($adjustments->first()->adjustment_type)->toBe('recurring_expense')
        ->and($adjustments->first()->amount_in_cents)->toBe(-25000)
        ->and($adjustments->first()->frequency)->toBe('monthly')
        ->and($adjustments->first()->day_of_month)->toBe(12)
        ->and($adjustments->first()->description)->toBe('Gym')
        ->and(ScenarioAdjustment::whereIn('id', $old->pluck('id'))->exists())->toBeFalse();
});
