<?php

use App\Models\Budget;
use App\Models\PlaidConnection;
use App\Models\User;
use Tests\Support\FakePlaidApi;

/*
 * PLAID-009: refreshing credit card liabilities for one connection.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->plaid = FakePlaidApi::install();
});

test('refreshing another users connection is forbidden and calls no Plaid endpoint', function () {
    $otherBudget = Budget::factory()->create();
    $connection = PlaidConnection::create([
        'budget_id' => $otherBudget->id,
        'plaid_item_id' => 'item-other',
        'institution_name' => 'Other Bank',
        'access_token' => 'access-sandbox-other',
        'status' => PlaidConnection::STATUS_ACTIVE,
    ]);

    $this->actingAs($this->user)
        ->post(route('admin.plaid-liabilities.update-connection', $connection))
        ->assertForbidden();

    expect($this->plaid->requests)->toBe([]);
});
