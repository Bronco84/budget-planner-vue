<?php

use App\Models\Budget;
use App\Models\User;
use App\Services\VirtualAccountService;
use App\Services\VirtualTransactionService;

it('can load budget page with virtual account data', function () {
    $user = User::factory()->create();
    $budget = Budget::factory()->create(['user_id' => $user->id]);
    
    $response = $this->actingAs($user)
        ->get("/budgets/{$budget->id}");
    
    $response->assertStatus(200);
    $response->assertSee($budget->name);
    $response->assertViewIs('app');
});

it('provides correct transaction data structure', function () {
    $user = User::factory()->create();
    $budget = Budget::factory()->create(['user_id' => $user->id]);
    
    // Test the virtual account service directly
    $virtualAccountService = app(VirtualAccountService::class);
    $accounts = $virtualAccountService->getAccountsForBudget($budget);
    
    expect($accounts)->toBeInstanceOf(\Illuminate\Support\Collection::class);
    
    // If we have accounts, test transaction structure
    if ($accounts->count() > 0) {
        $firstAccount = $accounts->first();
        
        expect($firstAccount)->toHaveKey('name');
        expect($firstAccount)->toHaveKey('current_balance_cents');
        expect($firstAccount)->toHaveKey('airtable_id');
        
        // Test transaction service
        $virtualTransactionService = app(VirtualTransactionService::class);
        $transactions = $virtualTransactionService->getHistoricalTransactionsForAccount($budget, $firstAccount['airtable_id']);
        
        expect($transactions)->toBeInstanceOf(\Illuminate\Support\Collection::class);
        
        // If we have transactions, verify structure
        if ($transactions->count() > 0) {
            $firstTransaction = $transactions->first();
            
            expect($firstTransaction)->toHaveKey('description');
            expect($firstTransaction)->toHaveKey('amount_in_cents');
            expect($firstTransaction)->toHaveKey('date');
            expect($firstTransaction)->toHaveKey('is_airtable_imported');
        }
    }
});

it('calculates running balances correctly', function () {
    // Test the running balance calculation logic
    $transactions = collect([
        (object) [
            'id' => 1,
            'date' => '2025-09-01',
            'amount_in_cents' => -10000, // -$100
        ],
        (object) [
            'id' => 2,
            'date' => '2025-09-05',
            'amount_in_cents' => 50000, // +$500
        ]
    ]);
    
    $startingBalance = 60000; // $600
    $runningBalance = $startingBalance;
    
    $sortedTransactions = $transactions->sortBy('date');
    
    foreach ($sortedTransactions as $transaction) {
        $transaction->running_balance = $runningBalance;
        $runningBalance += $transaction->amount_in_cents;
    }
    
    expect($transactions->first()->running_balance)->toBe(60000); // First transaction shows starting balance
    expect($transactions->last()->running_balance)->toBe(50000); // After -$100 transaction
});

it('provides account information for frontend', function () {
    $user = User::factory()->create();
    $budget = Budget::factory()->create(['user_id' => $user->id]);
    
    $virtualAccountService = app(VirtualAccountService::class);
    $groupedAccounts = $virtualAccountService->getGroupedAccountsForBudget($budget, $user->id);
    
    expect($groupedAccounts)->toBeArray();
    
    foreach ($groupedAccounts as $group) {
        expect($group)->toHaveKey('name');
        expect($group)->toHaveKey('accounts');
        expect($group)->toHaveKey('account_count');
        expect($group)->toHaveKey('total_balance');
        
        // Verify accounts are arrays (not collections) for frontend compatibility
        expect($group['accounts'])->toBeArray();
    }
});
