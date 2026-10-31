<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\RecurringTransactionTemplate;
use App\Models\Transaction;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * TXN-002: owner records a manual transaction.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->account = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);

    $this->otherBudget = Budget::factory()->create();
    $this->otherAccount = Account::factory()->create(['budget_id' => $this->otherBudget->id]);
});

test('owner can open the create transaction form with budget accounts and templates', function () {
    RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
    ]);
    RecurringTransactionTemplate::factory()->create(['budget_id' => $this->otherBudget->id]);

    $this->actingAs($this->user)
        ->get(route('budget.transaction.create', $this->budget))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Transactions/Create')
            ->where('budget.id', $this->budget->id)
            ->has('accounts', 1)
            ->where('accounts.0.id', $this->account->id)
            ->has('recurringTemplates', 1)
            ->where('prefillData.description', '')
            ->where('prefillData.amount', ''));
});

test('create transaction form is prefilled from query parameters', function () {
    $template = RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
    ]);

    $this->actingAs($this->user)
        ->get(route('budget.transaction.create', [
            $this->budget,
            'description' => 'Rent',
            'account_id' => $this->account->id,
            'category' => 'Housing',
            'date' => '2026-07-01',
            'amount' => '-1500',
            'recurring_transaction_template_id' => $template->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Transactions/Create')
            ->where('prefillData.description', 'Rent')
            ->where('prefillData.account_id', (string) $this->account->id)
            ->where('prefillData.category', 'Housing')
            ->where('prefillData.date', '2026-07-01')
            ->where('prefillData.amount', '-1500')
            ->where('prefillData.recurring_transaction_template_id', (string) $template->id));
});

test('storing a manual transaction persists it and redirects to its edit page', function () {
    $template = RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
    ]);

    $response = $this->actingAs($this->user)
        ->post(route('budget.transaction.store', $this->budget), [
            'account_id' => $this->account->id,
            'description' => 'Weekly groceries',
            'amount' => -82.25,
            'date' => '2026-06-14',
            'category' => 'Groceries',
            'notes' => 'Farmers market',
            'recurring_transaction_template_id' => $template->id,
        ]);

    $transaction = $this->budget->transactions()->sole();

    $response->assertRedirect(route('budget.transaction.edit', [$this->budget, $transaction]))
        ->assertSessionHasNoErrors();

    expect($transaction->account_id)->toBe($this->account->id)
        ->and($transaction->description)->toBe('Weekly groceries')
        ->and($transaction->amount_in_cents)->toBe(-8225)
        ->and($transaction->date->toDateString())->toBe('2026-06-14')
        ->and($transaction->category)->toBe('Groceries')
        ->and($transaction->notes)->toBe('Farmers market')
        ->and($transaction->recurring_transaction_template_id)->toBe($template->id);
});

test('quick add payload without notes or template stores the transaction', function () {
    // Mirrors the fields QuickAddTransactionModal posts.
    $this->actingAs($this->user)
        ->post(route('budget.transaction.store', $this->budget), [
            'account_id' => $this->account->id,
            'description' => 'Coffee',
            'category' => 'Dining',
            'amount' => '-4.50',
            'date' => '2026-06-15',
        ])
        ->assertSessionHasNoErrors();

    $transaction = $this->budget->transactions()->sole();

    expect($transaction->amount_in_cents)->toBe(-450)
        ->and($transaction->notes)->toBeNull()
        ->and($transaction->recurring_transaction_template_id)->toBeNull();
});

test('storing a transaction rejects invalid input and creates nothing', function (array $overrides, string $field) {
    $payload = array_merge([
        'account_id' => $this->account->id,
        'description' => 'Valid',
        'amount' => 10,
        'date' => '2026-06-15',
        'category' => 'Other',
    ], $overrides);

    $this->actingAs($this->user)
        ->post(route('budget.transaction.store', $this->budget), $payload)
        ->assertSessionHasErrors($field);

    expect(Transaction::count())->toBe(0);
})->with([
    'missing account' => [['account_id' => ''], 'account_id'],
    'missing description' => [['description' => ''], 'description'],
    'description too long' => [['description' => str_repeat('d', 256)], 'description'],
    'missing amount' => [['amount' => ''], 'amount'],
    'non numeric amount' => [['amount' => 'ten'], 'amount'],
    'missing date' => [['date' => ''], 'date'],
    'invalid date' => [['date' => 'not-a-date'], 'date'],
    'unknown category id' => [['category_id' => 999999], 'category_id'],
    'category too long' => [['category' => str_repeat('x', 256)], 'category'],
]);

test('storing a transaction rejects another budgets recurring template', function () {
    $foreignTemplate = RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->otherBudget->id,
        'account_id' => $this->otherAccount->id,
    ]);

    $this->actingAs($this->user)
        ->post(route('budget.transaction.store', $this->budget), [
            'account_id' => $this->account->id,
            'description' => 'Linked',
            'amount' => 10,
            'date' => '2026-06-15',
            'category' => 'Other',
            'recurring_transaction_template_id' => $foreignTemplate->id,
        ])
        ->assertSessionHasErrors('recurring_transaction_template_id');

    expect(Transaction::count())->toBe(0);
});

test('user cannot open the create transaction form for another users budget', function () {
    $this->actingAs($this->user)
        ->get(route('budget.transaction.create', $this->otherBudget))
        ->assertForbidden();
});

test('user cannot store a transaction on another users budget', function () {
    $this->actingAs($this->user)
        ->post(route('budget.transaction.store', $this->otherBudget), [
            'account_id' => $this->otherAccount->id,
            'description' => 'Sneaky',
            'amount' => 10,
            'date' => '2026-06-15',
            'category' => 'Other',
        ])
        ->assertForbidden();

    expect(Transaction::count())->toBe(0);
});

test('storing a transaction converts dollars to the exact cent amount', function (float $amount, int $expectedCents) {
    // Capture the raw value sent to the database: the integer column (and SQLite's
    // 15-digit REAL formatting) would otherwise mask a float like 28.999999999999996.
    Transaction::saving(function (Transaction $transaction) {
        test()->rawCents = $transaction->getAttributes()['amount_in_cents'];
    });

    $this->actingAs($this->user)
        ->post(route('budget.transaction.store', $this->budget), [
            'account_id' => $this->account->id,
            'description' => 'Coffee',
            'amount' => $amount,
            'date' => '2026-06-14',
            'category' => 'Dining',
        ])
        ->assertSessionHasNoErrors();

    expect($this->rawCents)->toBe($expectedCents)
        ->and($this->budget->transactions()->sole()->amount_in_cents)->toBe($expectedCents);
})->with([
    '0.29' => [0.29, 29],
    '19.99' => [19.99, 1999],
    '-19.99' => [-19.99, -1999],
]);

test('create transaction page has a breadcrumb trail', function () {
    $this->actingAs($this->user)
        ->get(route('budget.transaction.create', $this->budget))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('breadcrumbs')
            ->where('breadcrumbs', fn ($crumbs) => collect($crumbs)->last()['title'] === 'Create Transaction'));
});
