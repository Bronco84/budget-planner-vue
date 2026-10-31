<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\RecurringTransactionTemplate;
use App\Models\Transaction;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * TXN-003: owner edits, reviews the history of, or deletes a transaction.
 * Cross-user 403s for edit/update/delete live in SecurityAuthorizationTest.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->account = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
    $this->savings = Account::factory()->savings()->create(['budget_id' => $this->budget->id]);

    $this->transaction = Transaction::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'Electric bill',
        'amount_in_cents' => -9000,
        'date' => '2026-06-01',
        'category' => 'Utilities',
        'notes' => null,
    ]);
});

function validTransactionUpdate(array $overrides = []): array
{
    return array_merge([
        'account_id' => test()->savings->id,
        'description' => 'Electric bill (June)',
        'amount' => -95.75,
        'date' => '2026-06-03',
        'category' => 'Housing',
        'notes' => 'Corrected amount',
    ], $overrides);
}

test('owner can open the edit page for their transaction', function () {
    $this->actingAs($this->user)
        ->get(route('budget.transaction.edit', [$this->budget, $this->transaction]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Transactions/Edit')
            ->where('budget.id', $this->budget->id)
            ->where('transaction.id', $this->transaction->id)
            ->where('transaction.description', 'Electric bill')
            ->has('accounts', 2)
            ->where('recurringTemplate', null)
            ->has('rules', 0));
});

test('edit page includes the linked recurring template', function () {
    $template = RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
    ]);
    $this->transaction->update(['recurring_transaction_template_id' => $template->id]);

    $this->actingAs($this->user)
        ->get(route('budget.transaction.edit', [$this->budget, $this->transaction]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Transactions/Edit')
            ->where('recurringTemplate.id', $template->id)
            ->has('recurringTemplates', 1));
});

test('owner can update their transaction and is redirected to the list', function () {
    $this->actingAs($this->user)
        ->patch(route('budget.transaction.update', [$this->budget, $this->transaction]), validTransactionUpdate())
        ->assertRedirect(route('budget.transaction.index', $this->budget))
        ->assertSessionHasNoErrors();

    $fresh = $this->transaction->fresh();

    expect($fresh->account_id)->toBe($this->savings->id)
        ->and($fresh->description)->toBe('Electric bill (June)')
        ->and($fresh->amount_in_cents)->toBe(-9575)
        ->and($fresh->date->toDateString())->toBe('2026-06-03')
        ->and($fresh->category)->toBe('Housing')
        ->and($fresh->notes)->toBe('Corrected amount');
});

test('updating a transaction rejects invalid input and leaves it unchanged', function (array $overrides, string $field) {
    $this->actingAs($this->user)
        ->patch(
            route('budget.transaction.update', [$this->budget, $this->transaction]),
            validTransactionUpdate($overrides),
        )
        ->assertSessionHasErrors($field);

    $fresh = $this->transaction->fresh();

    expect($fresh->description)->toBe('Electric bill')
        ->and($fresh->amount_in_cents)->toBe(-9000)
        ->and($fresh->account_id)->toBe($this->account->id);
})->with([
    'missing description' => [['description' => ''], 'description'],
    'non numeric amount' => [['amount' => 'abc'], 'amount'],
    'invalid date' => [['date' => '2026-13-45'], 'date'],
    'missing category' => [['category' => ''], 'category'],
    'missing account' => [['account_id' => ''], 'account_id'],
]);

test('updating a transaction rejects an account from another budget', function () {
    $foreignAccount = Account::factory()->create();

    $this->actingAs($this->user)
        ->patch(
            route('budget.transaction.update', [$this->budget, $this->transaction]),
            validTransactionUpdate(['account_id' => $foreignAccount->id]),
        )
        ->assertSessionHasErrors('account_id');

    expect($this->transaction->fresh()->account_id)->toBe($this->account->id);
});

test('owner can delete their transaction and is redirected to the list', function () {
    $this->actingAs($this->user)
        ->delete(route('budget.transaction.destroy', [$this->budget, $this->transaction]))
        ->assertRedirect(route('budget.transaction.index', $this->budget));

    expect(Transaction::find($this->transaction->id))->toBeNull();
});

test('activity log returns the transaction history as json', function () {
    $this->actingAs($this->user)
        ->patch(
            route('budget.transaction.update', [$this->budget, $this->transaction]),
            validTransactionUpdate(['description' => 'Renamed bill']),
        );

    $response = $this->actingAs($this->user)
        ->getJson(route('budget.transaction.activity-log', [$this->budget, $this->transaction]))
        ->assertOk()
        ->assertJsonStructure(['activities' => [['id', 'description', 'event', 'properties', 'causer', 'created_at']]]);

    $events = collect($response->json('activities'))->pluck('event');

    expect($events)->toContain('created')
        ->and($events)->toContain('updated');

    $update = collect($response->json('activities'))->firstWhere('event', 'updated');

    expect($update['causer']['id'])->toBe($this->user->id)
        ->and($update['properties']['attributes']['description'])->toBe('Renamed bill');
});

test('user cannot read the activity log of another users transaction', function () {
    $otherBudget = Budget::factory()->create();
    $otherTransaction = Transaction::factory()->create([
        'budget_id' => $otherBudget->id,
        'account_id' => Account::factory()->create(['budget_id' => $otherBudget->id])->id,
    ]);

    $this->actingAs($this->user)
        ->getJson(route('budget.transaction.activity-log', [$otherBudget, $otherTransaction]))
        ->assertForbidden();
});
