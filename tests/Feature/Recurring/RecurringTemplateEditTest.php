<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\RecurringTransactionRule;
use App\Models\RecurringTransactionTemplate;
use App\Models\Transaction;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * RECUR-002: the template detail page, rule syncing on update, duplicating,
 * deleting, update validation and another user's budget.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->account = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
    $this->template = RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'Electric',
        'category' => 'Utilities',
        'frequency' => 'bimonthly',
        'first_day_of_month' => 1,
        'day_of_month' => 15,
        'start_date' => '2026-01-01',
        'amount_in_cents' => -9000,
        'is_dynamic_amount' => true,
        'min_amount' => -7000,
        'max_amount' => -12000,
    ]);
});

function recurEditRule(RecurringTransactionTemplate $template, array $attributes = []): RecurringTransactionRule
{
    return RecurringTransactionRule::create(array_merge([
        'recurring_transaction_template_id' => $template->id,
        'field' => 'description',
        'operator' => 'contains',
        'value' => 'electric',
        'is_case_sensitive' => false,
        'priority' => 1,
        'is_active' => true,
    ], $attributes));
}

function recurEditPayload(Account $account, array $overrides = []): array
{
    return array_merge([
        'description' => 'Electric',
        'amount' => -90,
        'account_id' => $account->id,
        'category' => 'Utilities',
        'frequency' => 'monthly',
        'start_date' => '2026-01-01',
        'day_of_month' => 15,
        'is_dynamic_amount' => false,
    ], $overrides);
}

function recurEditParams(Budget $budget, RecurringTransactionTemplate $template): array
{
    return ['budget' => $budget, 'recurring_transaction' => $template];
}

test('the detail page shows the template with its rules and linked transactions', function () {
    recurEditRule($this->template, ['value' => 'second', 'priority' => 2]);
    recurEditRule($this->template, ['value' => 'first', 'priority' => 1]);
    $linked = Transaction::factory()->create([
        'budget_id' => $this->budget->id, 'account_id' => $this->account->id,
        'recurring_transaction_template_id' => $this->template->id,
    ]);
    Transaction::factory()->create(['budget_id' => $this->budget->id, 'account_id' => $this->account->id]);

    $this->actingAs($this->user)
        ->get(route('recurring-transactions.edit', recurEditParams($this->budget, $this->template)))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('RecurringTransactions/Show')
            ->where('recurringTransaction.id', $this->template->id)
            ->where('recurringTransaction.account.id', $this->account->id)
            ->has('rules', 2)
            ->where('rules.0.value', 'first')
            ->where('rules.1.value', 'second')
            ->has('linkedTransactions', 1)
            ->where('linkedTransactions.0.id', $linked->id)
            ->has('fieldOptions')
            ->has('operatorOptions'));
});

test('updating with a changed rule list updates kept rules, creates new ones and deletes omitted ones', function () {
    $kept = recurEditRule($this->template, ['value' => 'electric', 'priority' => 1]);
    $removed = recurEditRule($this->template, ['value' => 'power co', 'priority' => 2]);

    $this->actingAs($this->user)
        ->patch(route('recurring-transactions.update', recurEditParams($this->budget, $this->template)), recurEditPayload($this->account, [
            'rules' => [
                ['field' => 'description', 'operator' => 'starts_with', 'value' => 'city'],
                ['id' => $kept->id, 'field' => 'description', 'operator' => 'contains', 'value' => 'electric co'],
            ],
        ]))
        ->assertRedirect(route('recurring-transactions.index', $this->budget))
        ->assertSessionHasNoErrors();

    expect(RecurringTransactionRule::find($removed->id))->toBeNull()
        ->and($this->template->rules()->orderBy('priority')->get(['id', 'operator', 'value', 'priority'])->toArray())
        ->sequence(
            fn ($rule) => $rule->toMatchArray(['operator' => 'starts_with', 'value' => 'city', 'priority' => 1]),
            fn ($rule) => $rule->toMatchArray(['id' => $kept->id, 'value' => 'electric co', 'priority' => 2]),
        );
});

test('duplicating a template copies its schedule, amounts and dynamic flag', function () {
    $this->actingAs($this->user)
        ->post(route('recurring-transactions.duplicate', recurEditParams($this->budget, $this->template)))
        ->assertRedirect(route('recurring-transactions.index', $this->budget))
        ->assertSessionHas('message', 'Recurring transaction duplicated successfully');

    $copy = RecurringTransactionTemplate::where('description', 'Copy of Electric')->sole();
    $columns = ['budget_id', 'account_id', 'category', 'frequency', 'first_day_of_month', 'day_of_month',
        'start_date', 'end_date', 'amount_in_cents', 'is_dynamic_amount', 'min_amount', 'max_amount'];

    expect($copy->id)->not->toBe($this->template->id)
        ->and($copy->only($columns))->toEqual($this->template->fresh()->only($columns))
        ->and($copy->is_dynamic_amount)->toBeTrue()
        ->and(RecurringTransactionTemplate::count())->toBe(2);
});

test('deleting a template removes it and returns to the index', function () {
    $this->actingAs($this->user)
        ->delete(route('recurring-transactions.destroy', recurEditParams($this->budget, $this->template)))
        ->assertRedirect(route('recurring-transactions.index', $this->budget))
        ->assertSessionHas('message', 'Recurring transaction deleted successfully');

    expect(RecurringTransactionTemplate::find($this->template->id))->toBeNull();
});

test('updating with an invalid schedule or an end date before the start date is rejected', function (array $overrides, string $field) {
    $this->actingAs($this->user)
        ->patch(route('recurring-transactions.update', recurEditParams($this->budget, $this->template)), recurEditPayload($this->account, $overrides))
        ->assertSessionHasErrors($field);

    expect($this->template->fresh())
        ->frequency->toBe('bimonthly')
        ->amount_in_cents->toBe(-9000);
})->with([
    'weekly without day_of_week' => [['frequency' => 'weekly', 'day_of_month' => null], 'day_of_week'],
    'monthly without day_of_month' => [['day_of_month' => null], 'day_of_month'],
    'day_of_month out of range' => [['day_of_month' => 32], 'day_of_month'],
    'unknown frequency' => [['frequency' => 'fortnightly'], 'frequency'],
    'bimonthly with identical days' => [['frequency' => 'bimonthly', 'first_day_of_month' => 15], 'first_day_of_month'],
    'end date before start date' => [['end_date' => '2025-12-31'], 'end_date'],
]);

test('another user cannot update, duplicate or delete a template in a budget they do not own', function (string $method, string $routeName) {
    $this->actingAs(User::factory()->create())
        ->{$method}(route($routeName, recurEditParams($this->budget, $this->template)), recurEditPayload($this->account, ['description' => 'Hijacked']))
        ->assertForbidden();

    expect(RecurringTransactionTemplate::count())->toBe(1)
        ->and($this->template->fresh())
        ->description->toBe('Electric')
        ->frequency->toBe('bimonthly');
})->with([
    'update' => ['patch', 'recurring-transactions.update'],
    'duplicate' => ['post', 'recurring-transactions.duplicate'],
    'destroy' => ['delete', 'recurring-transactions.destroy'],
]);
