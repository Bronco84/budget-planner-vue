<?php

use App\Models\User;
use App\Models\Budget;
use App\Models\Account;
use App\Models\Scenario;
use App\Models\ScenarioAdjustment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->account = Account::factory()->create([
        'budget_id' => $this->budget->id,
        'name' => 'Checking Account',
        'type' => 'checking',
        'current_balance_cents' => 100000, // $1,000
    ]);
});

test('user can create a scenario with adjustments', function () {
    $response = $this->actingAs($this->user)
        ->postJson(route('budgets.scenarios.store', $this->budget->id), [
            'name' => 'Buy Car',
            'description' => 'Purchase a new car',
            'color' => '#3b82f6',
            'is_active' => true,
            'adjustments' => [
                [
                    'adjustment_type' => 'one_time_expense',
                    'account_id' => $this->account->id,
                    'amount_in_cents' => -5000000, // -$50,000
                    'start_date' => now()->addDays(30)->format('Y-m-d'),
                    'description' => 'Car purchase',
                ],
                [
                    'adjustment_type' => 'recurring_expense',
                    'account_id' => $this->account->id,
                    'amount_in_cents' => -65000, // -$650/month
                    'start_date' => now()->addDays(30)->format('Y-m-d'),
                    'end_date' => now()->addYears(5)->format('Y-m-d'),
                    'frequency' => 'monthly',
                    'day_of_month' => 15,
                    'description' => 'Car payment',
                ],
            ],
        ]);

    $response->assertStatus(201);
    $response->assertJsonStructure([
        'message',
        'scenario' => [
            'id',
            'name',
            'description',
            'color',
            'is_active',
            'adjustments',
        ],
    ]);

    $this->assertDatabaseHas('scenarios', [
        'budget_id' => $this->budget->id,
        'name' => 'Buy Car',
        'color' => '#3b82f6',
    ]);

    $this->assertDatabaseHas('scenario_adjustments', [
        'adjustment_type' => 'one_time_expense',
        'account_id' => $this->account->id,
        'amount_in_cents' => -5000000,
    ]);

    $this->assertDatabaseHas('scenario_adjustments', [
        'adjustment_type' => 'recurring_expense',
        'account_id' => $this->account->id,
        'amount_in_cents' => -65000,
        'frequency' => 'monthly',
    ]);
});

