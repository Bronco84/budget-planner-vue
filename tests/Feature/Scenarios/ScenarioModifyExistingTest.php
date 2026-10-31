<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\RecurringTransactionTemplate;
use App\Models\Scenario;
use App\Models\ScenarioAdjustment;
use App\Models\User;

/*
 * SCENARIO-001 / SCENARIO-002: a modify_existing adjustment changes one
 * recurring template's projected occurrences (to a new amount or by a
 * percentage) inside its start/end window, and only in that scenario.
 */

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 3, 10)->setTime(9, 0));

    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->checking = Account::factory()->checking()->create(['budget_id' => $this->budget->id, 'current_balance_cents' => 200000]);

    $this->paycheck = RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->checking->id,
        'description' => 'Paycheck',
        'amount_in_cents' => 300000,
        'is_dynamic_amount' => false,
        'frequency' => RecurringTransactionTemplate::FREQUENCY_MONTHLY,
        'day_of_month' => 1,
        'start_date' => '2025-01-01',
        'end_date' => null,
    ]);
    $this->rent = RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->checking->id,
        'description' => 'Rent',
        'amount_in_cents' => -100000,
        'is_dynamic_amount' => false,
        'frequency' => RecurringTransactionTemplate::FREQUENCY_MONTHLY,
        'day_of_month' => 5,
        'start_date' => '2025-01-05',
        'end_date' => null,
    ]);

    $this->scenario = Scenario::factory()->create(['budget_id' => $this->budget->id, 'user_id' => $this->user->id, 'is_active' => true]);
});

function modifyAdjustment(array $attributes): ScenarioAdjustment
{
    return ScenarioAdjustment::factory()->create(array_merge([
        'scenario_id' => test()->scenario->id,
        'account_id' => test()->checking->id,
        'adjustment_type' => ScenarioAdjustment::TYPE_MODIFY_EXISTING,
        'amount_in_cents' => 0,
        'percent_change' => null,
        'end_date' => null,
        'frequency' => null,
        'day_of_month' => null,
    ], $attributes));
}

/**
 * Weekly points of the base and scenario projections for the checking account, keyed by date.
 */
function modifyProjectionPoints($test): array
{
    $props = $test->actingAs($test->user)
        ->get(route('budget.projections.multi-account', ['budget' => $test->budget->id, 'months' => 3]))
        ->assertOk()
        ->viewData('page')['props'];

    $key = fn (array $projection) => collect($projection['days'])->keyBy('date')->all();

    return [
        $key($props['baseProjections'][$test->checking->id]),
        $key($props['scenarioProjections'][$test->scenario->id][$test->checking->id]),
    ];
}

test('a new amount replaces the template occurrences only inside the window, keeping the template sign', function () {
    // Paycheck becomes $3,500 for occurrences from Apr 15 to May 31 (only May 1).
    modifyAdjustment([
        'target_recurring_template_id' => $this->paycheck->id,
        'amount_in_cents' => 350000,
        'start_date' => '2026-04-15',
        'end_date' => '2026-05-31',
    ]);
    // Rent entered as a positive $1,200 from Jun 1 still lands as an expense (only Jun 5).
    modifyAdjustment([
        'target_recurring_template_id' => $this->rent->id,
        'amount_in_cents' => 120000,
        'start_date' => '2026-06-01',
    ]);

    [$base, $scenario] = modifyProjectionPoints($this);

    // Apr 1 paycheck and Apr 5 rent: before both windows.
    expect($scenario['2026-04-05']['income'])->toBe(300000)
        ->and($scenario['2026-04-05']['expense'])->toBe(100000)
        ->and($scenario['2026-04-05']['balance'])->toBe($base['2026-04-05']['balance'])
        // May 1 paycheck is modified; May 5 rent is not.
        ->and($base['2026-05-03']['income'])->toBe(300000)
        ->and($scenario['2026-05-03']['income'])->toBe(350000)
        ->and($scenario['2026-05-10']['expense'])->toBe(100000)
        // Jun 1 paycheck is after the paycheck window; Jun 5 rent is modified.
        ->and($scenario['2026-06-07']['income'])->toBe(300000)
        ->and($scenario['2026-06-07']['expense'])->toBe(120000)
        ->and($base['2026-06-07']['expense'])->toBe(100000)
        ->and($scenario['2026-06-14']['balance'] - $base['2026-06-14']['balance'])->toBe(50000 - 20000);
});

