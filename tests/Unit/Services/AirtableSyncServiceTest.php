<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AirtableService;
use App\Services\AirtableSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create test user and budget
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->account = Account::factory()->create(['budget_id' => $this->budget->id]);
    
    // Mock Airtable service
    $this->airtableService = Mockery::mock(AirtableService::class);
    $this->syncService = new AirtableSyncService($this->airtableService);
});

it('can get account mapping from airtable', function () {
    $this->airtableService->shouldReceive('isConfigured')->andReturn(true);
    $this->airtableService->shouldReceive('getAllRecords')
        ->with('accounts')
        ->andReturn(collect([
            [
                'id' => 'recABC123',
                'fields' => [
                    'account_name' => 'Test Checking',
                    'institution_name' => 'Test Bank',
                    'account_type' => 'checking',
                    'current_balance' => 1500.75
                ]
            ],
            [
                'id' => 'recDEF456',
                'fields' => [
                    'account_name' => 'Test Savings',
                    'institution_name' => 'Test Bank',
                    'account_type' => 'savings',
                    'current_balance' => 5000.00
                ]
            ]
        ]));

    $mapping = $this->syncService->getAccountMapping($this->budget);

    expect($mapping)->toHaveCount(2);
    expect($mapping->first()['airtable_id'])->toBe('recABC123');
    expect($mapping->first()['name'])->toBe('Test Checking');
    expect($mapping->first()['institution'])->toBe('Test Bank');
});

it('can sync transactions for a specific account', function () {
    $this->airtableService->shouldReceive('isConfigured')->andReturn(true);
    $this->airtableService->shouldReceive('getAllRecords')
        ->with('transactions', "{{account_id}} = 'recABC123'")
        ->andReturn(collect([
            [
                'id' => 'recTXN123',
                'fields' => [
                    'description' => 'Coffee Shop',
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
        ]));

    $result = $this->syncService->syncTransactionsForAccount($this->account, 'recABC123');

    expect($result['imported'])->toBe(2);
    expect($result['updated'])->toBe(0);
    expect($result['errors'])->toBeEmpty();

    // Verify transactions were created
    expect(Transaction::count())->toBe(2);
    
    $coffeeTransaction = Transaction::where('airtable_transaction_id', 'recTXN123')->first();
    expect($coffeeTransaction)->not->toBeNull();
    expect($coffeeTransaction->description)->toBe('Coffee Shop');
    expect($coffeeTransaction->amount_in_cents)->toBe(-425);
    expect($coffeeTransaction->category)->toBe('Food & Dining');
    expect($coffeeTransaction->is_airtable_imported)->toBeTrue();
});

it('updates existing transactions instead of creating duplicates', function () {
    // Create existing transaction
    $existingTransaction = Transaction::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'airtable_transaction_id' => 'recTXN123',
        'description' => 'Old Description',
        'amount_in_cents' => -300,
        'is_airtable_imported' => true
    ]);

    $this->airtableService->shouldReceive('isConfigured')->andReturn(true);
    $this->airtableService->shouldReceive('getAllRecords')
        ->with('transactions', "{{account_id}} = 'recABC123'")
        ->andReturn(collect([
            [
                'id' => 'recTXN123',
                'fields' => [
                    'description' => 'Updated Coffee Shop',
                    'amount' => -4.25,
                    'date' => '2025-01-15',
                    'category' => 'Food & Dining',
                    'account_id' => 'recABC123'
                ]
            ]
        ]));

    $result = $this->syncService->syncTransactionsForAccount($this->account, 'recABC123');

    expect($result['imported'])->toBe(0);
    expect($result['updated'])->toBe(1);

    // Verify transaction was updated, not duplicated
    expect(Transaction::count())->toBe(1);
    
    $updatedTransaction = Transaction::where('airtable_transaction_id', 'recTXN123')->first();
    expect($updatedTransaction->id)->toBe($existingTransaction->id);
    expect($updatedTransaction->description)->toBe('Updated Coffee Shop');
    expect($updatedTransaction->amount_in_cents)->toBe(-425);
});

