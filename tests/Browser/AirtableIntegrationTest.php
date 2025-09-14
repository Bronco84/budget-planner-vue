<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create([
        'email' => 'test@example.com',
        'password' => bcrypt('password')
    ]);
    
    $this->budget = Budget::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Test Budget'
    ]);
    
    $this->account = Account::factory()->create([
        'budget_id' => $this->budget->id,
        'name' => 'Test Account'
    ]);

    // Mock Airtable configuration
    config([
        'services.airtable.api_key' => 'test_api_key',
        'services.airtable.base_id' => 'appTEST123456789',
        'accounts_table' => 'accounts',
        'transactions_table' => 'transactions',
    ]);
});

it('can view airtable analysis page', function () {
    Http::fake([
        'api.airtable.com/v0/*' => Http::response([
            'records' => [
                [
                    'id' => 'recABC123',
                    'fields' => [
                        'account_name' => 'Chase Checking',
                        'institution_name' => 'Chase Bank',
                        'current_balance' => 2500.50
                    ]
                ]
            ]
        ], 200)
    ]);

    browse(function ($browser) {
        $browser->loginAs($this->user)
                ->visit('/airtable/analyze')
                ->assertSee('Airtable Integration Analysis')
                ->assertSee('accounts found')
                ->assertSee('Chase Checking')
                ->assertSee('Chase Bank');
    });
});

it('can navigate to account linking page', function () {
    Http::fake([
        'api.airtable.com/v0/*' => Http::response([
            'records' => [
                [
                    'id' => 'recABC123',
                    'fields' => [
                        'account_name' => 'Chase Checking',
                        'institution_name' => 'Chase Bank',
                        'current_balance' => 2500.50
                    ]
                ]
            ]
        ], 200)
    ]);

    browse(function ($browser) {
        $browser->loginAs($this->user)
                ->visit("/airtable/budgets/{$this->budget->id}/accounts/{$this->account->id}/link")
                ->assertSee('Available Airtable Accounts')
                ->assertSee('Chase Checking')
                ->assertSee('Chase Bank')
                ->assertSee('$2,500.50'); // Formatted balance
    });
});

it('can sync transactions from airtable', function () {
    Http::fake([
        'api.airtable.com/v0/*/accounts*' => Http::response([
            'records' => [
                [
                    'id' => 'recABC123',
                    'fields' => [
                        'account_name' => 'Chase Checking',
                        'institution_name' => 'Chase Bank',
                        'current_balance' => 2500.50
                    ]
                ]
            ]
        ], 200),
        'api.airtable.com/v0/*/transactions*' => Http::response([
            'records' => [
                [
                    'id' => 'recTXN123',
                    'fields' => [
                        'description' => 'Starbucks Coffee',
                        'amount' => -4.25,
                        'date' => '2025-01-15',
                        'category' => 'Food & Dining',
                        'account_id' => 'recABC123'
                    ]
                ],
                [
                    'id' => 'recTXN456',
                    'fields' => [
                        'description' => 'Salary Deposit',
                        'amount' => 3000.00,
                        'date' => '2025-01-15',
                        'category' => 'Income',
                        'account_id' => 'recABC123'
                    ]
                ]
            ]
        ], 200)
    ]);

    browse(function ($browser) {
        $browser->loginAs($this->user)
                ->visit("/airtable/budgets/{$this->budget->id}/accounts/{$this->account->id}/link")
                ->select('airtable_account_id', 'recABC123')
                ->press('Sync Transactions')
                ->waitForText('Synced 2 new transactions')
                ->assertSee('successfully');

        // Verify transactions were created in database
        expect(Transaction::count())->toBe(2);
        expect(Transaction::where('description', 'Starbucks Coffee')->exists())->toBeTrue();
        expect(Transaction::where('description', 'Salary Deposit')->exists())->toBeTrue();
    });
});

it('shows error when airtable is not configured', function () {
    // Clear configuration
    config([
        'services.airtable.api_key' => null,
        'services.airtable.base_id' => null,
    ]);

    browse(function ($browser) {
        $browser->loginAs($this->user)
                ->visit('/airtable/analyze')
                ->assertSee('Airtable integration is not configured')
                ->assertSee('Please check your environment variables');
    });
});

it('displays helpful error message for api failures', function () {
    Http::fake([
        'api.airtable.com/v0/*' => Http::response([
            'error' => [
                'type' => 'AUTHENTICATION_REQUIRED',
                'message' => 'Authentication required'
            ]
        ], 401)
    ]);

    browse(function ($browser) {
        $browser->loginAs($this->user)
                ->visit("/airtable/budgets/{$this->budget->id}/accounts/{$this->account->id}/link")
                ->select('airtable_account_id', 'recABC123')
                ->press('Sync Transactions')
                ->waitForText('Failed to sync transactions')
                ->assertSee('error');
    });
});

it('shows existing airtable transactions indicator', function () {
    // Create existing Airtable transaction
    Transaction::factory()->airtableImported()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'Existing Airtable Transaction'
    ]);

    Http::fake([
        'api.airtable.com/v0/*' => Http::response([
            'records' => []
        ], 200)
    ]);

    browse(function ($browser) {
        $browser->loginAs($this->user)
                ->visit("/airtable/budgets/{$this->budget->id}/accounts/{$this->account->id}/link")
                ->assertSee('This account has existing Airtable transactions')
                ->assertSee('Existing Airtable Transaction');
    });
});

it('can refresh airtable data', function () {
    Http::fake([
        'api.airtable.com/v0/*' => Http::response([
            'records' => [
                [
                    'id' => 'recABC123',
                    'fields' => [
                        'account_name' => 'Updated Account Name',
                        'institution_name' => 'Updated Bank',
                        'current_balance' => 9999.99
                    ]
                ]
            ]
        ], 200)
    ]);

    browse(function ($browser) {
        $browser->loginAs($this->user)
                ->visit('/airtable/analyze')
                ->press('Refresh Data')
                ->waitForText('Updated Account Name')
                ->assertSee('Updated Bank')
                ->assertSee('$9,999.99');
    });
});
