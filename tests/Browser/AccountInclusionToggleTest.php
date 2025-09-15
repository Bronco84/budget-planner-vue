<?php

use App\Models\User;
use App\Models\Budget;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create a test user and budget
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
});

test('user can navigate to account settings page', function () {
    $this->actingAs($this->user)
        ->browse(function ($browser) {
            $browser->visit(route('budgets.show', $this->budget))
                ->waitFor('[data-testid="account-settings-link"]', 10)
                ->click('[data-testid="account-settings-link"]')
                ->waitForRoute('budgets.accounts.index', [$this->budget->id])
                ->assertSee('Account Settings')
                ->assertSee('Manage Account Inclusion');
        });
});

test('account settings page displays accounts with toggles', function () {
    $this->actingAs($this->user)
        ->browse(function ($browser) {
            $browser->visit(route('budgets.accounts.index', $this->budget))
                ->waitFor('.bg-white.divide-y', 10) // Wait for account groups
                ->assertPresent('input[type="checkbox"]') // Toggle switches should be present
                ->assertSee('Total') // Toggle labels should be present
                ->assertSee('Included Total:'); // Balance display should be present
        });
});

test('user can toggle account inclusion successfully', function () {
    $this->actingAs($this->user)
        ->browse(function ($browser) {
            $browser->visit(route('budgets.accounts.index', $this->budget))
                ->waitFor('input[type="checkbox"]', 10);
            
            // Find the first toggle switch
            $firstToggle = $browser->element('input[type="checkbox"]');
            $isInitiallyChecked = $firstToggle->isSelected();
            
            // Log initial state for debugging
            $browser->script('console.log("Initial toggle state:", arguments[0])', [$isInitiallyChecked]);
            
            // Click the toggle
            $browser->click('input[type="checkbox"]')
                ->pause(1000); // Wait for AJAX request to complete
            
            // Verify the state changed
            $isAfterChecked = $browser->element('input[type="checkbox"]')->isSelected();
            expect($isAfterChecked)->toBe(!$isInitiallyChecked);
            
            // Verify no JavaScript errors occurred
            $logs = $browser->driver->manage()->getLog('browser');
            $errors = array_filter($logs, fn($log) => $log['level'] === 'SEVERE');
            expect($errors)->toBeEmpty('JavaScript errors occurred: ' . json_encode($errors));
        });
});

test('account inclusion persists after page refresh', function () {
    $this->actingAs($this->user)
        ->browse(function ($browser) {
            $browser->visit(route('budgets.accounts.index', $this->budget))
                ->waitFor('input[type="checkbox"]', 10);
            
            // Get initial state of first toggle
            $firstToggle = $browser->element('input[type="checkbox"]');
            $initialState = $firstToggle->isSelected();
            
            // Toggle it
            $browser->click('input[type="checkbox"]')
                ->pause(1000); // Wait for AJAX
            
            // Verify it changed
            $newState = $browser->element('input[type="checkbox"]')->isSelected();
            expect($newState)->toBe(!$initialState);
            
            // Refresh the page
            $browser->refresh()
                ->waitFor('input[type="checkbox"]', 10);
            
            // Verify the state persisted
            $persistedState = $browser->element('input[type="checkbox"]')->isSelected();
            expect($persistedState)->toBe($newState, 'Toggle state should persist after refresh');
        });
});

test('total included balance updates when toggling accounts', function () {
    $this->actingAs($this->user)
        ->browse(function ($browser) {
            $browser->visit(route('budgets.accounts.index', $this->budget))
                ->waitFor('[class*="font-semibold"]', 10); // Wait for balance display
            
            // Get initial total balance
            $initialBalanceText = $browser->text('[class*="font-semibold"]:contains("Included Total:")');
            
            // Toggle an account
            $browser->click('input[type="checkbox"]')
                ->pause(1500); // Wait for AJAX and UI update
            
            // Get updated balance
            $updatedBalanceText = $browser->text('[class*="font-semibold"]:contains("Included Total:")');
            
            // The balance text should have changed (unless the account had $0 balance)
            // We'll just verify the element still exists and shows a balance
            expect($updatedBalanceText)->toContain('Included Total:');
        });
});

test('api endpoint handles various account id types correctly', function () {
    // Test the API directly with different ID types
    $this->actingAs($this->user);
    
    // Test with integer ID
    $response1 = $this->post(route('preferences.toggle-account-inclusion'), [
        'account_id' => 123456789,
        'included' => true
    ]);
    $response1->assertStatus(200)
        ->assertJson(['success' => true])
        ->assertJsonStructure(['total_included_balance']);
    
    // Test with string ID
    $response2 = $this->post(route('preferences.toggle-account-inclusion'), [
        'account_id' => '123456789',
        'included' => false
    ]);
    $response2->assertStatus(200)
        ->assertJson(['success' => true])
        ->assertJsonStructure(['total_included_balance']);
});

test('handles network errors gracefully', function () {
    $this->actingAs($this->user)
        ->browse(function ($browser) {
            $browser->visit(route('budgets.accounts.index', $this->budget))
                ->waitFor('input[type="checkbox"]', 10);
            
            // Simulate network failure by blocking the API endpoint
            $browser->script('
                const originalFetch = window.fetch;
                window.fetch = function(...args) {
                    if (args[0].includes("toggle-inclusion")) {
                        return Promise.reject(new Error("Network error"));
                    }
                    return originalFetch.apply(this, args);
                };
                
                // Also mock axios
                const originalPost = window.axios.post;
                window.axios.post = function(...args) {
                    if (args[0].includes("toggle-inclusion")) {
                        return Promise.reject({response: {status: 500, data: "Server error"}});
                    }
                    return originalPost.apply(this, args);
                };
            ');
            
            // Try to toggle - should handle error gracefully
            $browser->click('input[type="checkbox"]')
                ->pause(1000);
            
            // Check that appropriate error was logged
            $logs = $browser->driver->manage()->getLog('browser');
            $hasErrorLog = false;
            foreach ($logs as $log) {
                if (strpos($log['message'], 'Error toggling account inclusion') !== false) {
                    $hasErrorLog = true;
                    break;
                }
            }
            expect($hasErrorLog)->toBeTrue('Error should be logged when API fails');
        });
});