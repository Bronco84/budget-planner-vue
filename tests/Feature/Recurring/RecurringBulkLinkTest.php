<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\PlaidTransaction;
use App\Models\RecurringTransactionTemplate;
use App\Models\Transaction;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * RECUR-005: reviewing suggested matches (find-matching) and bulk linking the
 * selected transactions (link-transactions).
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->account = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
});

function recurBulkPlaidTransaction(Account $account, string $plaidId, string $name, array $attributes = []): Transaction
{
    PlaidTransaction::create([
        'account_id' => $account->id,
        'plaid_transaction_id' => $plaidId,
        'plaid_account_id' => 'plaid-acct-bulk',
        'amount' => 9.99,
        'date' => '2026-03-01',
        'name' => $name,
    ]);

    return Transaction::factory()->create(array_merge([
        'budget_id' => $account->budget_id,
        'account_id' => $account->id,
        'description' => $name,
        'plaid_transaction_id' => $plaidId,
        'amount_in_cents' => -999,
        'date' => '2026-03-01',
    ], $attributes));
}

test('an owner reviews suggested matches and links a selection', function () {
    $template = RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id, 'account_id' => $this->account->id, 'description' => 'Hulu',
    ]);
    $first = recurBulkPlaidTransaction($this->account, 'ptx-hulu-1', 'Hulu');
    $second = recurBulkPlaidTransaction($this->account, 'ptx-hulu-2', 'HULU', ['date' => '2026-02-01']);
    recurBulkPlaidTransaction($this->account, 'ptx-other', 'Corner Bakery');

    $this->actingAs($this->user)
        ->get(route('recurring-transactions.find-matching', $this->budget))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('RecurringTransactions/FindMatchingTransactions')
            ->where('totalTemplates', 1)
            ->where('totalMatches', 2)
            ->where('templatesWithMatches.0.template.id', $template->id)
            ->has('templatesWithMatches.0.matching_transactions', 2)
            ->where('templatesWithMatches.0.matching_transactions.0.id', $first->id));

    $this->actingAs($this->user)
        ->post(route('recurring-transactions.link-transactions', $this->budget), [
            'selections' => [['template_id' => $template->id, 'transaction_ids' => [$first->id]]],
        ])
        ->assertRedirect(route('recurring-transactions.index', $this->budget->id))
        ->assertSessionHas('success', 'Successfully linked 1 transaction to recurring templates.');

    expect($first->fresh()->recurring_transaction_template_id)->toBe($template->id)
        ->and($second->fresh()->recurring_transaction_template_id)->toBeNull();
});

test('find matching shows the empty state with no templates or no matching transactions', function () {
    $this->actingAs($this->user)
        ->get(route('recurring-transactions.find-matching', $this->budget))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('RecurringTransactions/FindMatchingTransactions')
            ->where('totalTemplates', 0)
            ->where('totalMatches', 0)
            ->has('templatesWithMatches', 0));

    RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id, 'account_id' => $this->account->id, 'description' => 'Hulu',
    ]);
    recurBulkPlaidTransaction($this->account, 'ptx-other', 'Corner Bakery');

    $this->actingAs($this->user)
        ->get(route('recurring-transactions.find-matching', $this->budget))
        ->assertInertia(fn (Assert $page) => $page
            ->where('totalTemplates', 0)
            ->where('totalMatches', 0)
            ->has('templatesWithMatches', 0));
});

test('an already linked transaction is not re-linked and nothing is reported as linked', function () {
    $template = RecurringTransactionTemplate::factory()->create(['budget_id' => $this->budget->id, 'account_id' => $this->account->id]);
    $original = RecurringTransactionTemplate::factory()->create(['budget_id' => $this->budget->id, 'account_id' => $this->account->id]);
    $linked = Transaction::factory()->create([
        'budget_id' => $this->budget->id, 'account_id' => $this->account->id,
        'recurring_transaction_template_id' => $original->id,
    ]);

    $this->actingAs($this->user)
        ->post(route('recurring-transactions.link-transactions', $this->budget), [
            'selections' => [['template_id' => $template->id, 'transaction_ids' => [$linked->id]]],
        ])
        ->assertRedirect(route('recurring-transactions.index', $this->budget->id))
        ->assertSessionMissing('success')
        ->assertSessionHas('info', 'No transactions were linked.');

    expect($linked->fresh()->recurring_transaction_template_id)->toBe($original->id);
});

test('bulk linking requires selections with existing template and transaction ids', function (Closure $payload, array $errors) {
    $template = RecurringTransactionTemplate::factory()->create(['budget_id' => $this->budget->id, 'account_id' => $this->account->id]);
    $transaction = Transaction::factory()->create(['budget_id' => $this->budget->id, 'account_id' => $this->account->id]);

    $this->actingAs($this->user)
        ->post(route('recurring-transactions.link-transactions', $this->budget), $payload($template, $transaction))
        ->assertSessionHasErrors($errors);

    expect($transaction->fresh()->recurring_transaction_template_id)->toBeNull();
})->with([
    'missing selections' => [fn () => [], ['selections']],
    'missing transaction ids' => [fn ($template) => ['selections' => [['template_id' => $template->id]]], ['selections.0.transaction_ids']],
    'unknown template' => [fn ($template, $transaction) => ['selections' => [['template_id' => 999999, 'transaction_ids' => [$transaction->id]]]], ['selections.0.template_id']],
    'unknown transaction' => [fn ($template) => ['selections' => [['template_id' => $template->id, 'transaction_ids' => [999999]]]], ['selections.0.transaction_ids.0']],
]);

test('another user cannot open find matching or bulk link in a budget they do not own', function () {
    $template = RecurringTransactionTemplate::factory()->create(['budget_id' => $this->budget->id, 'account_id' => $this->account->id]);
    $transaction = Transaction::factory()->create(['budget_id' => $this->budget->id, 'account_id' => $this->account->id]);
    $intruder = User::factory()->create();

    $this->actingAs($intruder)
        ->get(route('recurring-transactions.find-matching', $this->budget))
        ->assertForbidden();

    $this->actingAs($intruder)
        ->post(route('recurring-transactions.link-transactions', $this->budget), [
            'selections' => [['template_id' => $template->id, 'transaction_ids' => [$transaction->id]]],
        ])
        ->assertForbidden();

    expect($transaction->fresh()->recurring_transaction_template_id)->toBeNull();
});
