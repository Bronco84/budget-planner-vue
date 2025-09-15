<?php

use App\Models\User;
use App\Models\Budget;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\RecurringTransactionTemplate;
use App\Services\RecurringTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    
    // Create a hybrid account (with airtable_account_id)
    $this->account = Account::factory()->create([
        'budget_id' => $this->budget->id,
        'name' => 'Test Checking Account',
        'type' => 'checking',
        'current_balance_cents' => 500000, // $5,000
        'airtable_account_id' => 'recTestAccount123',
        'include_in_budget' => true,
    ]);
});

test('recurring transaction service generates projections for account with templates', function () {
    // Create some recurring templates
    $monthlyTemplate = RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'Monthly Utility Bill',
        'amount_in_cents' => -15000, // -$150
        'frequency' => 'monthly',
        'day_of_month' => 15,
        'start_date' => now()->subMonths(2),
    ]);

    $weeklyTemplate = RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'Weekly Groceries',
        'amount_in_cents' => -8000, // -$80
        'frequency' => 'weekly',
        'day_of_week' => 1, // Monday
        'start_date' => now()->subWeeks(4),
    ]);

    // Test projection generation
    $recurringService = app(RecurringTransactionService::class);
    $projections = $recurringService->projectTransactions(
        $this->account,
        now()->addDay(),
        now()->addMonths(2)
    );

    // Verify projections were generated
    expect($projections)->toBeInstanceOf(\Illuminate\Support\Collection::class);
    expect($projections->count())->toBeGreaterThan(0);

    // Verify monthly projection exists
    $monthlyProjection = collect($projections)->firstWhere('description', 'Monthly Utility Bill');
    expect($monthlyProjection)->not->toBeNull();
    expect($monthlyProjection['amount_in_cents'])->toBe(-15000);

    // Verify weekly projections exist
    $weeklyProjections = collect($projections)->where('description', 'Weekly Groceries');
    expect($weeklyProjections->count())->toBeGreaterThan(4); // Should have multiple weekly occurrences
});

test('budget controller passes projected transactions to frontend for hybrid accounts', function () {
    // Create recurring template
    RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'Internet Bill',
        'amount_in_cents' => -8999, // -$89.99
        'frequency' => 'monthly',
        'day_of_month' => 1,
        'start_date' => now()->subMonth(),
    ]);

    // Add some historical transactions to the account
    Transaction::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'airtable_account_id' => $this->account->airtable_account_id,
        'description' => 'Test Transaction',
        'amount_in_cents' => -5000,
        'date' => now()->subDays(5),
    ]);

    // Get the virtual account ID that the controller will use
    $virtualAccountService = app(\App\Services\VirtualAccountService::class);
    $virtualAccounts = $virtualAccountService->getAccountsForBudget($this->budget);
    $virtualAccount = $virtualAccounts->firstWhere('airtable_id', $this->account->airtable_account_id);
    
    // Make request to budget show page with virtual account ID
    $response = $this->actingAs($this->user)
        ->get(route('budgets.show', [
            'budget' => $this->budget->id,
            'account_id' => $virtualAccount['id'],
        ]));

    $response->assertStatus(200);
    
    // Verify projected transactions are passed to the frontend
    $response->assertInertia(fn ($page) => 
        $page->component('Budgets/Show')
            ->has('projectedTransactions')
            ->where('selectedAccount.airtable_id', $this->account->airtable_account_id)
    );

    // Get the actual projected transactions from the response
    $projectedTransactions = $response->getOriginalContent()->getData()['page']['props']['projectedTransactions'];
    
    expect($projectedTransactions)->not->toBeEmpty();
    
    // Verify the Internet Bill projection exists
    $internetBill = collect($projectedTransactions)->firstWhere('description', 'Internet Bill');
    expect($internetBill)->not->toBeNull();
    expect($internetBill['amount_in_cents'])->toBe(-8999);
});

