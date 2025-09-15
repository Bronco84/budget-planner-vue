<?php

use App\Models\User;
use App\Models\Budget;
use App\Models\Account;
use App\Models\RecurringTransactionTemplate;
use App\Services\RecurringTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    
    // Create a simple local account (not hybrid)
    $this->account = Account::factory()->create([
        'budget_id' => $this->budget->id,
        'name' => 'Test Checking Account',
        'type' => 'checking',
        'current_balance_cents' => 500000, // $5,000
        'include_in_budget' => true,
    ]);
});

test('recurring transaction service generates monthly projections', function () {
    // Create a monthly template
    $template = RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'Monthly Rent',
        'amount_in_cents' => -150000, // -$1,500
        'frequency' => 'monthly',
        'day_of_month' => 1,
        'start_date' => now()->subMonths(3),
    ]);

    $recurringService = app(RecurringTransactionService::class);
    $projections = $recurringService->projectTransactions(
        $this->account,
        now()->addDay(),
        now()->addMonths(3)
    );

    expect($projections)->toBeInstanceOf(\Illuminate\Support\Collection::class);
    expect($projections->count())->toBeGreaterThan(0);

    // Should have 3 monthly projections
    expect($projections->count())->toBe(3);

    // All projections should be for rent
    $projections->each(function ($projection) {
        expect($projection['description'])->toBe('Monthly Rent');
        expect($projection['amount_in_cents'])->toBe(-150000);
    });
});

test('recurring transaction service generates weekly projections', function () {
    // Create a weekly template
    RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'Weekly Groceries',
        'amount_in_cents' => -8000, // -$80
        'frequency' => 'weekly',
        'day_of_week' => 1, // Monday
        'start_date' => now()->subWeeks(4),
    ]);

    $recurringService = app(RecurringTransactionService::class);
    $projections = $recurringService->projectTransactions(
        $this->account,
        now()->addDay(),
        now()->addWeeks(4)
    );

    expect($projections->count())->toBeGreaterThan(2); // At least 2-3 weekly occurrences
    
    // Verify weekly pattern
    $groceryProjections = $projections->where('description', 'Weekly Groceries');
    expect($groceryProjections->count())->toBeGreaterThan(2);
});

test('account with recurring templates relationship works', function () {
    // Create multiple templates
    RecurringTransactionTemplate::factory()->count(3)->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
    ]);

    // Test relationship
    expect($this->account->recurringTransactionTemplates())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
    expect($this->account->recurringTransactionTemplates()->count())->toBe(3);
});

test('projections respect template end dates', function () {
    // Create template that ends soon
    RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'Limited Service',
        'amount_in_cents' => -5000,
        'frequency' => 'monthly',
        'day_of_month' => 15,
        'start_date' => now()->subMonth(),
        'end_date' => now()->addMonth(), // Ends in 1 month
    ]);

    $recurringService = app(RecurringTransactionService::class);
    $projections = $recurringService->projectTransactions(
        $this->account,
        now()->addDay(),
        now()->addMonths(6) // Project 6 months ahead
    );

    $limitedProjections = $projections->where('description', 'Limited Service');
    
    // Should only have 1 projection (before end_date)
    expect($limitedProjections->count())->toBeLessThanOrEqual(1);
});

test('account with no templates generates no projections', function () {
    // Account with no templates
    $emptyAccount = Account::factory()->create([
        'budget_id' => $this->budget->id,
        'name' => 'Empty Account',
    ]);

    $recurringService = app(RecurringTransactionService::class);
    $projections = $recurringService->projectTransactions(
        $emptyAccount,
        now()->addDay(),
        now()->addMonth()
    );

    expect($projections)->toBeEmpty();
});

test('projections are generated for correct date ranges', function () {
    // Create template with specific day
    RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'Phone Bill',
        'amount_in_cents' => -7500,
        'frequency' => 'monthly',
        'day_of_month' => 15, // 15th of each month
        'start_date' => now()->subMonths(2),
    ]);

    $recurringService = app(RecurringTransactionService::class);
    $projections = $recurringService->projectTransactions(
        $this->account,
        now()->addDay(),
        now()->addMonths(2)
    );

    expect($projections->count())->toBeGreaterThan(0);

    // All projections should be on the 15th
    $projections->each(function ($projection) {
        $date = Carbon::parse($projection['date']);
        expect($date->day)->toBe(15);
    });
});

test('projected transactions include template id for frontend identification', function () {
    $template = RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'Template ID Test',
        'amount_in_cents' => -3000,
        'frequency' => 'monthly',
        'day_of_month' => 5,
    ]);

    $recurringService = app(RecurringTransactionService::class);
    $projections = $recurringService->projectTransactions(
        $this->account,
        now()->addDay(),
        now()->addMonth()
    );

    expect($projections->count())->toBeGreaterThan(0);

    $projection = $projections->first();
    expect($projection['recurring_transaction_template_id'])->toBe($template->id);
    expect($projection['description'])->toBe('Template ID Test');
});
