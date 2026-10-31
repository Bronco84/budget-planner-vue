<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use App\Services\TransferService;

/*
 * TRANSFER-001 / TRANSFER-002: transfers are created from the quick-add modal and
 * edited on Transfers/Edit; every other transfer URL and every post-action redirect
 * must land on an existing page.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->checking = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
    $this->savings = Account::factory()->savings()->create(['budget_id' => $this->budget->id]);

    $this->transfer = app(TransferService::class)->create([
        'budget_id' => $this->budget->id,
        'from_account_id' => $this->checking->id,
        'to_account_id' => $this->savings->id,
        'amount_in_cents' => 5000,
        'date' => '2026-06-01',
        'description' => 'Move to savings',
    ]);
});

test('the transfers index url redirects to the budget dashboard', function () {
    $this->actingAs($this->user)
        ->get(route('budget.transfers.index', $this->budget))
        ->assertRedirect(route('budgets.show', $this->budget));
});

test('the standalone transfer create url redirects to the budget dashboard', function () {
    $this->actingAs($this->user)
        ->get(route('budget.transfers.create', $this->budget))
        ->assertRedirect(route('budgets.show', $this->budget));
});

test('the transfer show url redirects to its edit page', function () {
    $this->actingAs($this->user)
        ->get(route('budget.transfers.show', [$this->budget, $this->transfer]))
        ->assertRedirect(route('budget.transfers.edit', [$this->budget, $this->transfer]));
});

test('updating a transfer resyncs both legs and redirects to the budget dashboard', function () {
    $this->actingAs($this->user)
        ->patch(route('budget.transfers.update', [$this->budget, $this->transfer]), [
            'from_account_id' => $this->checking->id,
            'to_account_id' => $this->savings->id,
            'amount' => 75.25,
            'date' => '2026-06-02',
            'description' => 'Bigger move',
        ])
        ->assertRedirect(route('budgets.show', $this->budget));

    $amounts = Transaction::where('transfer_id', $this->transfer->id)->pluck('amount_in_cents')->sort()->values()->all();

    expect($this->transfer->fresh()->amount_in_cents)->toBe(7525)
        ->and($amounts)->toBe([-7525, 7525]);
});

test('deleting a transfer redirects to the budget dashboard', function () {
    $this->actingAs($this->user)
        ->delete(route('budget.transfers.destroy', [$this->budget, $this->transfer]))
        ->assertRedirect(route('budgets.show', $this->budget));

    expect(Transfer::find($this->transfer->id))->toBeNull()
        ->and(Transaction::where('transfer_id', $this->transfer->id)->count())->toBe(0);
});

test('another users transfer urls are forbidden', function () {
    $other = User::factory()->create();

    $this->actingAs($other)
        ->get(route('budget.transfers.index', $this->budget))
        ->assertForbidden();

    $this->actingAs($other)
        ->get(route('budget.transfers.show', [$this->budget, $this->transfer]))
        ->assertForbidden();
});