test('user can list scenarios for a budget', function () {
    $scenario1 = Scenario::factory()->create([
        'budget_id' => $this->budget->id,
        'user_id' => $this->user->id,
        'name' => 'Scenario 1',
    ]);

    $scenario2 = Scenario::factory()->create([
        'budget_id' => $this->budget->id,
        'user_id' => $this->user->id,
        'name' => 'Scenario 2',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('budgets.scenarios.index', $this->budget->id));

    $response->assertStatus(200);
    $response->assertJsonCount(2, 'scenarios');
    $response->assertJsonFragment(['name' => 'Scenario 1']);
    $response->assertJsonFragment(['name' => 'Scenario 2']);
});

test('user can update a scenario', function () {
    $scenario = Scenario::factory()->create([
        'budget_id' => $this->budget->id,
        'user_id' => $this->user->id,
        'name' => 'Old Name',
    ]);

    $adjustment = ScenarioAdjustment::factory()->create([
        'scenario_id' => $scenario->id,
        'account_id' => $this->account->id,
    ]);

    $response = $this->actingAs($this->user)
        ->patchJson(route('budgets.scenarios.update', [
            'budget' => $this->budget->id,
            'scenario' => $scenario->id,
        ]), [
            'name' => 'New Name',
            'description' => 'Updated description',
            'color' => '#ef4444',
            'adjustments' => [
                [
                    'adjustment_type' => 'one_time_expense',
                    'account_id' => $this->account->id,
                    'amount_in_cents' => -10000,
                    'start_date' => now()->format('Y-m-d'),
                    'description' => 'Updated adjustment',
                ],
            ],
        ]);

    $response->assertStatus(200);
    $this->assertDatabaseHas('scenarios', [
        'id' => $scenario->id,
        'name' => 'New Name',
        'description' => 'Updated description',
        'color' => '#ef4444',
    ]);
});

test('user can delete a scenario', function () {
    $scenario = Scenario::factory()->create([
        'budget_id' => $this->budget->id,
        'user_id' => $this->user->id,
    ]);

    $adjustment = ScenarioAdjustment::factory()->create([
        'scenario_id' => $scenario->id,
        'account_id' => $this->account->id,
    ]);

    $response = $this->actingAs($this->user)
        ->deleteJson(route('budgets.scenarios.destroy', [
            'budget' => $this->budget->id,
            'scenario' => $scenario->id,
        ]));

    $response->assertStatus(200);
    $this->assertDatabaseMissing('scenarios', ['id' => $scenario->id]);
    $this->assertDatabaseMissing('scenario_adjustments', ['id' => $adjustment->id]);
});

test('user can toggle scenario active state', function () {
    $scenario = Scenario::factory()->create([
        'budget_id' => $this->budget->id,
        'user_id' => $this->user->id,
        'is_active' => true,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson(route('budgets.scenarios.toggle', [
            'budget' => $this->budget->id,
            'scenario' => $scenario->id,
        ]));

    $response->assertStatus(200);
    $response->assertJson(['is_active' => false]);
    $this->assertDatabaseHas('scenarios', [
        'id' => $scenario->id,
        'is_active' => false,
    ]);

    // Toggle back
    $response = $this->actingAs($this->user)
        ->postJson(route('budgets.scenarios.toggle', [
            'budget' => $this->budget->id,
            'scenario' => $scenario->id,
        ]));

    $response->assertStatus(200);
    $response->assertJson(['is_active' => true]);
});

test('user cannot access scenarios from another users budget', function () {
    $otherUser = User::factory()->create();
    $otherBudget = Budget::factory()->create(['user_id' => $otherUser->id]);
    $otherScenario = Scenario::factory()->create([
        'budget_id' => $otherBudget->id,
        'user_id' => $otherUser->id,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('budgets.scenarios.show', [
            'budget' => $otherBudget->id,
            'scenario' => $otherScenario->id,
        ]));

    $response->assertStatus(403);
});

test('scenario adjustments must have valid account from budget', function () {
    $otherBudget = Budget::factory()->create(['user_id' => $this->user->id]);
    $otherAccount = Account::factory()->create(['budget_id' => $otherBudget->id]);

    $response = $this->actingAs($this->user)
        ->postJson(route('budgets.scenarios.store', $this->budget->id), [
            'name' => 'Invalid Scenario',
            'color' => '#3b82f6',
            'adjustments' => [
                [
                    'adjustment_type' => 'one_time_expense',
                    'account_id' => $otherAccount->id, // Account from different budget
                    'amount_in_cents' => -10000,
                    'start_date' => now()->format('Y-m-d'),
                ],
            ],
        ]);

    $response->assertStatus(422);
});

test('multi account projection returns correct structure', function () {
    $account2 = Account::factory()->create([
        'budget_id' => $this->budget->id,
        'name' => 'Savings Account',
        'type' => 'savings',
        'current_balance_cents' => 500000, // $5,000
    ]);

    $scenario = Scenario::factory()->create([
        'budget_id' => $this->budget->id,
        'user_id' => $this->user->id,
        'is_active' => true,
    ]);

    ScenarioAdjustment::factory()->create([
        'scenario_id' => $scenario->id,
        'account_id' => $this->account->id,
        'adjustment_type' => 'one_time_expense',
        'amount_in_cents' => -10000,
        'start_date' => now()->addDays(10)->format('Y-m-d'),
    ]);

    $response = $this->actingAs($this->user)
        ->get(route('budget.projections.multi-account', [
            'budget' => $this->budget->id,
            'account_ids' => [$this->account->id, $account2->id],
            'months' => 3,
        ]));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Budgets/MultiAccountProjection')
        ->has('budget')
        ->has('accounts', 2)
        ->has('baseProjections')
        ->has('scenarioProjections')
        ->has('scenarios')
    );
});

test('scenario adjustment generates correct one time transactions', function () {
    $adjustment = ScenarioAdjustment::factory()->create([
        'scenario_id' => Scenario::factory()->create([
            'budget_id' => $this->budget->id,
            'user_id' => $this->user->id,
        ])->id,
        'account_id' => $this->account->id,
        'adjustment_type' => 'one_time_expense',
        'amount_in_cents' => -50000, // -$500
        'start_date' => now()->addDays(15)->format('Y-m-d'),
    ]);

    $transactions = $adjustment->generateProjectedTransactions(
        now(),
        now()->addMonths(1)
    );

    expect($transactions)->toHaveCount(1);
    expect($transactions[0]['amount_in_cents'])->toBe(-50000);
    expect($transactions[0]['is_scenario_adjustment'])->toBeTrue();
});

test('scenario adjustment generates correct recurring transactions', function () {
    $startDate = now()->startOfMonth();
    $adjustment = ScenarioAdjustment::factory()->create([
        'scenario_id' => Scenario::factory()->create([
            'budget_id' => $this->budget->id,
            'user_id' => $this->user->id,
        ])->id,
        'account_id' => $this->account->id,
        'adjustment_type' => 'recurring_expense',
        'amount_in_cents' => -100000, // -$1,000/month
        'start_date' => $startDate->format('Y-m-d'),
        'frequency' => 'monthly',
        'day_of_month' => 1,
    ]);

    $transactions = $adjustment->generateProjectedTransactions(
        $startDate,
        $startDate->copy()->addMonths(3)
    );

    // Should generate 4 transactions (start month + 3 additional months)
    expect($transactions)->toHaveCount(4);
    expect($transactions[0]['amount_in_cents'])->toBe(-100000);
});




