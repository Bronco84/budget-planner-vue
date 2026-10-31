<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\RecurringTransactionTemplate;
use App\Models\Transaction;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * RECUR-001: creating a recurring template (fixed amount, inline rules, from an
 * existing transaction), the active-budget redirect, the empty list, schedule and
 * amount validation, and another user's budget.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->account = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
});

function recurCreatePayload(Account $account, array $overrides = []): array
{
    return array_merge([
        'description' => 'Rent',
        'amount' => -1500,
        'account_id' => $account->id,
        'category' => 'Housing',
        'frequency' => 'monthly',
        'start_date' => '2026-01-01',
        'day_of_month' => 1,
        'is_dynamic_amount' => false,
    ], $overrides);
}

test('an owner creates a fixed amount monthly template and returns to the index', function () {
    $this->actingAs($this->user)
        ->post(route('recurring-transactions.store', $this->budget), recurCreatePayload($this->account))
        ->assertRedirect(route('recurring-transactions.index', $this->budget))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('message', 'Recurring transaction created successfully');

    expect(RecurringTransactionTemplate::sole())
        ->budget_id->toBe($this->budget->id)
        ->account_id->toBe($this->account->id)
        ->description->toBe('Rent')
        ->category->toBe('Housing')
        ->frequency->toBe('monthly')
        ->day_of_month->toBe(1)
        ->amount_in_cents->toBe(-150000)
        ->is_dynamic_amount->toBeFalse();
});

test('inline rules are saved in order and existing matching transactions are linked', function () {
    $matching = Transaction::factory()->create([
        'budget_id' => $this->budget->id, 'account_id' => $this->account->id,
        'description' => 'SPOTIFY USA 1234', 'amount_in_cents' => -1199,
    ]);
    $other = Transaction::factory()->create([
        'budget_id' => $this->budget->id, 'account_id' => $this->account->id,
        'description' => 'Grocery Store', 'amount_in_cents' => -1199,
    ]);

    $this->actingAs($this->user)
        ->post(route('recurring-transactions.store', $this->budget), recurCreatePayload($this->account, [
            'description' => 'Spotify',
            'amount' => -11.99,
            'category' => 'Subscriptions',
            'rules' => [
                ['field' => 'description', 'operator' => 'contains', 'value' => 'spotify'],
                ['field' => 'amount', 'operator' => 'equals', 'value' => '11.99'],
            ],
        ]))
        ->assertRedirect(route('recurring-transactions.index', $this->budget))
        ->assertSessionHasNoErrors();

    $template = RecurringTransactionTemplate::sole();
    expect($template->rules()->orderBy('priority')->get(['field', 'operator', 'value', 'priority'])->toArray())->toBe([
        ['field' => 'description', 'operator' => 'contains', 'value' => 'spotify', 'priority' => 1],
        ['field' => 'amount', 'operator' => 'equals', 'value' => '11.99', 'priority' => 2],
    ])
        ->and($matching->fresh()->recurring_transaction_template_id)->toBe($template->id)
        ->and($other->fresh()->recurring_transaction_template_id)->toBeNull();
});

test('making a transaction recurring prefills the form and links that transaction after save', function () {
    $source = Transaction::factory()->create([
        'budget_id' => $this->budget->id, 'account_id' => $this->account->id,
        'description' => 'City Water', 'amount_in_cents' => -6400, 'category' => 'Utilities',
    ]);

    $this->actingAs($this->user)
        ->get(route('recurring-transactions.create', ['budget' => $this->budget, 'from_transaction' => $source->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('RecurringTransactions/Create')
            ->where('sourceTransaction.id', $source->id)
            ->where('sourceTransaction.description', 'City Water')
            ->where('sourceTransaction.amount_in_cents', -6400));

    $this->actingAs($this->user)
        ->post(route('recurring-transactions.store', $this->budget), recurCreatePayload($this->account, [
            'description' => 'City Water',
            'amount' => -64,
            'category' => 'Utilities',
            'from_transaction_id' => $source->id,
        ]))
        ->assertRedirect(route('recurring-transactions.index', $this->budget));

    expect($source->fresh()->recurring_transaction_template_id)->toBe(RecurringTransactionTemplate::sole()->id);
});

test('the recurring shortcut sends a user without a budget to create one', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('recurring-transactions.redirect'))
        ->assertRedirect(route('budgets.create'));
});

test('the recurring shortcut opens the active budgets recurring list', function () {
    $this->actingAs($this->user)
        ->get(route('recurring-transactions.redirect'))
        ->assertRedirect(route('recurring-transactions.index', $this->budget->id));
});

test('a budget with no templates renders an empty recurring list', function () {
    $this->actingAs($this->user)
        ->get(route('recurring-transactions.index', $this->budget))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('RecurringTransactions/Index')
            ->where('budget.id', $this->budget->id)
            ->has('recurringTransactions', 0));
});

test('a schedule missing its day for the chosen frequency is rejected', function (array $overrides, string $field) {
    $this->actingAs($this->user)
        ->post(route('recurring-transactions.store', $this->budget), recurCreatePayload($this->account, $overrides))
        ->assertSessionHasErrors($field);

    expect(RecurringTransactionTemplate::count())->toBe(0);
})->with([
    'weekly without day_of_week' => [['frequency' => 'weekly', 'day_of_month' => null], 'day_of_week'],
    'biweekly without day_of_week' => [['frequency' => 'biweekly', 'day_of_month' => null], 'day_of_week'],
    'monthly without day_of_month' => [['frequency' => 'monthly', 'day_of_month' => null], 'day_of_month'],
    'quarterly without day_of_month' => [['frequency' => 'quarterly', 'day_of_month' => null], 'day_of_month'],
    'bimonthly without first_day_of_month' => [['frequency' => 'bimonthly', 'day_of_month' => 15], 'first_day_of_month'],
    'bimonthly with identical days' => [['frequency' => 'bimonthly', 'day_of_month' => 15, 'first_day_of_month' => 15], 'first_day_of_month'],
]);

test('a fixed amount template requires an amount', function () {
    $this->actingAs($this->user)
        ->post(route('recurring-transactions.store', $this->budget), recurCreatePayload($this->account, ['amount' => null]))
        ->assertSessionHasErrors('amount');

    expect(RecurringTransactionTemplate::count())->toBe(0);
});

test('a dynamic amount template without an amount is saved with zero cents', function () {
    $this->actingAs($this->user)
        ->post(route('recurring-transactions.store', $this->budget), recurCreatePayload($this->account, [
            'amount' => null,
            'is_dynamic_amount' => true,
        ]))
        ->assertRedirect(route('recurring-transactions.index', $this->budget))
        ->assertSessionHasNoErrors();

    expect(RecurringTransactionTemplate::sole())
        ->amount_in_cents->toBe(0)
        ->is_dynamic_amount->toBeTrue();
});

test('another user cannot list, open the create form for or store templates in a budget', function () {
    $intruder = User::factory()->create();

    $this->actingAs($intruder)
        ->get(route('recurring-transactions.index', $this->budget))
        ->assertForbidden();

    $this->actingAs($intruder)
        ->get(route('recurring-transactions.create', $this->budget))
        ->assertForbidden();

    $this->actingAs($intruder)
        ->post(route('recurring-transactions.store', $this->budget), recurCreatePayload($this->account))
        ->assertForbidden();

    expect(RecurringTransactionTemplate::count())->toBe(0);
});