it('handles missing airtable service configuration', function () {
    $this->airtableService->shouldReceive('isConfigured')->andReturn(false);

    expect(fn() => $this->syncService->syncTransactionsForAccount($this->account, 'recABC123'))
        ->toThrow(Exception::class, 'Airtable service is not configured');
});

it('handles airtable api errors gracefully', function () {
    $this->airtableService->shouldReceive('isConfigured')->andReturn(true);
    $this->airtableService->shouldReceive('getAllRecords')
        ->with('transactions', "{{account_id}} = 'recABC123'")
        ->andThrow(new Exception('API Error'));

    expect(fn() => $this->syncService->syncTransactionsForAccount($this->account, 'recABC123'))
        ->toThrow(Exception::class, 'API Error');
});

it('can get data summary', function () {
    $this->airtableService->shouldReceive('isConfigured')->andReturn(true);
    $this->airtableService->shouldReceive('getAllRecords')->with('accounts')->andReturn(collect([
        ['id' => 'rec1', 'fields' => ['name' => 'Account 1']],
        ['id' => 'rec2', 'fields' => ['name' => 'Account 2']]
    ]));
    $this->airtableService->shouldReceive('getAllRecords')->with('transactions')->andReturn(collect([
        ['id' => 'txn1', 'fields' => ['description' => 'Transaction 1']],
        ['id' => 'txn2', 'fields' => ['description' => 'Transaction 2']],
        ['id' => 'txn3', 'fields' => ['description' => 'Transaction 3']]
    ]));

    $summary = $this->syncService->getDataSummary();

    expect($summary['accounts_count'])->toBe(2);
    expect($summary['transactions_count'])->toBe(3);
    expect($summary['sample_account']['id'])->toBe('rec1');
    expect($summary['sample_transaction']['id'])->toBe('txn1');
});

it('maps transaction fields correctly', function () {
    $this->airtableService->shouldReceive('isConfigured')->andReturn(true);
    $this->airtableService->shouldReceive('getAllRecords')
        ->with('transactions', "{{account_id}} = 'recABC123'")
        ->andReturn(collect([
            [
                'id' => 'recTXN123',
                'fields' => [
                    'name' => 'Merchant Name',  // Should map to description
                    'transaction_amount' => -25.50,  // Should map to amount
                    'transaction_date' => '2025-01-10',  // Should map to date
                    'primary_category' => 'Dining',  // Should map to category
                    'account_id' => 'recABC123'
                ]
            ]
        ]));

    $result = $this->syncService->syncTransactionsForAccount($this->account, 'recABC123');

    expect($result['imported'])->toBe(1);
    
    $transaction = Transaction::where('airtable_transaction_id', 'recTXN123')->first();
    expect($transaction->description)->toBe('Merchant Name');
    expect($transaction->amount_in_cents)->toBe(-2550);
    expect($transaction->date->format('Y-m-d'))->toBe('2025-01-10');
    expect($transaction->category)->toBe('Dining');
});

it('handles transactions with minimal data', function () {
    $this->airtableService->shouldReceive('isConfigured')->andReturn(true);
    $this->airtableService->shouldReceive('getAllRecords')
        ->with('transactions', "{{account_id}} = 'recABC123'")
        ->andReturn(collect([
            [
                'id' => 'recTXN123',
                'fields' => [
                    // Only minimal data provided
                    'account_id' => 'recABC123'
                ]
            ]
        ]));

    $result = $this->syncService->syncTransactionsForAccount($this->account, 'recABC123');

    expect($result['imported'])->toBe(1);
    
    $transaction = Transaction::where('airtable_transaction_id', 'recTXN123')->first();
    expect($transaction->description)->toBe('Airtable Transaction'); // Default value
    expect($transaction->amount_in_cents)->toBe(0); // Default value
    expect($transaction->category)->toBe('Uncategorized'); // Default value
});
