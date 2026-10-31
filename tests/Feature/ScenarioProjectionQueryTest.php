<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\RecurringTransactionTemplate;
use App\Models\Scenario;
use App\Models\ScenarioAdjustment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RecurringTransactionService;
use Illuminate\Support\Facades\DB;

/*
 * SCENARIO-001: multi-account projection output pinned for a small fixture,
 * plus a guard that the base projection is built once per account and reused
 * for every scenario instead of being recomputed per scenario x account.
 */

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 3, 10)->setTime(9, 0));

    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->checking = Account::factory()->checking()->create(['budget_id' => $this->budget->id, 'current_balance_cents' => 200000]);
    $this->card = Account::factory()->creditCard()->create(['budget_id' => $this->budget->id, 'current_balance_cents' => 50000]);

    RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->checking->id,
        'description' => 'Paycheck',
        'amount_in_cents' => 300000,
        'frequency' => RecurringTransactionTemplate::FREQUENCY_MONTHLY,
        'day_of_month' => 1,
        'start_date' => '2025-01-01',
    ]);
    RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->card->id,
        'description' => 'Streaming',
        'amount_in_cents' => -1599,
        'frequency' => RecurringTransactionTemplate::FREQUENCY_MONTHLY,
        'day_of_month' => 20,
        'start_date' => '2025-01-20',
    ]);

    Transaction::create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->checking->id,
        'description' => 'Planned purchase',
        'category' => 'Misc',
        'amount_in_cents' => -45000,
        'date' => '2026-04-03',
    ]);

    $this->carScenario = Scenario::factory()->create(['budget_id' => $this->budget->id, 'user_id' => $this->user->id, 'is_active' => true]);
    ScenarioAdjustment::factory()->oneTime()->create([
        'scenario_id' => $this->carScenario->id,
        'account_id' => $this->checking->id,
        'amount_in_cents' => -150000,
        'start_date' => '2026-03-25',
    ]);

    $this->gymScenario = Scenario::factory()->create(['budget_id' => $this->budget->id, 'user_id' => $this->user->id, 'is_active' => true]);
    ScenarioAdjustment::factory()->create([
        'scenario_id' => $this->gymScenario->id,
        'account_id' => $this->card->id,
        'adjustment_type' => 'recurring_expense',
        'amount_in_cents' => -4000,
        'start_date' => '2026-03-15',
        'end_date' => '2026-12-31',
        'frequency' => 'monthly',
        'day_of_month' => 15,
    ]);
});

function scenarioProjectionProps($test): array
{
    $response = $test->actingAs($test->user)->get(route('budget.projections.multi-account', [
        'budget' => $test->budget->id,
        'months' => 2,
    ]));

    $response->assertOk();

    return $response->viewData('page')['props'];
}

function compactProjection(array $projection): array
{
    return array_map(fn ($d) => "{$d['date']} {$d['balance']} {$d['income']} {$d['expense']}", $projection['days']);
}

test('multi account projection matches the pinned base and scenario values', function () {
    $props = scenarioProjectionProps($this);

    $checkingBase = [
        '2026-03-10 200000 0 0',
        '2026-03-15 200000 0 0',
        '2026-03-22 200000 0 0',
        '2026-03-29 200000 0 0',
        '2026-04-05 455000 300000 45000',
        '2026-04-12 455000 0 0',
        '2026-04-19 455000 0 0',
        '2026-04-26 455000 0 0',
        '2026-05-03 755000 300000 0',
        '2026-05-10 755000 0 0',
    ];
    $cardBase = [
        '2026-03-10 50000 0 0',
        '2026-03-15 50000 0 0',
        '2026-03-22 51599 0 1599',
        '2026-03-29 51599 0 0',
        '2026-04-05 51599 0 0',
        '2026-04-12 51599 0 0',
        '2026-04-19 51599 0 0',
        '2026-04-26 53198 0 1599',
        '2026-05-03 53198 0 0',
        '2026-05-10 53198 0 0',
    ];

    expect(compactProjection($props['baseProjections'][$this->checking->id]))->toBe($checkingBase)
        ->and(compactProjection($props['baseProjections'][$this->card->id]))->toBe($cardBase)
        // Car scenario: one-time -1500.00 on checking only.
        ->and(compactProjection($props['scenarioProjections'][$this->carScenario->id][$this->checking->id]))->toBe([
            '2026-03-10 200000 0 0',
            '2026-03-15 200000 0 0',
            '2026-03-22 200000 0 0',
            '2026-03-29 50000 0 150000',
            '2026-04-05 305000 300000 45000',
            '2026-04-12 305000 0 0',
            '2026-04-19 305000 0 0',
            '2026-04-26 305000 0 0',
            '2026-05-03 605000 300000 0',
            '2026-05-10 605000 0 0',
        ])
        ->and(compactProjection($props['scenarioProjections'][$this->carScenario->id][$this->card->id]))->toBe($cardBase)
        // Gym scenario: monthly -40.00 on the card only.
        ->and(compactProjection($props['scenarioProjections'][$this->gymScenario->id][$this->checking->id]))->toBe($checkingBase)
        ->and(compactProjection($props['scenarioProjections'][$this->gymScenario->id][$this->card->id]))->toBe([
            '2026-03-10 50000 0 0',
            '2026-03-15 54000 0 4000',
            '2026-03-22 55599 0 1599',
            '2026-03-29 55599 0 0',
            '2026-04-05 55599 0 0',
            '2026-04-12 55599 0 0',
            '2026-04-19 59599 0 4000',
            '2026-04-26 61198 0 1599',
            '2026-05-03 61198 0 0',
            '2026-05-10 61198 0 0',
        ]);
});

test('the base projection is built once per account and reused across scenarios', function () {
    $service = Mockery::mock(RecurringTransactionService::class)->makePartial();
    $this->app->instance(RecurringTransactionService::class, $service);

    DB::enableQueryLog();
    scenarioProjectionProps($this);
    $manualTransactionQueries = array_filter(
        DB::getQueryLog(),
        fn ($q) => str_contains($q['query'], 'from "transactions"') && str_contains($q['query'], '"transfer_id" is null'),
    );
    DB::disableQueryLog();

    // Previously (1 base + 2 scenarios) x 2 accounts = 6 of each.
    $service->shouldHaveReceived('projectTransactions')->twice();
    expect($manualTransactionQueries)->toHaveCount(2);
});
