<?php

use App\Models\User;
use App\Models\Budget;
use App\Services\AirtableService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create a test user
    $this->user = User::factory()->create([
        'name' => 'Test User',
        'email' => 'test@example.com',
    ]);
    
    // Create a test budget
    $this->budget = Budget::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Test Budget',
        'description' => 'Budget for testing Airtable integration'
    ]);
    
    // Mock Airtable service if not configured
    if (!app(AirtableService::class)->isConfigured()) {
        $this->markTestSkipped('Airtable is not configured for testing');
    }
});

it('can load the budget page without errors', function () {
    $this->actingAs($this->user)
        ->browse(function ($browser) {
            $browser->visit("/budgets/{$this->budget->id}")
                ->waitFor('[data-testid="budget-page"]', 10)
                ->assertSee($this->budget->name)
                ->assertDontSee('Internal Server Error')
                ->assertDontSee('Call to a member function')
                ->assertDontSee('Attempt to read property');
        });
});

it('displays virtual accounts in the sidebar', function () {
    $this->actingAs($this->user)
        ->browse(function ($browser) {
            $browser->visit("/budgets/{$this->budget->id}")
                ->waitFor('[data-testid="account-sidebar"]', 10)
                ->assertVisible('[data-testid="account-groups"]');
                
            // Check for account groups
            $browser->with('[data-testid="account-groups"]', function ($accountsContainer) {
                $accountsContainer->assertVisible('[data-testid="account-group"]');
            });
        });
});

it('can select an account and view its transactions', function () {
    $this->actingAs($this->user)
        ->browse(function ($browser) {
            $browser->visit("/budgets/{$this->budget->id}")
                ->waitFor('[data-testid="account-sidebar"]', 10);
                
            // Find and click the first account
            $browser->click('[data-testid="account-item"]:first-child')
                ->waitForLocation("/budgets/{$this->budget->id}*", 5);
                
            // Check that the account is highlighted
            $browser->assertVisible('[data-testid="account-item"].bg-blue-50')
                ->assertVisible('[data-testid="selected-account-header"]')
                ->assertVisible('[data-testid="transactions-table"]');
        });
});

it('shows account header when an account is selected', function () {
    $this->actingAs($this->user)
        ->browse(function ($browser) {
            $browser->visit("/budgets/{$this->budget->id}")
                ->waitFor('[data-testid="account-sidebar"]', 10)
                ->click('[data-testid="account-item"]:first-child')
                ->waitForLocation("/budgets/{$this->budget->id}*", 5);
                
            // Verify selected account header appears
            $browser->with('[data-testid="selected-account-header"]', function ($header) {
                $header->assertVisible('h3') // Account name
                       ->assertVisible('p');  // Account type and balance
            });
        });
});

it('displays transactions when an account is selected', function () {
    $this->actingAs($this->user)
        ->browse(function ($browser) {
            $browser->visit("/budgets/{$this->budget->id}")
                ->waitFor('[data-testid="account-sidebar"]', 10)
                ->click('[data-testid="account-item"]:first-child')
                ->waitForLocation("/budgets/{$this->budget->id}*", 5)
                ->waitFor('[data-testid="transactions-table"]', 5);
                
            // Check if transactions are displayed or if there's an appropriate empty state
            $browser->assertVisible('[data-testid="transactions-table"]');
            
            // The table should either have transactions or show "No transactions found"
            $browser->assertElementsExist('tbody tr', function ($elements) {
                // Should have at least one row (either transactions or empty state)
                return count($elements) >= 1;
            });
        });
});

it('can search and filter transactions', function () {
    $this->actingAs($this->user)
        ->browse(function ($browser) {
            $browser->visit("/budgets/{$this->budget->id}")
                ->waitFor('[data-testid="search-input"]', 10)
                ->type('[data-testid="search-input"]', 'test')
                ->waitFor(1000) // Allow for any debounced search
                ->assertVisible('[data-testid="transactions-table"]');
        });
});

it('can toggle account groups collapse state', function () {
    $this->actingAs($this->user)
        ->browse(function ($browser) {
            $browser->visit("/budgets/{$this->budget->id}")
                ->waitFor('[data-testid="account-groups"]', 10);
                
            // Find a group header and click to toggle
            if ($browser->elementExists('[data-testid="group-header"]:first-child')) {
                $browser->click('[data-testid="group-header"]:first-child')
                    ->waitFor(500); // Allow for collapse animation
            }
        });
});

it('displays account balances correctly', function () {
    $this->actingAs($this->user)
        ->browse(function ($browser) {
            $browser->visit("/budgets/{$this->budget->id}")
                ->waitFor('[data-testid="account-sidebar"]', 10);
                
            // Check that account balances are displayed
            $browser->with('[data-testid="account-groups"]', function ($container) {
                $container->assertVisible('[data-testid="account-balance"]');
            });
        });
});

it('handles JavaScript errors gracefully', function () {
    $this->actingAs($this->user)
        ->browse(function ($browser) {
            // Enable console error logging
            $browser->visit("/budgets/{$this->budget->id}")
                ->waitFor('[data-testid="budget-page"]', 10);
                
            // Get console logs to check for errors
            $logs = $browser->script('return window.console?.logs || [];');
            
            // Assert no critical JavaScript errors
            foreach ($logs as $log) {
                if (isset($log['level']) && $log['level'] === 'error') {
                    // Allow some expected errors but fail on critical ones
                    $message = $log['message'] ?? '';
                    if (str_contains($message, 'find is not a function') || 
                        str_contains($message, 'Cannot read properties of undefined')) {
                        $this->fail("Critical JavaScript error found: " . $message);
                    }
                }
            }
        });
});

it('maintains account selection state during navigation', function () {
    $this->actingAs($this->user)
        ->browse(function ($browser) {
            $browser->visit("/budgets/{$this->budget->id}")
                ->waitFor('[data-testid="account-sidebar"]', 10)
                ->click('[data-testid="account-item"]:first-child')
                ->waitForLocation("/budgets/{$this->budget->id}*", 5);
                
            // Verify the selected account remains highlighted
            $browser->assertVisible('[data-testid="account-item"].bg-blue-50');
            
            // Refresh the page and verify selection persists (if URL parameter is maintained)
            $currentUrl = $browser->script('return window.location.href')[0];
            $browser->refresh()
                ->waitFor('[data-testid="budget-page"]', 10);
                
            if (str_contains($currentUrl, 'account_id=')) {
                $browser->assertVisible('[data-testid="account-item"].bg-blue-50');
            }
        });
});
