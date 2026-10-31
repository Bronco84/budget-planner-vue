<?php

use App\Models\Budget;
use App\Models\User;

/*
 * TXN-001: browsing a budget's transactions.
 */

test('a budget with no transactions renders an empty list', function () {
    $user = User::factory()->create();
    $budget = Budget::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->get(route('budget.transaction.index', $budget))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Transactions/Index')
            ->has('transactions.data', 0)
            ->where('transactions.total', 0)
            ->has('categories', 0)
            ->where('filters.search', '')
        );
});
