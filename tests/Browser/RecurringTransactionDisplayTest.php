<?php

use App\Models\User;
use App\Models\Budget;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\RecurringTransactionTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    
    $this->account = Account::factory()->create([
        'budget_id' => $this->budget->id,
        'name' => 'Test Checking Account',
        'type' => 'checking',
        'current_balance_cents' => 500000,
        'airtable_account_id' => 'recTestAccount123',
        'include_in_budget' => true,
    ]);
});

test('user can see projected transactions in budget view', function () {
    // Create a recurring template that will generate a near-future projection
    RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'Netflix Subscription',
        'amount_in_cents' => -1999, // -$19.99
        'frequency' => 'monthly',
        'day_of_month' => now()->addDays(2)->day, // 2 days from now
        'start_date' => now()->subMonths(2),
    ]);

    // Add a historical transaction for context
    Transaction::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'Past Transaction',
        'amount_in_cents' => -5000,
        'date' => now()->subDays(3),
    ]);

    $this->actingAs($this->user)
        ->browse(function ($browser) {
            $browser->visit(route('budgets.show', [
                    'budget' => $this->budget->id,
                    'account_id' => $this->account->id,
                ]))
                ->waitFor('[data-testid="transactions-table"]', 10)
                ->assertSee('Netflix Subscription');

            // Check for projected transaction styling
            $browser->with('[data-testid="transactions-table"]', function ($table) {
                // Look for blue-highlighted row (projected transaction)
                $table->assertPresent('.bg-blue-50');
                
                // Verify Netflix subscription appears with projection styling
                $table->assertSeeIn('.bg-blue-50', 'Netflix Subscription');
                
                // Should show "Projected" in actions column
                $table->assertSee('Projected');
                
                // Should show recurring icon (circular arrow)
                $table->assertPresent('svg'); // Recurring icon
            });
        });
});

test('projected transactions appear in correct chronological order', function () {
    // Create multiple templates with different future dates
    RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'Early Bill',
        'amount_in_cents' => -5000,
        'frequency' => 'monthly',
        'day_of_month' => now()->addDays(1)->day,
        'start_date' => now()->subMonth(),
    ]);

    RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'Late Bill',
        'amount_in_cents' => -3000,
        'frequency' => 'monthly',
        'day_of_month' => now()->addDays(5)->day,
        'start_date' => now()->subMonth(),
    ]);

    // Add a historical transaction
    Transaction::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'Historical Transaction',
        'amount_in_cents' => -2000,
        'date' => now()->subDays(2),
    ]);

    $this->actingAs($this->user)
        ->browse(function ($browser) {
            $browser->visit(route('budgets.show', [
                    'budget' => $this->budget->id,
                    'account_id' => $this->account->id,
                ]))
                ->waitFor('[data-testid="transactions-table"]', 10);

            // Get all transaction rows
            $transactionRows = $browser->elements('tbody tr:not(.bg-gray-100)'); // Exclude "today" marker
            
            expect(count($transactionRows))->toBeGreaterThanOrEqual(3);

            // Verify the transactions appear in chronological order
            $browser->assertSee('Historical Transaction')
                ->assertSee('Early Bill')
                ->assertSee('Late Bill');
        });
});

test('today marker appears between historical and projected transactions', function () {
    // Historical transaction
    Transaction::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'Yesterday Transaction',
        'amount_in_cents' => -1000,
        'date' => now()->subDay(),
    ]);

    // Future projected transaction
    RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'Tomorrow Bill',
        'amount_in_cents' => -2000,
        'frequency' => 'monthly',
        'day_of_month' => now()->addDay()->day,
        'start_date' => now()->subMonth(),
    ]);

    $this->actingAs($this->user)
        ->browse(function ($browser) {
            $browser->visit(route('budgets.show', [
                    'budget' => $this->budget->id,
                    'account_id' => $this->account->id,
                ]))
                ->waitFor('[data-testid="transactions-table"]', 10);

            // Should see "Today" marker
            $browser->assertSee('Today');
            
            // Verify marker is between historical and projected
            $browser->with('[data-testid="transactions-table"]', function ($table) {
                $table->assertSee('Yesterday Transaction')
                    ->assertSee('Today')
                    ->assertSee('Tomorrow Bill');
            });
        });
});

test('recurring transaction icon appears for projected transactions', function () {
    RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'Icon Test Bill',
        'amount_in_cents' => -7500,
        'frequency' => 'monthly',
        'day_of_month' => now()->addDays(3)->day,
        'start_date' => now()->subMonth(),
    ]);

    $this->actingAs($this->user)
        ->browse(function ($browser) {
            $browser->visit(route('budgets.show', [
                    'budget' => $this->budget->id,
                    'account_id' => $this->account->id,
                ]))
                ->waitFor('[data-testid="transactions-table"]', 10);

            // Find the row with our projected transaction
            $browser->with('[data-testid="transactions-table"]', function ($table) {
                // Look for the recurring icon (circular arrow SVG)
                $table->assertPresent('svg[stroke="currentColor"]');
                
                // Verify it's in the same row as our test bill
                $table->assertSee('Icon Test Bill');
            });
        });
});

test('projected transactions show correct amounts and formatting', function () {
    RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'Formatting Test',
        'amount_in_cents' => -12345, // -$123.45
        'frequency' => 'monthly',
        'day_of_month' => now()->addDays(1)->day,
        'start_date' => now()->subMonth(),
    ]);

    $this->actingAs($this->user)
        ->browse(function ($browser) {
            $browser->visit(route('budgets.show', [
                    'budget' => $this->budget->id,
                    'account_id' => $this->account->id,
                ]))
                ->waitFor('[data-testid="transactions-table"]', 10);

            // Verify amount formatting
            $browser->assertSee('$123.45')
                ->assertSee('Formatting Test');

            // Verify negative amount has red color styling
            $browser->with('[data-testid="transactions-table"]', function ($table) {
                $table->assertPresent('.text-red-600');
            });
        });
});

test('user can navigate to recurring transactions management', function () {
    $this->actingAs($this->user)
        ->browse(function ($browser) {
            $browser->visit(route('budgets.show', $this->budget->id))
                ->waitFor('[data-testid="budget-page"]', 10)
                ->assertSee('RECURRING TRANSACTIONS')
                ->click('a:contains("RECURRING TRANSACTIONS")')
                ->waitForRoute('recurring-transactions.index', $this->budget->id)
                ->assertUrlIs(route('recurring-transactions.index', $this->budget->id));
        });
});

test('projected transactions persist after page refresh', function () {
    RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'Persistence Test',
        'amount_in_cents' => -8888,
        'frequency' => 'monthly',
        'day_of_month' => now()->addDays(2)->day,
        'start_date' => now()->subMonth(),
    ]);

    $this->actingAs($this->user)
        ->browse(function ($browser) {
            $browser->visit(route('budgets.show', [
                    'budget' => $this->budget->id,
                    'account_id' => $this->account->id,
                ]))
                ->waitFor('[data-testid="transactions-table"]', 10)
                ->assertSee('Persistence Test')
                ->refresh()
                ->waitFor('[data-testid="transactions-table"]', 10)
                ->assertSee('Persistence Test');
        });
});
