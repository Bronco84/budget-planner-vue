<?php

use App\Services\AirtableService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    // Mock configuration
    Config::set('services.airtable', [
        'api_key' => 'test_api_key',
        'base_id' => 'appTEST123456789',
        'accounts_table' => 'accounts',
        'transactions_table' => 'transactions',
        'base_url' => 'https://api.airtable.com/v0',
    ]);
});

it('can be instantiated with proper configuration', function () {
    $service = new AirtableService();
    expect($service->isConfigured())->toBeTrue();
});

it('fails with missing configuration', function () {
    Config::set('services.airtable.api_key', null);
    
    $service = new AirtableService();
    expect($service->isConfigured())->toBeFalse();
});

it('can fetch accounts from airtable', function () {
    Http::fake([
        'api.airtable.com/v0/appTEST123456789/accounts*' => Http::response([
            'records' => [
                [
                    'id' => 'recABC123',
                    'fields' => [
                        'account_name' => 'Checking Account',
                        'institution_name' => 'Test Bank',
                        'current_balance' => 1500.75,
                        'account_type' => 'checking'
                    ],
                    'createdTime' => '2025-01-01T00:00:00.000Z'
                ]
            ]
        ], 200)
    ]);

    $service = new AirtableService();
    $accounts = $service->getAccounts();

    expect($accounts)->toHaveCount(1);
    expect($accounts->first()['id'])->toBe('recABC123');
    expect($accounts->first()['fields']['account_name'])->toBe('Checking Account');
});

it('can fetch transactions from airtable', function () {
    Http::fake([
        'api.airtable.com/v0/appTEST123456789/transactions*' => Http::response([
            'records' => [
                [
                    'id' => 'recTXN123',
                    'fields' => [
                        'description' => 'Coffee Shop',
                        'amount' => -4.25,
                        'date' => '2025-01-15',
                        'category' => 'Food & Dining',
                        'account_id' => 'recABC123'
                    ],
                    'createdTime' => '2025-01-15T10:30:00.000Z'
                ]
            ]
        ], 200)
    ]);

    $service = new AirtableService();
    $transactions = $service->getTransactions();

    expect($transactions)->toHaveCount(1);
    expect($transactions->first()['id'])->toBe('recTXN123');
    expect($transactions->first()['fields']['description'])->toBe('Coffee Shop');
});

it('handles pagination correctly', function () {
    Http::fake([
        'api.airtable.com/v0/appTEST123456789/accounts*' => Http::sequence()
            ->push([
                'records' => [
                    ['id' => 'rec1', 'fields' => ['name' => 'Account 1']],
                    ['id' => 'rec2', 'fields' => ['name' => 'Account 2']]
                ],
                'offset' => 'next_page_token'
            ], 200)
            ->push([
                'records' => [
                    ['id' => 'rec3', 'fields' => ['name' => 'Account 3']]
                ]
            ], 200)
    ]);

    $service = new AirtableService();
    $allRecords = $service->getAllRecords('accounts');

    expect($allRecords)->toHaveCount(3);
    expect($allRecords->pluck('id')->toArray())->toBe(['rec1', 'rec2', 'rec3']);
});

it('analyzes data structure correctly', function () {
    Http::fake([
        'api.airtable.com/v0/appTEST123456789/accounts*' => Http::response([
            'records' => [
                [
                    'id' => 'recABC123',
                    'fields' => [
                        'account_name' => 'Test Account',
                        'institution_name' => 'Test Bank',
                        'current_balance' => 1000.00
                    ]
                ]
            ]
        ], 200),
        'api.airtable.com/v0/appTEST123456789/transactions*' => Http::response([
            'records' => [
                [
                    'id' => 'recTXN123',
                    'fields' => [
                        'description' => 'Test Transaction',
                        'amount' => -25.00,
                        'date' => '2025-01-15'
                    ]
                ]
            ]
        ], 200)
    ]);

    $service = new AirtableService();
    $analysis = $service->analyzeDataStructure();

    expect($analysis)->toHaveKey('accounts');
    expect($analysis)->toHaveKey('transactions');
    expect($analysis)->toHaveKey('field_mappings');
    
    expect($analysis['accounts']['sample_count'])->toBe(1);
    expect($analysis['transactions']['sample_count'])->toBe(1);
});

it('handles api errors gracefully', function () {
    Http::fake([
        'api.airtable.com/v0/appTEST123456789/accounts*' => Http::response([
            'error' => [
                'type' => 'AUTHENTICATION_REQUIRED',
                'message' => 'Authentication required'
            ]
        ], 401)
    ]);

    $service = new AirtableService();
    $accounts = $service->getAccounts();

    expect($accounts)->toHaveCount(0);
});

it('can get individual record by id', function () {
    Http::fake([
        'api.airtable.com/v0/appTEST123456789/accounts/recABC123' => Http::response([
            'id' => 'recABC123',
            'fields' => [
                'account_name' => 'Specific Account',
                'institution_name' => 'Test Bank'
            ]
        ], 200)
    ]);

    $service = new AirtableService();
    $account = $service->getAccount('recABC123');

    expect($account)->not->toBeNull();
    expect($account['id'])->toBe('recABC123');
    expect($account['fields']['account_name'])->toBe('Specific Account');
});

it('suggests field mappings between airtable and plaid', function () {
    Http::fake([
        'api.airtable.com/v0/appTEST123456789/accounts*' => Http::response([
            'records' => [
                [
                    'id' => 'recABC123',
                    'fields' => [
                        'account_name' => 'Test Account',
                        'institution_name' => 'Test Bank',
                        'current_balance' => 1000.00,
                        'account_type' => 'checking'
                    ]
                ]
            ]
        ], 200),
        'api.airtable.com/v0/appTEST123456789/transactions*' => Http::response([
            'records' => [
                [
                    'id' => 'recTXN123',
                    'fields' => [
                        'description' => 'Test Transaction',
                        'amount' => -25.00,
                        'date' => '2025-01-15',
                        'merchant_name' => 'Test Merchant',
                        'category' => 'Food'
                    ]
                ]
            ]
        ], 200)
    ]);

    $service = new AirtableService();
    $analysis = $service->analyzeDataStructure();
    
    expect($analysis)->toHaveKey('field_mappings');
    expect($analysis['field_mappings'])->toHaveKey('accounts');
    expect($analysis['field_mappings'])->toHaveKey('transactions');
});
