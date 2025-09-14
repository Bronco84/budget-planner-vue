<?php

use App\Models\Budget;
use App\Models\User;
use App\Services\HybridAccountService;

test('budget show page handles empty accounts gracefully', function () {
    // Create a test user
    $user = User::factory()->create();
    
    // Create a budget without any accounts
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'name' => 'Test Budget',
        'description' => 'Testing empty accounts scenario'
    ]);
    
    // Mock the HybridAccountService to return empty accounts
    $this->mock(HybridAccountService::class, function ($mock) {
        $mock->shouldReceive('getAccountsForBudget')
             ->andReturn(collect([])); // Empty collection
    });
    
    // Visit the budget page as the authenticated user
    $this->actingAs($user)
         ->browse(function ($browser) use ($budget) {
             $browser->visit("/budgets/{$budget->id}")
                    ->waitFor('[data-testid="budget-title"]', 10)
                    ->assertSee($budget->name)
                    ->assertDontSee('Internal Server Error')
                    ->assertSee('No accounts found');
         });
});

test('budget show page works with airtable accounts', function () {
    // Create a test user
    $user = User::factory()->create();
    
    // Create a budget
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'name' => 'Test Budget with Accounts',
        'description' => 'Testing with mock Airtable accounts'
    ]);
    
    // Mock the HybridAccountService to return test accounts
    $mockAccounts = collect([
        [
            'id' => 1,
            'airtable_id' => 'recTestAccount1',
            'name' => 'Test Checking Account',
            'type' => 'checking',
            'current_balance_cents' => 150000, // $1,500.00
            'include_in_budget' => true,
            'is_airtable_synced' => true,
        ],
        [
            'id' => 2,
            'airtable_id' => 'recTestAccount2',
            'name' => 'Test Savings Account',
            'type' => 'savings',
            'current_balance_cents' => 500000, // $5,000.00
            'include_in_budget' => true,
            'is_airtable_synced' => true,
        ]
    ]);
    
    $this->mock(HybridAccountService::class, function ($mock) use ($mockAccounts) {
        $mock->shouldReceive('getAccountsForBudget')
             ->andReturn($mockAccounts);
        $mock->shouldReceive('getAccount')
             ->andReturn($mockAccounts->first());
    });
    
    // Visit the budget page
    $this->actingAs($user)
         ->browse(function ($browser) use ($budget) {
             $browser->visit("/budgets/{$budget->id}")
                    ->waitFor('[data-testid="budget-title"]', 10)
                    ->assertSee($budget->name)
                    ->assertSee('Test Checking Account')
                    ->assertSee('Test Savings Account')
                    ->assertDontSee('Internal Server Error')
                    ->assertDontSee('No accounts found');
         });
});

test('budget show page handles undefined accounts prop', function () {
    // Create a test user and budget
    $user = User::factory()->create();
    $budget = Budget::factory()->create(['user_id' => $user->id]);
    
    // Mock the service to return empty accounts to trigger the undefined scenario
    $this->mock(HybridAccountService::class, function ($mock) {
        $mock->shouldReceive('getAccountsForBudget')
             ->andReturn(collect([]));
    });
    
    // Visit the page and ensure it doesn't crash
    $this->actingAs($user)
         ->browse(function ($browser) use ($budget) {
             $browser->visit("/budgets/{$budget->id}")
                    ->pause(2000) // Give time for Vue to render
                    ->assertDontSee('TypeError')
                    ->assertDontSee('Cannot read properties of undefined')
                    ->assertDontSee('Internal Server Error');
         });
});

test('budget creation and immediate viewing works correctly', function () {
    // Test the full flow: create budget → view budget (should handle empty accounts)
    $user = User::factory()->create();
    
    // Mock empty accounts for new budget
    $this->mock(HybridAccountService::class, function ($mock) {
        $mock->shouldReceive('getAccountsForBudget')
             ->andReturn(collect([]));
        $mock->shouldReceive('syncAccountsForBudget')
             ->andReturn(['synced' => 0, 'created' => 0, 'updated' => 0, 'errors' => []]);
    });
    
    $this->actingAs($user)
         ->browse(function ($browser) {
             // Create a new budget
             $browser->visit('/budgets/create')
                    ->waitFor('input[name="name"]', 5)
                    ->type('name', 'Test Integration Budget')
                    ->type('description', 'Testing the full integration flow')
                    ->press('Create Budget')
                    ->waitForRoute('budgets.show', [], 10)
                    ->assertSee('Test Integration Budget')
                    ->assertSee('Budget created successfully')
                    ->assertDontSee('Internal Server Error');
         });
});

test('budget show page form initialization with null accounts', function () {
    $user = User::factory()->create();
    $budget = Budget::factory()->create(['user_id' => $user->id]);
    
    // Test with null/undefined accounts to catch the specific TypeError
    $this->mock(HybridAccountService::class, function ($mock) {
        $mock->shouldReceive('getAccountsForBudget')
             ->andReturn(collect([]));
    });
    
    $this->actingAs($user)
         ->browse(function ($browser) use ($budget) {
             $browser->visit("/budgets/{$budget->id}")
                    ->waitFor('body', 10)
                    ->pause(1000) // Let Vue fully initialize
                    // Check that the page loads without throwing errors
                    ->assertPresent('[data-testid="budget-title"], h1, .budget-name')
                    ->assertDontSee('TypeError')
                    ->assertDontSee('Cannot read properties');
         });
});

test('account tab switching works with hybrid accounts', function () {
    $user = User::factory()->create();
    $budget = Budget::factory()->create(['user_id' => $user->id]);
    
    $mockAccounts = collect([
        ['id' => 1, 'airtable_id' => 'rec1', 'name' => 'Account 1', 'type' => 'checking'],
        ['id' => 2, 'airtable_id' => 'rec2', 'name' => 'Account 2', 'type' => 'savings']
    ]);
    
    $this->mock(HybridAccountService::class, function ($mock) use ($mockAccounts) {
        $mock->shouldReceive('getAccountsForBudget')->andReturn($mockAccounts);
        $mock->shouldReceive('getAccount')->andReturn($mockAccounts->first());
    });
    
    $this->actingAs($user)
         ->browse(function ($browser) use ($budget) {
             $browser->visit("/budgets/{$budget->id}")
                    ->waitFor('[data-account-tab]', 10)
                    ->assertSee('Account 1')
                    ->assertSee('Account 2')
                    ->click('[data-account-tab="2"]')
                    ->pause(500)
                    ->assertDontSee('Internal Server Error');
         });
});
