<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use App\Services\TransferService;

/*
 * PR-094: a transfer leg cannot be edited from Nova. TransferService keeps both
 * legs and the transfer in step, and the app sends leg edits to the transfer.
 */

function novaTransferLegAdmin(): User
{
    $admin = User::factory()->create();
    // is_admin is deliberately not mass-assignable.
    $admin->is_admin = true;
    $admin->save();

    return $admin;
}

function novaTransferLegPayload(Transaction $transaction, array $overrides = []): array
{
    return array_merge([
        'budget' => $transaction->budget_id,
        'account' => $transaction->account_id,
        'description' => $transaction->description,
        'category' => $transaction->category,
        'amount_in_cents' => $transaction->amount_in_cents,
        'date' => $transaction->date->toDateString(),
        'import_source' => $transaction->import_source ?? 'manual',
        'is_plaid_imported' => 0,
        'is_reconciled' => 0,
        'notes' => '',
        'plaidTransaction' => '',
        'recurringTemplate' => '',
    ], $overrides);
}

test('nova refuses to edit one leg of a transfer and leaves the transfer in step', function () {
    $admin = novaTransferLegAdmin();
    $budget = Budget::factory()->create(['user_id' => $admin->id]);
    $checking = Account::factory()->checking()->create(['budget_id' => $budget->id]);
    $savings = Account::factory()->savings()->create(['budget_id' => $budget->id]);
    $transfer = app(TransferService::class)->create([
        'budget_id' => $budget->id,
        'from_account_id' => $checking->id,
        'to_account_id' => $savings->id,
        'amount_in_cents' => 5000,
        'date' => now()->toDateString(),
        'description' => 'Move to savings',
    ]);
    $leg = Transaction::where('transfer_id', $transfer->id)->where('account_id', $checking->id)->sole();
    $before = Transaction::where('transfer_id', $transfer->id)->orderBy('id')->get(['id', 'account_id', 'amount_in_cents', 'date'])->toArray();

    $this->actingAs($admin)
        ->putJson('/nova-api/transactions/'.$leg->id, novaTransferLegPayload($leg, ['amount_in_cents' => -9900]))
        ->assertForbidden();

    $this->actingAs($admin)
        ->getJson('/nova-api/transactions/'.$leg->id)
        ->assertOk()
        ->assertJsonPath('resource.authorizedToUpdate', false)
        ->assertJsonPath('resource.authorizedToReplicate', false);

    expect(Transaction::where('transfer_id', $transfer->id)->orderBy('id')->get(['id', 'account_id', 'amount_in_cents', 'date'])->toArray())->toBe($before)
        ->and(Transfer::find($transfer->id)->amount_in_cents)->toBe(5000);
});

test('nova still edits a transaction that is not part of a transfer', function () {
    $admin = novaTransferLegAdmin();
    $budget = Budget::factory()->create(['user_id' => $admin->id]);
    $account = Account::factory()->checking()->create(['budget_id' => $budget->id]);
    $transaction = Transaction::factory()->create([
        'budget_id' => $budget->id,
        'account_id' => $account->id,
        'amount_in_cents' => -1200,
    ]);

    $this->actingAs($admin)
        ->getJson('/nova-api/transactions/'.$transaction->id)
        ->assertOk()
        ->assertJsonPath('resource.authorizedToUpdate', true);

    $this->actingAs($admin)
        ->putJson('/nova-api/transactions/'.$transaction->id, novaTransferLegPayload($transaction, ['amount_in_cents' => -3400]))
        ->assertOk();

    expect($transaction->fresh()->amount_in_cents)->toBe(-3400);
});