test('projected transactions appear with correct styling flags in frontend data', function () {
    // Create a template that should generate a projection soon
    RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'Phone Bill',
        'amount_in_cents' => -5500, // -$55
        'frequency' => 'monthly',
        'day_of_month' => now()->addDays(3)->day, // 3 days from now
        'start_date' => now()->subMonths(3),
    ]);

    $response = $this->actingAs($this->user)
        ->get(route('budgets.show', [
            'budget' => $this->budget->id,
            'account_id' => $this->account->id,
        ]));

    $projectedTransactions = $response->getOriginalContent()->getData()['page']['props']['projectedTransactions'];
    
    expect($projectedTransactions)->not->toBeEmpty();
    
    // Verify the projection has the expected properties for frontend styling
    $phoneProjection = collect($projectedTransactions)->firstWhere('description', 'Phone Bill');
    expect($phoneProjection)->not->toBeNull();
    expect($phoneProjection['is_projected'] ?? false)->toBeTrue();
    expect($phoneProjection['recurring_transaction_template_id'])->not->toBeNull();
});

test('account with no templates generates no projections', function () {
    // Account with no templates
    $emptyAccount = Account::factory()->create([
        'budget_id' => $this->budget->id,
        'name' => 'Empty Account',
        'airtable_account_id' => 'recEmptyAccount456',
    ]);

    $recurringService = app(RecurringTransactionService::class);
    $projections = $recurringService->projectTransactions(
        $emptyAccount,
        now()->addDay(),
        now()->addMonth()
    );

    expect($projections)->toBeEmpty();
});

test('projections respect template start and end dates', function () {
    // Create template that ends soon
    $limitedTemplate = RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'Limited Service',
        'amount_in_cents' => -2000,
        'frequency' => 'weekly',
        'day_of_week' => 1,
        'start_date' => now()->subWeeks(2),
        'end_date' => now()->addWeeks(1), // Ends in 1 week
    ]);

    $recurringService = app(RecurringTransactionService::class);
    
    // Project far into the future
    $projections = $recurringService->projectTransactions(
        $this->account,
        now()->addDay(),
        now()->addMonths(6) // Project 6 months ahead
    );

    $limitedProjections = collect($projections)->where('description', 'Limited Service');
    
    // Should only have 1 projection (within the next week before end_date)
    expect($limitedProjections->count())->toBeLessThanOrEqual(1);
    
    // All projections should be before the end date
    $limitedProjections->each(function ($projection) use ($limitedTemplate) {
        expect(Carbon::parse($projection['date']))->toBeLessThanOrEqual($limitedTemplate->end_date);
    });
});

test('hybrid account relationship works correctly', function () {
    // Verify the account relationship exists
    expect($this->account->recurringTransactionTemplates())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
    
    // Create a template
    $template = RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'Test Template',
    ]);

    // Verify relationship works
    expect($this->account->recurringTransactionTemplates()->count())->toBe(1);
    expect($this->account->recurringTransactionTemplates()->first()->id)->toBe($template->id);
});

test('budget controller finds correct local account for hybrid account projections', function () {
    // Create another account with different airtable_id to test specificity
    $otherAccount = Account::factory()->create([
        'budget_id' => $this->budget->id,
        'name' => 'Other Account',
        'airtable_account_id' => 'recOtherAccount789',
    ]);

    // Create template for our specific account
    RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'Specific Account Bill',
        'amount_in_cents' => -4500,
        'frequency' => 'monthly',
        'day_of_month' => 5,
    ]);

    // Test that projections are specific to the selected account
    $response = $this->actingAs($this->user)
        ->get(route('budgets.show', [
            'budget' => $this->budget->id,
            'account_id' => $this->account->id, // Specific account ID
        ]));

    $projectedTransactions = $response->getOriginalContent()->getData()['page']['props']['projectedTransactions'];
    
    // Should have projections for our account
    expect($projectedTransactions)->not->toBeEmpty();
    
    $specificProjection = collect($projectedTransactions)->firstWhere('description', 'Specific Account Bill');
    expect($specificProjection)->not->toBeNull();
});

test('projected transactions include proper account information', function () {
    RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'Account Info Test',
        'amount_in_cents' => -1500,
        'frequency' => 'monthly',
        'day_of_month' => 10,
    ]);

    $response = $this->actingAs($this->user)
        ->get(route('budgets.show', [
            'budget' => $this->budget->id,
            'account_id' => $this->account->id,
        ]));

    $projectedTransactions = $response->getOriginalContent()->getData()['page']['props']['projectedTransactions'];
    
    $projection = collect($projectedTransactions)->firstWhere('description', 'Account Info Test');
    expect($projection)->not->toBeNull();
    
    // Verify account information is included
    expect($projection['account'])->not->toBeNull();
    expect($projection['account']['name'])->toBe($this->account->name);
    expect($projection['account']['id'])->toBe($this->account->id);
});
