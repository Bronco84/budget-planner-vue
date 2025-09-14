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
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->account = Account::factory()->create(['budget_id' => $this->budget->id]);
    
    // Mock Airtable configuration
    config([
        'services.airtable.api_key' => 'test_api_key',
        'services.airtable.base_id' => 'appTEST123456789',
        'services.airtable.accounts_table' => 'accounts',
        'services.airtable.transactions_table' => 'transactions',
        'services.airtable.base_url' => 'https://api.airtable.com/v0',
    ]);
});

it('can view airtable link form for an account', function () {
    Http::fake([
        'api.airtable.com/v0/*' => Http::response([
            'records' => [
                [
                    'id' => 'recABC123',
                    'fields' => [
                        'account_name' => 'Test Account',
                        'institution_name' => 'Test Bank',
                        'account_type' => 'checking',
                        'current_balance' => 1500.75
                    ]
                ]
            ]
        ], 200)
    ]);

    $response = $this->actingAs($this->user)
        ->get(route('budgets.accounts.edit', [$this->budget, $this->account]));

    $response->assertOk();
    // Note: You'd need to create this route or adjust based on your actual routing
});

it('can sync transactions from airtable', function () {
    Http::fake([
        'api.airtable.com/v0/*/transactions*' => Http::response([
            'records' => [
                [
                    'id' => 'recTXN123',
                    'fields' => [
                        'description' => 'Test Transaction',
                        'amount' => -25.00,
                        'date' => '2025-01-15',
                        'category' => 'Food & Dining',
                        'account_id' => 'recABC123'
                    ]
                ]
            ]
        ], 200)
    ]);

    $response = $this->actingAs($this->user)
        ->post(route('airtable.sync', [$this->budget, $this->account]), [
            'airtable_account_id' => 'recABC123'
        ]);

    $response->assertRedirect(route('budgets.show', $this->budget));
    $response->assertSessionHas('message');

    // Verify transaction was created
    expect(Transaction::count())->toBe(1);
    
    $transaction = Transaction::first();
    expect($transaction->description)->toBe('Test Transaction');
    expect($transaction->amount_in_cents)->toBe(-2500);
    expect($transaction->is_airtable_imported)->toBeTrue();
});

it('handles airtable sync errors gracefully', function () {
    Http::fake([
        'api.airtable.com/v0/*' => Http::response([
            'error' => [
                'type' => 'AUTHENTICATION_REQUIRED',
                'message' => 'Authentication required'
            ]
        ], 401)
    ]);

    $response = $this->actingAs($this->user)
        ->post(route('airtable.sync', [$this->budget, $this->account]), [
            'airtable_account_id' => 'recABC123'
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error');
    
    // Verify no transactions were created
    expect(Transaction::count())->toBe(0);
});

it('requires authentication for airtable endpoints', function () {
    $response = $this->post(route('airtable.sync', [$this->budget, $this->account]), [
        'airtable_account_id' => 'recABC123'
    ]);

    $response->assertRedirect('/login');
});

it('validates airtable sync request', function () {
    $response = $this->actingAs($this->user)
        ->post(route('airtable.sync', [$this->budget, $this->account]), [
            // Missing airtable_account_id
        ]);

    $response->assertSessionHasErrors(['airtable_account_id']);
});

it('can view airtable summary', function () {
    Http::fake([
        'api.airtable.com/v0/*/accounts*' => Http::response([
            'records' => [
                ['id' => 'rec1', 'fields' => ['name' => 'Account 1']],
                ['id' => 'rec2', 'fields' => ['name' => 'Account 2']]
            ]
        ], 200),
        'api.airtable.com/v0/*/transactions*' => Http::response([
            'records' => [
                ['id' => 'txn1', 'fields' => ['description' => 'Transaction 1']],
                ['id' => 'txn2', 'fields' => ['description' => 'Transaction 2']]
            ]
        ], 200)
    ]);

    $response = $this->actingAs($this->user)
        ->get(route('airtable.summary', $this->budget));

    $response->assertOk();
    $response->assertInertia(fn ($page) => 
        $page->component('Airtable/Summary')
            ->has('summary.accounts_count')
            ->has('summary.transactions_count')
            ->where('isConfigured', true)
    );
});

it('shows analysis page', function () {
    Http::fake([
        'api.airtable.com/v0/*' => Http::response([
            'records' => []
        ], 200)
    ]);

    $response = $this->actingAs($this->user)
        ->get(route('airtable.analyze'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => 
        $page->component('Airtable/Analyze')
            ->where('isConfigured', true)
    );
});

it('handles unconfigured airtable service', function () {
    // Clear configuration
    config([
        'services.airtable.api_key' => null,
        'services.airtable.base_id' => null,
    ]);

    $response = $this->actingAs($this->user)
        ->post(route('airtable.sync', [$this->budget, $this->account]), [
            'airtable_account_id' => 'recABC123'
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error', 'Airtable integration is not configured.');
});

it('can detect existing airtable transactions', function () {
    // Create existing Airtable transaction
    Transaction::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'airtable_transaction_id' => 'recTXN123',
        'is_airtable_imported' => true
    ]);

    Http::fake([
        'api.airtable.com/v0/*' => Http::response([
            'records' => []
        ], 200)
    ]);

    $response = $this->actingAs($this->user)
        ->get(route('airtable.link', [$this->budget, $this->account]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => 
        $page->where('hasAirtableTransactions', true)
    );
});

it('prevents unauthorized access to other users budgets', function () {
    $otherUser = User::factory()->create();
    $otherBudget = Budget::factory()->create(['user_id' => $otherUser->id]);
    $otherAccount = Account::factory()->create(['budget_id' => $otherBudget->id]);

    $response = $this->actingAs($this->user)
        ->post(route('airtable.sync', [$otherBudget, $otherAccount]), [
            'airtable_account_id' => 'recABC123'
        ]);

    $response->assertForbidden();
});
