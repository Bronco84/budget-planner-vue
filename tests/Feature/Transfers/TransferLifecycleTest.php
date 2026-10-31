<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use App\Services\TransferService;

/*
 * TRANSFER-001 / TRANSFER-002: creating transfers over HTTP, the edit page,
 * cross-user protection and leg recovery.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->checking = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
    $this->savings = Account::factory()->savings()->create(['budget_id' => $this->budget->id]);
    $this->payload = [
        'from_account_id' => $this->checking->id,
        'to_account_id' => $this->savings->id,
        'amount' => 125.5,
        'date' => '2026-07-01',
        'description' => 'Rainy day',
    ];
});

function transferLifecycleCreate(Budget $budget, Account $from, Account $to): Transfer
{
    return app(TransferService::class)->create([
        'budget_id' => $budget->id,
        'from_account_id' => $from->id,
        'to_account_id' => $to->id,
        'amount_in_cents' => 5000,
        'date' => '2026-06-01',
        'description' => 'Move to savings',
    ]);
}

test('posting a valid transfer from the modal creates the transfer and its legs and redirects back', function () {
    $this->actingAs($this->user)
        ->from(route('budgets.show', $this->budget))
        ->post(route('budget.transfers.store', $this->budget), $this->payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('budgets.show', $this->budget))
        ->assertSessionHas('message', 'Transfer created successfully');

    $transfer = Transfer::where('budget_id', $this->budget->id)->sole();
    $legs = Transaction::where('transfer_id', $transfer->id)->get();

    expect($transfer->amount_in_cents)->toBe(12550)
        ->and($transfer->from_account_id)->toBe($this->checking->id)
        ->and($legs->firstWhere('account_id', $this->checking->id)->amount_in_cents)->toBe(-12550)
        ->and($legs->firstWhere('account_id', $this->savings->id)->amount_in_cents)->toBe(12550);
});

test('a json transfer request receives the message and the transfer', function () {
    $response = $this->actingAs($this->user)
        ->postJson(route('budget.transfers.store', $this->budget), $this->payload)
        ->assertOk()
        ->assertJsonPath('message', 'Transfer created successfully')
        ->assertJsonPath('transfer.amount_in_cents', 12550);

    expect(Transfer::find($response->json('transfer.id')))->not->toBeNull();
});

test('posting a transfer to another users budget is forbidden and nothing is created', function () {
    $other = User::factory()->create();
    $foreignBudget = Budget::factory()->create(['user_id' => $other->id]);
    $from = Account::factory()->checking()->create(['budget_id' => $foreignBudget->id]);
    $to = Account::factory()->savings()->create(['budget_id' => $foreignBudget->id]);

    $this->actingAs($this->user)
        ->post(route('budget.transfers.store', $foreignBudget), array_replace($this->payload, [
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
        ]))
        ->assertForbidden();

    expect(Transfer::count())->toBe(0)
        ->and(Transaction::count())->toBe(0);
});

test('the owner can open the transfer edit page', function () {
    $transfer = transferLifecycleCreate($this->budget, $this->checking, $this->savings);

    $this->actingAs($this->user)
        ->get(route('budget.transfers.edit', [$this->budget, $transfer]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Transfers/Edit')
            ->where('budget.id', $this->budget->id)
            ->where('transfer.id', $transfer->id)
            ->where('transfer.from_account.id', $this->checking->id)
            ->where('transfer.to_account.id', $this->savings->id)
            ->has('accounts', 2)
        );
});

test('edit update and delete of another users transfer are forbidden and nothing changes', function () {
    $transfer = transferLifecycleCreate($this->budget, $this->checking, $this->savings);
    $intruder = User::factory()->create();

    $this->actingAs($intruder)
        ->get(route('budget.transfers.edit', [$this->budget, $transfer]))
        ->assertForbidden();

    $this->actingAs($intruder)
        ->patch(route('budget.transfers.update', [$this->budget, $transfer]), array_replace($this->payload, ['amount' => 1]))
        ->assertForbidden();

    $this->actingAs($intruder)
        ->delete(route('budget.transfers.destroy', [$this->budget, $transfer]))
        ->assertForbidden();

    expect($transfer->fresh()->amount_in_cents)->toBe(5000)
        ->and(Transaction::where('transfer_id', $transfer->id)->count())->toBe(2);
});

test('updating a transfer recreates a leg that was deleted separately', function () {
    $transfer = transferLifecycleCreate($this->budget, $this->checking, $this->savings);
    Transaction::where('transfer_id', $transfer->id)->where('amount_in_cents', '>', 0)->delete();

    $this->actingAs($this->user)
        ->patch(route('budget.transfers.update', [$this->budget, $transfer]), $this->payload)
        ->assertRedirect(route('budgets.show', $this->budget));

    $legs = Transaction::where('transfer_id', $transfer->id)->get();
    $incoming = $legs->firstWhere('amount_in_cents', '>', 0);

    expect($legs)->toHaveCount(2)
        ->and($incoming->account_id)->toBe($this->savings->id)
        ->and($incoming->amount_in_cents)->toBe(12550)
        ->and($incoming->category)->toBe('Transfer')
        ->and($legs->firstWhere('amount_in_cents', '<', 0)->amount_in_cents)->toBe(-12550);
});