test('a percentage change scales only the target template from its start date', function () {
    // Rent 10% cheaper from Apr 1 with no end date: Apr 5, May 5 and Jun 5 become -$900.
    modifyAdjustment([
        'target_recurring_template_id' => $this->rent->id,
        'percent_change' => -10,
        'start_date' => '2026-04-01',
    ]);

    [$base, $scenario] = modifyProjectionPoints($this);

    expect($scenario['2026-04-05']['expense'])->toBe(90000)
        ->and($scenario['2026-05-10']['expense'])->toBe(90000)
        ->and($scenario['2026-06-07']['expense'])->toBe(90000)
        // The paycheck template is untouched.
        ->and($scenario['2026-04-05']['income'])->toBe($base['2026-04-05']['income'])
        ->and($scenario['2026-05-03']['income'])->toBe(300000)
        ->and($scenario['2026-06-14']['balance'] - $base['2026-06-14']['balance'])->toBe(30000);
});

test('a modify existing adjustment is saved with its template and percentage', function () {
    $this->actingAs($this->user)
        ->postJson(route('budgets.scenarios.store', $this->budget), [
            'name' => 'Cheaper rent',
            'color' => '#3b82f6',
            'adjustments' => [[
                'adjustment_type' => 'modify_existing',
                'account_id' => $this->checking->id,
                'target_recurring_template_id' => $this->rent->id,
                'amount_in_cents' => 0,
                'percent_change' => -12.5,
                'start_date' => '2026-04-01',
                'end_date' => '2026-12-31',
            ]],
        ])
        ->assertCreated();

    $adjustment = ScenarioAdjustment::where('target_recurring_template_id', $this->rent->id)->sole();

    expect((float) $adjustment->percent_change)->toBe(-12.5)
        ->and($adjustment->account_id)->toBe($this->checking->id)
        ->and($adjustment->end_date->format('Y-m-d'))->toBe('2026-12-31');
});

test('a modify existing adjustment takes its account from the target template', function () {
    $savings = Account::factory()->savings()->create(['budget_id' => $this->budget->id]);

    $this->actingAs($this->user)
        ->postJson(route('budgets.scenarios.store', $this->budget), [
            'name' => 'Raise',
            'color' => '#3b82f6',
            'adjustments' => [[
                'adjustment_type' => 'modify_existing',
                'account_id' => $savings->id,
                'target_recurring_template_id' => $this->paycheck->id,
                'amount_in_cents' => 320000,
                'start_date' => '2026-04-01',
            ]],
        ])
        ->assertCreated();

    expect(ScenarioAdjustment::sole()->account_id)->toBe($this->checking->id);
});

test('a modify existing adjustment needs a target template and a sane percentage', function (array $overrides, string $errorKey) {
    $this->actingAs($this->user)
        ->postJson(route('budgets.scenarios.store', $this->budget), [
            'name' => 'Broken',
            'color' => '#3b82f6',
            'adjustments' => [array_merge([
                'adjustment_type' => 'modify_existing',
                'account_id' => $this->checking->id,
                'target_recurring_template_id' => $this->rent->id,
                'amount_in_cents' => 0,
                'start_date' => '2026-04-01',
            ], $overrides)],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errorKey);

    expect(Scenario::where('name', 'Broken')->exists())->toBeFalse();
})->with([
    'missing template' => [['target_recurring_template_id' => null], 'adjustments.0.target_recurring_template_id'],
    'percentage below -100' => [['percent_change' => -150], 'adjustments.0.percent_change'],
    'non-numeric percentage' => [['percent_change' => 'lots'], 'adjustments.0.percent_change'],
]);
