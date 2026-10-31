<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\RecurringTransactionRule;
use App\Models\RecurringTransactionTemplate;
use App\Models\User;

/*
 * RECUR-003: unknown fields/operators and empty values fail validation on both
 * store and update, and nothing is written.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $account = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
    $this->template = RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $account->id,
    ]);
});

dataset('invalid rule payloads', [
    'unknown field' => [['field' => 'merchant'], 'field'],
    'unknown operator' => [['operator' => 'sounds_like'], 'operator'],
    'empty value' => [['value' => ''], 'value'],
    'missing operator' => [['operator' => null], 'operator'],
]);

test('storing a rule with an unknown field or operator or an empty value is rejected', function (array $overrides, string $field) {
    $this->actingAs($this->user)
        ->post(route('recurring-transactions.rules.store', [$this->budget, $this->template]), array_merge([
            'field' => 'description',
            'operator' => 'contains',
            'value' => 'NETFLIX',
        ], $overrides))
        ->assertSessionHasErrors($field);

    expect(RecurringTransactionRule::count())->toBe(0);
})->with('invalid rule payloads');

test('updating a rule with an unknown field or operator or an empty value is rejected', function (array $overrides, string $field) {
    $rule = $this->template->rules()->create([
        'field' => 'description',
        'operator' => 'contains',
        'value' => 'NETFLIX',
        'is_case_sensitive' => false,
        'priority' => 1,
        'is_active' => true,
    ]);

    $this->actingAs($this->user)
        ->patch(route('recurring-transactions.rules.update', [$this->budget, $this->template, $rule]), array_merge([
            'field' => 'description',
            'operator' => 'equals',
            'value' => 'SPOTIFY',
        ], $overrides))
        ->assertSessionHasErrors($field);

    $fresh = $rule->fresh();
    expect($fresh->field)->toBe('description')
        ->and($fresh->operator)->toBe('contains')
        ->and($fresh->value)->toBe('NETFLIX');
})->with('invalid rule payloads');
