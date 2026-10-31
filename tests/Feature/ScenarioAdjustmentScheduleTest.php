<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\Scenario;
use App\Models\ScenarioAdjustment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/*
 * SCENARIO-001 / SCENARIO-002: scheduling of recurring scenario adjustments
 * (month-end clamping, twice-a-month) and the multi-account projection page.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->account = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
    $this->scenario = Scenario::factory()->create([
        'budget_id' => $this->budget->id,
        'user_id' => $this->user->id,
    ]);
});

function scheduledAdjustment(array $attributes): ScenarioAdjustment
{
    return ScenarioAdjustment::factory()->create(array_merge([
        'scenario_id' => test()->scenario->id,
        'account_id' => test()->account->id,
        'adjustment_type' => 'recurring_expense',
        'amount_in_cents' => -10000,
        'end_date' => null,
        'day_of_week' => null,
    ], $attributes));
}

function adjustmentDates(ScenarioAdjustment $adjustment, string $from, string $to): array
{
    return array_column($adjustment->generateProjectedTransactions(Carbon::parse($from), Carbon::parse($to)), 'date');
}

test('monthly adjustments on days 29 to 31 fall on the last day of shorter months', function (string $start, int $day, array $expected) {
    $adjustment = scheduledAdjustment(['frequency' => 'monthly', 'day_of_month' => $day, 'start_date' => $start]);

    expect(adjustmentDates($adjustment, $start, '2026-05-01'))->toBe($expected);
})->with([
    'day 31 from mid January' => ['2026-01-15', 31, ['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30']],
    'day 30 starting in February' => ['2026-02-10', 30, ['2026-02-28', '2026-03-30', '2026-04-30']],
    'day 29 after the day has passed' => ['2026-01-30', 29, ['2026-02-28', '2026-03-29', '2026-04-29']],
]);

test('bimonthly adjustments occur on both days of every month, clamped to the month end', function () {
    $adjustment = scheduledAdjustment([
        'frequency' => 'bimonthly',
        'first_day_of_month' => 1,
        'day_of_month' => 31,
        'start_date' => '2026-01-10',
    ]);

    expect(adjustmentDates($adjustment, '2026-01-01', '2026-03-15'))
        ->toBe(['2026-01-31', '2026-02-01', '2026-02-28', '2026-03-01']);
});

test('a scenario can be saved with a bimonthly adjustment', function () {
    $this->actingAs($this->user)
        ->postJson(route('budgets.scenarios.store', $this->budget), [
            'name' => 'Side gig',
            'color' => '#3b82f6',
            'adjustments' => [[
                'adjustment_type' => 'savings_contribution',
                'account_id' => $this->account->id,
                'amount_in_cents' => 25000,
                'start_date' => '2026-01-01',
                'frequency' => 'bimonthly',
                'first_day_of_month' => 1,
                'day_of_month' => 15,
            ]],
        ])
        ->assertCreated();

    expect(ScenarioAdjustment::where('frequency', 'bimonthly')->sole())
        ->first_day_of_month->toBe(1)
        ->day_of_month->toBe(15);
});

test('a bimonthly adjustment needs two different days of the month', function (array $days, string $errorKey) {
    $this->actingAs($this->user)
        ->postJson(route('budgets.scenarios.store', $this->budget), [
            'name' => 'Side gig',
            'color' => '#3b82f6',
            'adjustments' => [array_merge([
                'adjustment_type' => 'savings_contribution',
                'account_id' => $this->account->id,
                'amount_in_cents' => 25000,
                'start_date' => '2026-01-01',
                'frequency' => 'bimonthly',
            ], $days)],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errorKey);
})->with([
    'missing first day' => [['day_of_month' => 15], 'adjustments.0.first_day_of_month'],
    'missing second day' => [['first_day_of_month' => 1], 'adjustments.0.day_of_month'],
    'same day twice' => [['first_day_of_month' => 15, 'day_of_month' => 15], 'adjustments.0.first_day_of_month'],
]);

test('the multi account projection page does not write autopay projections to the log', function () {
    Log::spy();

    $this->actingAs($this->user)
        ->get(route('budget.projections.multi-account', $this->budget))
        ->assertOk();

    Log::shouldNotHaveReceived('info');
});

test('a leftover first day is ignored for adjustments that are not bimonthly', function () {
    $this->actingAs($this->user)
        ->postJson(route('budgets.scenarios.store', $this->budget), [
            'name' => 'Gym',
            'color' => '#3b82f6',
            'adjustments' => [[
                'adjustment_type' => 'recurring_expense',
                'account_id' => $this->account->id,
                'amount_in_cents' => -4000,
                'start_date' => '2026-01-01',
                'frequency' => 'monthly',
                'first_day_of_month' => 15,
                'day_of_month' => 15,
            ]],
        ])
        ->assertCreated();

    expect(ScenarioAdjustment::sole()->first_day_of_month)->toBeNull();
});
