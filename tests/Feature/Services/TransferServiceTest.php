<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Services\TransferService;

/*
 * Tests for TransferService: a transfer is the source of truth and must always
 * produce a balanced pair of transactions (money out of the source, money into
 * the destination) and clean them up on delete.
 */

function makeTransfer(array $overrides = []): array
{
    $budget = Budget::factory()->create();
    $from = Account::factory()->checking()->create(['budget_id' => $budget->id]);
    $to = Account::factory()->savings()->create(['budget_id' => $budget->id]);

    $data = array_merge([
        'budget_id' => $budget->id,
        'from_account_id' => $from->id,
        'to_account_id' => $to->id,
        'amount_in_cents' => 5000,
        'date' => '2026-02-01',
        'description' => 'Move to savings',
    ], $overrides);

    return [$budget, $from, $to, $data];
}

test('create produces a balanced pair of transactions', function () {
    [$budget, $from, $to, $data] = makeTransfer();

    $transfer = app(TransferService::class)->create($data);

    expect($transfer->amount_in_cents)->toBe(5000)
        ->and(Transaction::where('transfer_id', $transfer->id)->count())->toBe(2);

    $outgoing = Transaction::where('transfer_id', $transfer->id)
        ->where('account_id', $from->id)->first();
    $incoming = Transaction::where('transfer_id', $transfer->id)
        ->where('account_id', $to->id)->first();

    // Source loses the money, destination gains it; the pair nets to zero.
    expect($outgoing->amount_in_cents)->toBe(-5000)
        ->and($incoming->amount_in_cents)->toBe(5000)
        ->and($outgoing->category)->toBe('Transfer')
        ->and($outgoing->amount_in_cents + $incoming->amount_in_cents)->toBe(0);
});

test('create normalizes a negative amount to a positive transfer', function () {
    [$budget, $from, $to, $data] = makeTransfer(['amount_in_cents' => -7500]);

    $transfer = app(TransferService::class)->create($data);

    expect($transfer->amount_in_cents)->toBe(7500)
        ->and(Transaction::where('transfer_id', $transfer->id)->where('account_id', $from->id)->first()->amount_in_cents)->toBe(-7500)
        ->and(Transaction::where('transfer_id', $transfer->id)->where('account_id', $to->id)->first()->amount_in_cents)->toBe(7500);
});

test('delete removes the transfer and its paired transactions', function () {
    [$budget, $from, $to, $data] = makeTransfer();
    $service = app(TransferService::class);
    $transfer = $service->create($data);
    $transferId = $transfer->id;

    $result = $service->delete($transfer);

    expect($result)->toBeTrue()
        ->and(Transfer::find($transferId))->toBeNull()
        ->and(Transaction::where('transfer_id', $transferId)->count())->toBe(0);
});
