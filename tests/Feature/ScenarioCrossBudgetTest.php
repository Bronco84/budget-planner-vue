<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\RecurringTransactionTemplate;
use App\Models\Scenario;
use App\Models\ScenarioAdjustment;
use App\Models\User;

/*
 * SCENARIO-002: adjustment references must belong to the budget in the route,
 * and server errors must not leak exception text to the client.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->account = Account::factory()->create(['budget_id' => $this->budget->id]);

    $otherBudget = Budget::factory()->create(['user_id' => User::factory()->create()->id]);
    $this->foreignTemplate = RecurringTransactionTemplate::factory()->create([
        'budget_id' => $otherBudget->id,
        'account_id' => Account::factory()->create(['budget_id' => $otherBudget->id])->id,
    ]);
});

function scenarioAdjustmentPayload(array $overrides = []): array
{
    return array_replace([
        'adjustment_type' => 'modify_existing',
        'account_id' => null,
        'amount_in_cents' => -1000,
        'start_date' => '2026-12-01',
    ], $overrides);
}

test('creating a scenario targeting a recurring template from another budget is rejected and nothing is saved', function () {
    $this->actingAs($this->user)
        ->postJson(route('budgets.scenarios.store', $this->budget), [
            'name' => 'Cancel their subscription',
            'color' => '#3b82f6',
            'adjustments' => [scenarioAdjustmentPayload([
                'account_id' => $this->account->id,
                'target_recurring_template_id' => $this->foreignTemplate->id,
            ])],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('adjustments.0.target_recurring_template_id');

    expect(Scenario::count())->toBe(0)
        ->and(ScenarioAdjustment::count())->toBe(0);
});

test('updating a scenario to target a recurring template from another budget is rejected and adjustments are unchanged', function () {
    $scenario = Scenario::factory()->create(['budget_id' => $this->budget->id, 'user_id' => $this->user->id]);
    $adjustment = $scenario->adjustments()->create(scenarioAdjustmentPayload(['account_id' => $this->account->id]));

    $this->actingAs($this->user)
        ->patchJson(route('budgets.scenarios.update', [$this->budget, $scenario]), [
            'adjustments' => [scenarioAdjustmentPayload([
                'account_id' => $this->account->id,
                'target_recurring_template_id' => $this->foreignTemplate->id,
            ])],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('adjustments.0.target_recurring_template_id');

    expect($scenario->adjustments()->pluck('id')->all())->toBe([$adjustment->id])
        ->and(ScenarioAdjustment::whereNotNull('target_recurring_template_id')->exists())->toBeFalse();
});

test('a scenario store failure returns a generic error without the exception text', function () {
    ScenarioAdjustment::creating(fn () => throw new RuntimeException('SQLSTATE secret internals'));

    $response = $this->actingAs($this->user)
        ->postJson(route('budgets.scenarios.store', $this->budget), [
            'name' => 'Buy car',
            'color' => '#3b82f6',
            'adjustments' => [scenarioAdjustmentPayload(['adjustment_type' => 'one_time_expense', 'account_id' => $this->account->id])],
        ])
        ->assertServerError()
        ->assertJsonMissingPath('error')
        ->assertJsonPath('message', 'Failed to create scenario.');

    expect($response->getContent())->not->toContain('SQLSTATE')
        ->and(Scenario::count())->toBe(0);
});

test('a scenario toggle failure returns a generic error without the exception text', function () {
    $scenario = Scenario::factory()->create(['budget_id' => $this->budget->id, 'user_id' => $this->user->id]);
    Scenario::saving(fn () => throw new RuntimeException('SQLSTATE secret internals'));

    $response = $this->actingAs($this->user)
        ->postJson(route('budgets.scenarios.toggle', [$this->budget, $scenario]))
        ->assertServerError()
        ->assertJsonMissingPath('error')
        ->assertJsonPath('message', 'Failed to toggle scenario.');

    expect($response->getContent())->not->toContain('SQLSTATE');
});
