<?php

use App\Models\User;
use App\Models\Budget;
use App\Models\Account;
use App\Models\Transaction;

beforeEach(function () {
    // Create two users
    $this->user1 = User::factory()->create();
    $this->user2 = User::factory()->create();
    
    // Create budgets for each user
    $this->budget1 = Budget::factory()->create(['user_id' => $this->user1->id]);
    $this->budget2 = Budget::factory()->create(['user_id' => $this->user2->id]);
    
    // Create accounts for each budget
    $this->account1 = Account::factory()->create(['budget_id' => $this->budget1->id]);
    $this->account2 = Account::factory()->create(['budget_id' => $this->budget2->id]);
    
    // Create transactions for each budget
    $this->transaction1 = Transaction::factory()->create([
        'budget_id' => $this->budget1->id,
        'account_id' => $this->account1->id,
    ]);
    $this->transaction2 = Transaction::factory()->create([
        'budget_id' => $this->budget2->id,
        'account_id' => $this->account2->id,
    ]);
});

test('user cannot access another users budget', function () {
    $this->actingAs($this->user1)
        ->get(route('budgets.show', $this->budget2))
        ->assertForbidden();
});

test('user can access their own budget', function () {
    $this->actingAs($this->user1)
        ->get(route('budgets.show', $this->budget1))
        ->assertOk();
});

test('user cannot edit another users account', function () {
    $this->actingAs($this->user1)
        ->get(route('budgets.accounts.edit', [$this->budget2, $this->account2]))
        ->assertForbidden();
});

test('user can edit their own account', function () {
    $this->actingAs($this->user1)
        ->get(route('budgets.accounts.edit', [$this->budget1, $this->account1]))
        ->assertOk();
});

test('user cannot update another users account', function () {
    $this->actingAs($this->user1)
        ->patch(route('budgets.accounts.update', [$this->budget2, $this->account2]), [
            'name' => 'Hacked Account',
            'type' => 'checking',
        ])
        ->assertForbidden();
});

test('user cannot delete another users account', function () {
    $this->actingAs($this->user1)
        ->delete(route('budgets.accounts.destroy', [$this->budget2, $this->account2]))
        ->assertForbidden();
    
    // Verify account still exists
    expect(Account::find($this->account2->id))->not->toBeNull();
});

test('user cannot access another users transactions', function () {
    $this->actingAs($this->user1)
        ->get(route('budget.transaction.index', $this->budget2))
        ->assertForbidden();
});

test('user cannot edit another users transaction', function () {
    $this->actingAs($this->user1)
        ->get(route('budget.transaction.edit', [$this->budget2, $this->transaction2]))
        ->assertForbidden();
});

test('user cannot update another users transaction', function () {
    $this->actingAs($this->user1)
        ->patch(route('budget.transaction.update', [$this->budget2, $this->transaction2]), [
            'account_id' => $this->account2->id,
            'description' => 'Hacked Transaction',
            'amount' => 1000,
            'date' => now()->toDateString(),
            'category' => 'Hacking',
        ])
        ->assertForbidden();
});

test('user cannot delete another users transaction', function () {
    $this->actingAs($this->user1)
        ->delete(route('budget.transaction.destroy', [$this->budget2, $this->transaction2]))
        ->assertForbidden();
    
    // Verify transaction still exists
    expect(Transaction::find($this->transaction2->id))->not->toBeNull();
});

test('account must belong to budget in route', function () {
    // Try to access account2 through budget1's route
    $this->actingAs($this->user1)
        ->get(route('budgets.accounts.edit', [$this->budget1, $this->account2]))
        ->assertNotFound();
});

test('transaction must belong to budget in route', function () {
    // Try to access transaction2 through budget1's route
    $this->actingAs($this->user1)
        ->get(route('budget.transaction.edit', [$this->budget1, $this->transaction2]))
        ->assertNotFound();
});

test('unauthenticated user cannot access budgets', function () {
    $this->get(route('budgets.show', $this->budget1))
        ->assertRedirect(route('login'));
});

test('unauthenticated user cannot access accounts', function () {
    $this->get(route('budgets.accounts.edit', [$this->budget1, $this->account1]))
        ->assertRedirect(route('login'));
});

test('unauthenticated user cannot access transactions', function () {
    $this->get(route('budget.transaction.index', $this->budget1))
        ->assertRedirect(route('login'));
});

