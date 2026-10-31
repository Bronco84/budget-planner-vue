<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use App\Services\TransferService;

/*
 * TRANSFER-001 / TRANSFER-002: invalid transfers (same account, sub-cent amount,
 * accounts outside the budget) are rejected and nothing is written.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->checking = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
    $this->savings = Account::factory()->savings()->create(['budget_id' => $this->budget->id]);
});

test('storing a transfer with identical accounts or an amount below one cent is rejected', function (string $case, string $field) {
    $payload = [
        'from_account_id' => $this->checking->id,
        'to_account_id' => $case === 'same_account' ? $this->checking->id : $this->savings->id,
        'amount' => $case === 'same_account' ? 25 : ($case === 'zero' ? 0 : 0.001),
        'date' => '2026-06-01',
    ];

    $this->actingAs($this->user)
        ->from(route('budgets.show', $this->budget))
        ->post(route('budget.transfers.store', $this->budget), $payload)
        ->assertRedirect(route('budgets.show', $this->budget))
        ->assertSessionHasErrors($field);

    expect(Transfer::count())->toBe(0)
        ->and(Transaction::count())->toBe(0);
})->with([
    'same account' => ['same_account', 'from_account_id'],
    'zero amount' => ['zero', 'amount'],
    'sub-cent amount' => ['sub_cent', 'amount'],
]);

test('storing a transfer with accounts from another budget is rejected', function (string $field) {
    $otherBudget = Budget::factory()->create();
    $foreign = Account::factory()->checking()->create(['budget_id' => $otherBudget->id]);

    $payload = [
        'from_account_id' => $field === 'from_account_id' ? $foreign->id : $this->checking->id,
        'to_account_id' => $field === 'to_account_id' ? $foreign->id : $this->savings->id,
        'amount' => 25,
        'date' => '2026-06-01',
    ];

    $this->actingAs($this->user)
        ->post(route('budget.transfers.store', $this->budget), $payload)
        ->assertSessionHasErrors($field);

    expect(Transfer::count())->toBe(0)
        ->and(Transaction::count())->toBe(0)
        ->and($foreign->fresh()->current_balance_cents)->toBe($foreign->current_balance_cents);
})->with(['from_account_id', 'to_account_id']);

test('updating a transfer with identical accounts or an amount below one cent is rejected', function (string $case, string $field) {
    $transfer = app(TransferService::class)->create([
        'budget_id' => $this->budget->id,
        'from_account_id' => $this->checking->id,
        'to_account_id' => $this->savings->id,
        'amount_in_cents' => 5000,
        'date' => '2026-06-01',
        'description' => 'Move to savings',
    ]);
    $legsBefore = Transaction::orderBy('id')->get(['id', 'account_id', 'amount_in_cents'])->toArray();

    $this->actingAs($this->user)
        ->patch(route('budget.transfers.update', [$this->budget, $transfer]), [
            'from_account_id' => $this->savings->id,
            'to_account_id' => $case === 'same_account' ? $this->savings->id : $this->checking->id,
            'amount' => $case === 'same_account' ? 99 : 0,
            'date' => '2026-07-01',
        ])
        ->assertSessionHasErrors($field);

    $fresh = $transfer->fresh();
    expect($fresh->from_account_id)->toBe($this->checking->id)
        ->and($fresh->to_account_id)->toBe($this->savings->id)
        ->and($fresh->amount_in_cents)->toBe(5000)
        ->and(Transaction::orderBy('id')->get(['id', 'account_id', 'amount_in_cents'])->toArray())->toBe($legsBefore);
})->with([
    'same account' => ['same_account', 'from_account_id'],
    'zero amount' => ['zero', 'amount'],
]);
